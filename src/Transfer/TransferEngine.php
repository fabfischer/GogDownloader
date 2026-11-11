<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\Exception\PartRejected;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\PartBody;
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
use HashContext;
use InvalidArgumentException;
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
 *   buffer, both with backoff, up to the retry budget;
 * - a changed source, a non-resumable source failing midway, a cancel request or a "no" from the commit gate abort the
 *   upload session; storage errors and an exhausted retry budget leave it open for the caller (it has the checkpoint);
 * - pause and cancel requests are honoured between parts;
 * - after completing, the stored size is checked against the source size.
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
        $target = $request->target;
        $fingerprint = $request->source->open();

        if ($request->resumeFrom !== null) {
            [$session, $parts, $sha, $md5] = $this->prepareResume($request, $request->resumeFrom, $fingerprint);
            $checkpoint = $request->resumeFrom;
        } else {
            $plan = $this->planner->plan($fingerprint->totalSize, $target->capabilities());
            $session = $target->begin(new UploadRequest($request->key, $fingerprint->totalSize, $plan->partSize, $request->contentType));
            $parts = [];
            $sha = hash_init('sha256');
            $md5 = hash_init('md5');
            $checkpoint = $this->checkpoint($request, $session, $parts, $fingerprint, $sha, $md5);
        }

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

        $stored = $target->complete($session, $parts);
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
     * @return array{UploadSession, list<UploadedPart>, HashContext, HashContext}
     */
    private function prepareResume(TransferRequest $request, TransferCheckpoint $checkpoint, SourceFingerprint $fingerprint): array
    {
        $target = $request->target;
        $session = $checkpoint->session;
        if (!$session->key->equals($request->key)) {
            throw new InvalidArgumentException('The checkpoint belongs to another object key.');
        }
        if ($session->backend !== $target->capabilities()->backend) {
            throw new ResumeImpossible('The checkpoint belongs to another storage backend.');
        }
        if (!$checkpoint->fingerprint->matches($fingerprint)) {
            $this->abortQuietly($request, $session);

            throw new SourceChanged('The source changed since the checkpoint; start again from byte zero.');
        }
        if (!$request->source->isResumable() && $checkpoint->parts !== []) {
            $this->abortQuietly($request, $session);

            throw new ResumeImpossible('A sequential source cannot continue in the middle of a file.');
        }

        if ($target->capabilities()->canListParts) {
            try {
                $listed = $target->listCompletedParts($session);
            } catch (UploadSessionNotFound $e) {
                throw new ResumeImpossible('The upload session no longer exists at the target.', 0, $e);
            }
            $remote = [];
            foreach ($listed as $part) {
                $remote[$part->number] = $part->remoteId;
            }
            foreach ($checkpoint->parts as $part) {
                if (($remote[$part->number] ?? null) !== $part->remoteId) {
                    $this->abortQuietly($request, $session);

                    throw new ResumeImpossible(sprintf('The target no longer holds part %d as checkpointed.', $part->number));
                }
            }
        }

        try {
            $sha = $checkpoint->sha256();
            $md5 = $checkpoint->md5();
        } catch (InvalidArgumentException) {
            // Hash state from another PHP version, for example: hash the finished parts again from the source,
            // never from the target, whose bytes are what the hash is meant to verify.
            [$sha, $md5] = $this->rehashFromSource($request, $session, $checkpoint, $fingerprint);
        }

        return [$session, $checkpoint->parts, $sha, $md5];
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
