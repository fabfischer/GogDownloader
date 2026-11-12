<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\PartBody;
use App\Storage\StoredObject;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use App\Transfer\Exception\NotResumableFromSource;
use App\Transfer\Exception\ResumeImpossible;
use App\Transfer\Exception\RetriesExhausted;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\TransferException;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\Exception\VerificationFailed;
use Closure;
use DateTimeImmutable;
use HashContext;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Moves one file from a TransferSource to an ArchiveStorageAdapter in bounded parts, without ever holding the whole
 * file in memory or on disk:
 *
 * - each part is read into a php://memory stream of at most one part size, never into a temporary file;
 * - SHA-256 and MD5 are updated on copies of the running state and taken over only when the target acknowledged the
 *   part, so retries never hash bytes twice;
 * - a checkpoint is saved after the session is opened and after every acknowledged part, never before;
 * - temporary source failures re-read the part (resumable sources only), temporary storage failures re-send the same
 *   buffer, both with backoff, up to the retry budget; completing the upload is retried the same way;
 * - a changed source, a non-resumable source failing midway, a cancel request or a "no" from the commit gate abort the
 *   upload session; storage errors and an exhausted retry budget leave it open for the caller (it has the checkpoint);
 * - pause and cancel requests are honoured between parts;
 * - after completing, the stored size is checked against the source size.
 *
 * Resuming from a checkpoint first reconciles it with the target (Reconciler): an open session continues after the
 * last checkpointed part; an upload that an earlier run already completed is returned without reading the source; a
 * gone session or a missing part ends in ResumeImpossible, and the caller starts again from byte zero with a fresh key.
 */
final readonly class TransferEngine
{
    /** @var Closure(float): void */
    private Closure $sleep;

    /**
     * @param (Closure(float): void)|null $sleep
     */
    public function __construct(
        private ChunkPlanner $planner = new ChunkPlanner(),
        private RetryPolicy $retry = new RetryPolicy(),
        private ?BandwidthLimiter $limiter = null,
        ?Closure $sleep = null,
        private Reconciler $reconciler = new Reconciler(),
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * @throws TransferException|\App\Storage\Exception\StorageException
     */
    public function transfer(TransferRequest $request): TransferResult
    {
        if ($request->resumeFrom !== null) {
            return $this->resume($request, $request->resumeFrom);
        }

        $target = $request->target;
        $fingerprint = $request->source->open();
        $plan = $this->planner->plan($fingerprint->totalSize, $target->capabilities());
        $session = $target->begin(new UploadRequest($request->key, $fingerprint->totalSize, $plan->partSize, $request->contentType));
        $sha = hash_init('sha256');
        $md5 = hash_init('md5');
        $checkpoint = $this->checkpoint($request, $session, [], $fingerprint, $sha, $md5);

        return $this->run($request, $session, [], $fingerprint, $sha, $md5, $checkpoint, completingMayHaveStarted: false);
    }

    private function resume(TransferRequest $request, TransferCheckpoint $checkpoint): TransferResult
    {
        $session = $checkpoint->session;
        if (!$session->key->equals($request->key)) {
            throw new InvalidArgumentException('The checkpoint belongs to another object key.');
        }

        $state = $this->reconciler->reconcile($checkpoint, $request->target);
        switch ($state->outcome) {
            case ReconcileOutcome::AlreadyCompleted:
                return $this->recover($request, $state);
            case ReconcileOutcome::SessionGone:
                throw new ResumeImpossible('The upload session no longer exists at the target.');
            case ReconcileOutcome::PartMissing:
                $this->abortQuietly($request, $session);

                throw new ResumeImpossible(sprintf('The target no longer holds part %d as checkpointed.', $state->missingPart));
            case ReconcileOutcome::Resumable:
                break;
        }

        $fingerprint = $request->source->open();
        if (!$checkpoint->fingerprint->matches($fingerprint)) {
            $this->abortQuietly($request, $session);

            throw new SourceChanged('The source changed since the checkpoint; start again from byte zero.');
        }
        if (!$request->source->isResumable() && $checkpoint->parts !== []) {
            $this->abortQuietly($request, $session);

            throw new ResumeImpossible('A sequential source cannot continue in the middle of a file.');
        }
        [$sha, $md5] = $this->hashState($request, $checkpoint, $fingerprint);

        // Parts the target holds beyond the checkpoint (Reconciliation::$extraParts) are sent again: their bytes are
        // not in the hash state, and writing a part again replaces it.
        return $this->run($request, $session, $checkpoint->parts, $fingerprint, $sha, $md5, $checkpoint, completingMayHaveStarted: $checkpoint->hasAllParts());
    }

    /**
     * @param list<UploadedPart> $parts acknowledged parts so far
     */
    private function run(TransferRequest $request, UploadSession $session, array $parts, SourceFingerprint $fingerprint, HashContext $sha, HashContext $md5, TransferCheckpoint $checkpoint, bool $completingMayHaveStarted): TransferResult
    {
        $target = $request->target;
        for ($number = count($parts) + 1; $number <= $session->partCount(); ++$number) {
            $stop = $request->control?->stopRequested();
            if ($stop !== null) {
                if ($stop === StopRequest::Cancel) {
                    $target->abort($session);
                }

                return new TransferResult(TransferStatus::Stopped, $fingerprint, stopRequest: $stop, checkpoint: $checkpoint);
            }

            [$parts[], $sha, $md5] = $this->transferPart($request, $session, $number, $fingerprint, $sha, $md5);
            $checkpoint = $this->checkpoint($request, $session, $parts, $fingerprint, $sha, $md5);
        }

        $sha256 = hash_final($sha);
        $md5sum = hash_final($md5);
        if ($request->commitGate !== null && !$request->commitGate->allowCommit($sha256, $md5sum, $fingerprint->totalSize)) {
            $target->abort($session);

            return new TransferResult(TransferStatus::Deduplicated, $fingerprint, $sha256, $md5sum, checkpoint: $checkpoint);
        }

        $stored = $this->complete($request, $session, $parts, $completingMayHaveStarted);
        $info = $target->stat($session->key);
        if ($info === null || $info->size !== $fingerprint->totalSize) {
            throw new VerificationFailed(sprintf(
                'The stored object has %s bytes, the source %d.',
                $info === null ? 'no' : (string) $info->size,
                $fingerprint->totalSize,
            ));
        }

        return new TransferResult(TransferStatus::Completed, $fingerprint, $sha256, $md5sum, $stored, checkpoint: $checkpoint);
    }

    /**
     * Completes the upload, retrying temporary failures like a part. When an attempt's outcome is unknown (its answer
     * was lost, or the run before a resume may have been completing when it died), the target can still be finishing
     * that attempt and report the key as taken; a later attempt then finds the session gone and returns the object
     * (ArchiveStorageAdapter::complete() is safe to repeat).
     *
     * @param list<UploadedPart> $parts
     */
    private function complete(TransferRequest $request, UploadSession $session, array $parts, bool $outcomeUnknown): StoredObject
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $request->target->complete($session, $parts);
            } catch (TransientStorageFailure $e) {
                $failure = $e;
                $retryAfter = $e->retryAfterSeconds;
                $outcomeUnknown = true;
            } catch (ObjectAlreadyExists $e) {
                if (!$outcomeUnknown) {
                    throw $e;
                }
                $failure = $e;
                $retryAfter = null;
            }

            if ($attempt >= $this->retry->maxAttempts) {
                throw new RetriesExhausted(sprintf('The upload could not be completed after %d attempts.', $attempt), 0, $failure);
            }
            ($this->sleep)($this->retry->delayAfter($attempt, $retryAfter));
        }
    }

    /**
     * An earlier run completed the upload but did not get to report it. The object is returned with the hashes from
     * the checkpoint, which covers every part. Nothing is sent, the source is not read (unless the saved hash state is
     * unreadable), and the commit gate is not asked again: the earlier run asked it before completing.
     */
    private function recover(TransferRequest $request, Reconciliation $state): TransferResult
    {
        $checkpoint = $state->checkpoint;
        $object = $state->object ?? throw new LogicException('An already completed upload comes with its object.');

        try {
            $sha = $checkpoint->sha256();
            $md5 = $checkpoint->md5();
        } catch (InvalidArgumentException) {
            $fingerprint = $request->source->open();
            if (!$checkpoint->fingerprint->matches($fingerprint)) {
                throw new SourceChanged('The upload was completed earlier, but its saved hash state is unreadable and the source changed since, so the stored object cannot be hashed from the source; start again from byte zero.');
            }
            [$sha, $md5] = $this->rehashFromSource($request, $checkpoint->session, $checkpoint, $fingerprint);
        }

        return new TransferResult(
            TransferStatus::Completed,
            $checkpoint->fingerprint,
            hash_final($sha),
            hash_final($md5),
            new StoredObject($object->key, $object->size, $object->remoteETag, $object->lastModified ?? new DateTimeImmutable()),
            checkpoint: $checkpoint,
            recovered: true,
        );
    }

    /**
     * @return array{UploadedPart, HashContext, HashContext}
     */
    private function transferPart(TransferRequest $request, UploadSession $session, int $number, SourceFingerprint $fingerprint, HashContext $sha, HashContext $md5): array
    {
        [$buffer, $partSha, $partMd5] = $this->readPart($request, $session, $number, $fingerprint, $sha, $md5);

        for ($attempt = 1; ; ++$attempt) {
            try {
                $uploaded = $request->target->writePart($session, $number, PartBody::fromStream($buffer, $session->lengthOf($number)));
                fclose($buffer);

                return [$uploaded, $partSha, $partMd5];
            } catch (TransientStorageFailure $e) {
                if ($attempt >= $this->retry->maxAttempts) {
                    fclose($buffer);

                    throw new RetriesExhausted(sprintf('Part %d could not be stored after %d attempts.', $number, $attempt), 0, $e);
                }
                ($this->sleep)($this->retry->delayAfter($attempt, $e->retryAfterSeconds));
            } catch (PartRejected $e) {
                fclose($buffer);
                $request->target->abort($session);

                throw $e;
            }
        }
    }

    /**
     * Reads one part into memory and hashes it on copies of the running hash state.
     *
     * @return array{resource, HashContext, HashContext}
     */
    private function readPart(TransferRequest $request, UploadSession $session, int $number, SourceFingerprint $fingerprint, HashContext $sha, HashContext $md5): array
    {
        $offset = $session->offsetOf($number);
        $length = $session->lengthOf($number);

        for ($attempt = 1; ; ++$attempt) {
            $buffer = fopen('php://memory', 'w+b');
            if ($buffer === false) {
                throw new VerificationFailed('Could not open a memory buffer for a part.');
            }
            $partSha = hash_copy($sha);
            $partMd5 = hash_copy($md5);

            try {
                $received = 0;
                foreach ($request->source->readPart($offset, $length, $fingerprint) as $chunk) {
                    fwrite($buffer, $chunk);
                    hash_update($partSha, $chunk);
                    hash_update($partMd5, $chunk);
                    $received += strlen($chunk);
                    $this->limiter?->consume(strlen($chunk));
                }
                if ($received !== $length) {
                    throw new TransientSourceFailure(sprintf('Part %d: the source delivered %d of %d bytes.', $number, $received, $length));
                }

                return [$buffer, $partSha, $partMd5];
            } catch (TransientSourceFailure $e) {
                fclose($buffer);
                if (!$request->source->isResumable()) {
                    $this->abortQuietly($request, $session);

                    throw new NotResumableFromSource(sprintf('The sequential source failed in part %d; the transfer has to start again from byte zero.', $number), 0, $e);
                }
                if ($attempt >= $this->retry->maxAttempts) {
                    throw new RetriesExhausted(sprintf('Part %d could not be read after %d attempts.', $number, $attempt), 0, $e);
                }
                ($this->sleep)($this->retry->delayAfter($attempt, $e->retryAfterSeconds));
            } catch (TransferException $e) {
                // Source changed, range ignored or mismatched, file unavailable, redirect refused: this upload can't
                // be finished from this source.
                fclose($buffer);
                $this->abortQuietly($request, $session);

                throw $e;
            }
        }
    }

    /**
     * @return array{HashContext, HashContext}
     */
    private function hashState(TransferRequest $request, TransferCheckpoint $checkpoint, SourceFingerprint $fingerprint): array
    {
        try {
            return [$checkpoint->sha256(), $checkpoint->md5()];
        } catch (InvalidArgumentException) {
            // Hash state from another PHP version, for example: hash the finished parts again from the source,
            // never from the target, whose bytes are what the hash is meant to verify.
            return $this->rehashFromSource($request, $checkpoint->session, $checkpoint, $fingerprint);
        }
    }

    /**
     * @return array{HashContext, HashContext}
     */
    private function rehashFromSource(TransferRequest $request, UploadSession $session, TransferCheckpoint $checkpoint, SourceFingerprint $fingerprint): array
    {
        $sha = hash_init('sha256');
        $md5 = hash_init('md5');
        foreach ($checkpoint->parts as $part) {
            [$buffer, $sha, $md5] = $this->readPart($request, $session, $part->number, $fingerprint, $sha, $md5);
            fclose($buffer);
        }

        return [$sha, $md5];
    }

    /**
     * @param list<UploadedPart> $parts
     */
    private function checkpoint(TransferRequest $request, UploadSession $session, array $parts, SourceFingerprint $fingerprint, HashContext $sha, HashContext $md5): TransferCheckpoint
    {
        $checkpoint = new TransferCheckpoint($session, $parts, $fingerprint, serialize(hash_copy($sha)), serialize(hash_copy($md5)));
        $request->checkpoints->save($checkpoint);

        return $checkpoint;
    }

    private function abortQuietly(TransferRequest $request, UploadSession $session): void
    {
        try {
            $request->target->abort($session);
        } catch (Throwable) {
            // The transfer fails anyway; a cleanup of abandoned upload sessions removes what is left.
        }
    }
}
