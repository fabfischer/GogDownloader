<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\InMemory\InMemoryStorageAdapter;
use App\Storage\ObjectKey;
use App\Storage\ObjectKeyBuilder;
use App\Storage\PartBody;
use App\Storage\RemoteObjectInfo;
use App\Storage\StorageCapabilities;
use App\Storage\StoredObject;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use App\Transfer\ChunkPlanner;
use App\Transfer\CommitGate;
use App\Transfer\Exception\NotResumableFromSource;
use App\Transfer\Exception\ResumeImpossible;
use App\Transfer\Exception\RetriesExhausted;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\Exception\VerificationFailed;
use App\Transfer\InMemoryCheckpointStore;
use App\Transfer\Reconciler;
use App\Transfer\RetryPolicy;
use App\Transfer\StopRequest;
use App\Transfer\TransferCheckpoint;
use App\Transfer\TransferControl;
use App\Transfer\TransferEngine;
use App\Transfer\TransferRequest;
use App\Transfer\TransferSource;
use App\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransferEngine::class)]
#[CoversClass(TransferCheckpoint::class)]
#[CoversClass(Reconciler::class)]
final class TransferEngineTest extends TestCase
{
    /** 25 bytes in parts of 10: 10 + 10 + 5. */
    private const FILE = 'abcdefghijklmnopqrstuvwxy';

    private InMemoryStorageAdapter $target;

    private InMemoryCheckpointStore $checkpoints;

    /** @var list<float> */
    private array $slept = [];

    private ObjectKey $key;

    protected function setUp(): void
    {
        $this->target = new InMemoryStorageAdapter();
        $this->checkpoints = new InMemoryCheckpointStore();
        $this->slept = [];
        $this->key = (new ObjectKeyBuilder())->revisionKey(1, 'Engine test', '0190f5e2-7c1a-7d3e-9a4b-1c2d3e4f5a6b', '0190f5e2-8d2b-7e4f-8b5c-2d3e4f5a6b7c', 'file.bin');
    }

    public function testTheFileIsStoredInPartsWithHashesAndACheckpointAfterEachAcknowledgedPart(): void
    {
        $source = new FakeSource(self::FILE);

        $result = $this->engine()->transfer($this->request($source));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame(md5(self::FILE), $result->md5);
        self::assertSame(self::FILE, $this->stored());
        self::assertSame([[0, 10], [10, 10], [20, 5]], $source->reads);
        // One checkpoint when the session opens, then one per acknowledged part.
        self::assertSame([0, 1, 2, 3], array_map(static fn (TransferCheckpoint $c): int => count($c->parts), $this->checkpoints->saved));
        self::assertSame(25, $this->checkpoints->last()?->bytesDone());
        self::assertSame(0, $this->target->openSessionCount());
    }

    public function testATemporarySourceFailureReadsThePartAgain(): void
    {
        $source = new FakeSource(self::FILE);
        $source->failReadAt(10, new TransientSourceFailure('connection reset', 4));

        $result = $this->engine()->transfer($this->request($source));

        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame([[0, 10], [10, 10], [10, 10], [20, 5]], $source->reads);
        self::assertSame([4.0], $this->slept);
    }

    public function testATemporaryStorageFailureSendsTheSameBytesAgainWithoutRereading(): void
    {
        $source = new FakeSource(self::FILE);
        $this->target->failNextWrites(new TransientStorageFailure('503'));

        $result = $this->engine()->transfer($this->request($source));

        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame([[0, 10], [10, 10], [20, 5]], $source->reads);
        self::assertCount(1, $this->slept);
        // The failed attempt did not produce a checkpoint.
        self::assertSame([0, 1, 2, 3], array_map(static fn (TransferCheckpoint $c): int => count($c->parts), $this->checkpoints->saved));
    }

    public function testExhaustedRetriesKeepTheSessionAndTheLastCheckpointForLater(): void
    {
        $source = new FakeSource(self::FILE);
        $source->failReadAt(10, new TransientSourceFailure('a'), new TransientSourceFailure('b'), new TransientSourceFailure('c'));

        try {
            $this->engine()->transfer($this->request($source));
            self::fail('Expected RetriesExhausted');
        } catch (RetriesExhausted $e) {
            self::assertInstanceOf(TransientSourceFailure::class, $e->getPrevious());
        }

        self::assertSame(1, $this->target->openSessionCount());
        self::assertCount(1, $this->checkpoints->last()->parts ?? []);
        self::assertSame([], $this->target->objectKeys());
    }

    public function testAChangedSourceAbortsTheUpload(): void
    {
        $source = new FakeSource(self::FILE);
        $source->failReadAt(10, new SourceChanged('ETag changed'));

        try {
            $this->engine()->transfer($this->request($source));
            self::fail('Expected SourceChanged');
        } catch (SourceChanged) {
        }

        self::assertSame(0, $this->target->openSessionCount());
        self::assertSame([], $this->target->objectKeys());
    }

    public function testAPausedTransferResumesFromItsSerializedCheckpointInAnotherRun(): void
    {
        $first = $this->engine()->transfer($this->request(new FakeSource(self::FILE), control: $this->pauseAfter(2)));

        self::assertSame(TransferStatus::Stopped, $first->status);
        self::assertSame(StopRequest::Pause, $first->stopRequest);
        self::assertSame(1, $this->target->openSessionCount());

        // What the app stores and loads in another worker process.
        $checkpoint = TransferCheckpoint::fromArray(json_decode((string) json_encode($first->checkpoint?->toArray()), true));
        $second = new FakeSource(self::FILE);
        $result = $this->engine()->transfer($this->request($second, resumeFrom: $checkpoint));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame([[20, 5]], $second->reads);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame(md5(self::FILE), $result->md5);
        self::assertSame(self::FILE, $this->stored());
    }

    public function testACancelledTransferAbortsTheSession(): void
    {
        $control = new class implements TransferControl {
            public function stopRequested(): StopRequest
            {
                return StopRequest::Cancel;
            }
        };

        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE), control: $control));

        self::assertSame(TransferStatus::Stopped, $result->status);
        self::assertSame(StopRequest::Cancel, $result->stopRequest);
        self::assertSame(0, $this->target->openSessionCount());
    }

    public function testResumingAfterTheSourceChangedAbortsAndAsksForARestart(): void
    {
        $checkpoint = $this->pausedCheckpoint();

        try {
            $this->engine()->transfer($this->request(new FakeSource(self::FILE, etag: '"etag-2"'), resumeFrom: $checkpoint));
            self::fail('Expected SourceChanged');
        } catch (SourceChanged) {
        }

        self::assertSame(0, $this->target->openSessionCount());
    }

    public function testResumingAnExpiredSessionIsImpossible(): void
    {
        $checkpoint = $this->pausedCheckpoint();
        $this->target->expireSession($checkpoint->session);

        $this->expectException(ResumeImpossible::class);
        $this->engine()->transfer($this->request(new FakeSource(self::FILE), resumeFrom: $checkpoint));
    }

    public function testResumingWhenTheTargetLostACheckpointedPartIsImpossible(): void
    {
        $checkpoint = $this->pausedCheckpoint();
        $tampered = new TransferCheckpoint(
            $checkpoint->session,
            [new UploadedPart(1, 0, 10, 'not-what-the-target-holds'), $checkpoint->parts[1]],
            $checkpoint->fingerprint,
            $checkpoint->sha256State,
            $checkpoint->md5State,
        );

        try {
            $this->engine()->transfer($this->request(new FakeSource(self::FILE), resumeFrom: $tampered));
            self::fail('Expected ResumeImpossible');
        } catch (ResumeImpossible) {
        }

        self::assertSame(0, $this->target->openSessionCount());
    }

    public function testAnUnreadableHashStateIsRebuiltFromTheSourceNotTheTarget(): void
    {
        $checkpoint = $this->pausedCheckpoint();
        $broken = new TransferCheckpoint($checkpoint->session, $checkpoint->parts, $checkpoint->fingerprint, 'not a hash state', 'neither');
        $source = new FakeSource(self::FILE);

        $result = $this->engine()->transfer($this->request($source, resumeFrom: $broken));

        self::assertSame([[0, 10], [10, 10], [20, 5]], $source->reads);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
    }

    public function testASequentialSourceFailingMidwayAbortsAndNeedsARestart(): void
    {
        $source = new FakeSource(self::FILE, resumable: false);
        $source->failReadAt(10, new TransientSourceFailure('connection reset'));

        try {
            $this->engine()->transfer($this->request($source));
            self::fail('Expected NotResumableFromSource');
        } catch (NotResumableFromSource) {
        }

        self::assertSame(0, $this->target->openSessionCount());
        self::assertSame([], $this->slept);
    }

    public function testTheCommitGateCanDeduplicate(): void
    {
        $gate = new class implements CommitGate {
            /** @var list<array{string, string, int}> */
            public array $asked = [];

            public function allowCommit(string $sha256, string $md5, int $size): bool
            {
                $this->asked[] = [$sha256, $md5, $size];

                return false;
            }
        };

        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE), gate: $gate));

        self::assertSame(TransferStatus::Deduplicated, $result->status);
        self::assertSame([[hash('sha256', self::FILE), md5(self::FILE), 25]], $gate->asked);
        self::assertSame([], $this->target->objectKeys());
        self::assertSame(0, $this->target->openSessionCount());
    }

    public function testAStoredSizeThatDiffersFromTheSourceFailsVerification(): void
    {
        $target = new class($this->target) implements ArchiveStorageAdapter {
            public function __construct(private readonly InMemoryStorageAdapter $inner)
            {
            }

            public function capabilities(): StorageCapabilities
            {
                return $this->inner->capabilities();
            }

            public function begin(UploadRequest $request): UploadSession
            {
                return $this->inner->begin($request);
            }

            public function listCompletedParts(UploadSession $session): array
            {
                return $this->inner->listCompletedParts($session);
            }

            public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart
            {
                return $this->inner->writePart($session, $number, $body);
            }

            public function complete(UploadSession $session, array $parts): StoredObject
            {
                return $this->inner->complete($session, $parts);
            }

            public function abort(UploadSession $session): void
            {
                $this->inner->abort($session);
            }

            public function stat(ObjectKey $key): ?RemoteObjectInfo
            {
                $info = $this->inner->stat($key);

                return $info === null ? null : new RemoteObjectInfo($key, $info->size - 1, $info->remoteETag, $info->lastModified);
            }

            public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable
            {
                return $this->inner->openRead($key, $chunkSize);
            }

            public function delete(ObjectKey $key): void
            {
                $this->inner->delete($key);
            }
        };

        $this->expectException(VerificationFailed::class);
        $this->engine()->transfer(new TransferRequest(new FakeSource(self::FILE), $target, $this->key, $this->checkpoints));
    }

    public function testATemporaryFailureWhileCompletingIsRetried(): void
    {
        $this->target->failNextCompletes(new TransientStorageFailure('502 from the proxy', 7));

        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE)));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame([7.0], $this->slept);
        self::assertSame(self::FILE, $this->stored());
    }

    public function testALostAnswerWhileCompletingIsRetriedAndReturnsTheStoredObject(): void
    {
        $this->target->loseNextCompleteAnswer(new TransientStorageFailure('connection reset after the request was sent'));

        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE)));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertFalse($result->recovered);
        self::assertSame(25, $result->stored?->size);
        self::assertSame([$this->key->value], $this->target->objectKeys());
        self::assertSame(self::FILE, $this->stored());
    }

    public function testATakenKeyAfterAnUnclearAttemptIsRetriedWhileTheTargetFinishesIt(): void
    {
        // Nextcloud: a retried MOVE answers 412 while the first one is still assembling the file.
        $this->target->failNextCompletes(new TransientStorageFailure('504 from the proxy'), new ObjectAlreadyExists('412'));

        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE)));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertCount(2, $this->slept);
    }

    public function testATakenKeyOnTheFirstAttemptToCompleteIsNotRetried(): void
    {
        $this->target->failNextCompletes(new ObjectAlreadyExists('412'));

        try {
            $this->engine()->transfer($this->request(new FakeSource(self::FILE)));
            self::fail('Expected ObjectAlreadyExists');
        } catch (ObjectAlreadyExists) {
        }

        self::assertSame([], $this->slept);
    }

    public function testCompletingGivesUpAfterTheRetryBudgetAndALaterResumeFinishes(): void
    {
        $this->target->failNextCompletes(new TransientStorageFailure('a'), new TransientStorageFailure('b'), new TransientStorageFailure('c'));

        try {
            $this->engine()->transfer($this->request(new FakeSource(self::FILE)));
            self::fail('Expected RetriesExhausted');
        } catch (RetriesExhausted $e) {
            self::assertInstanceOf(TransientStorageFailure::class, $e->getPrevious());
        }
        $last = $this->checkpoints->last();
        self::assertNotNull($last);
        self::assertTrue($last->hasAllParts());
        self::assertSame(1, $this->target->openSessionCount());

        $source = new FakeSource(self::FILE);
        $result = $this->engine()->transfer($this->request($source, resumeFrom: $last));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame([], $source->reads);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame(self::FILE, $this->stored());
    }

    public function testAResumeFindsTheUploadCompletedByTheRunBeforeIt(): void
    {
        // The run completes the upload, then its worker dies before the result is recorded. The job still has the
        // last checkpoint, which holds every part.
        $this->engine()->transfer($this->request(new FakeSource(self::FILE)));
        $last = TransferCheckpoint::fromArray(json_decode((string) json_encode($this->checkpoints->last()?->toArray()), true));
        $gate = new RecordingCommitGate(allow: true);
        $source = new FakeSource(self::FILE);

        $result = $this->engine()->transfer($this->request($source, resumeFrom: $last, gate: $gate));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertTrue($result->recovered);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame(md5(self::FILE), $result->md5);
        self::assertSame(25, $result->stored?->size);
        self::assertTrue($result->stored->key->equals($this->key));
        self::assertSame(0, $source->opens, 'The source is not needed');
        self::assertSame([], $gate->asked, 'The run before asked the gate before completing');
    }

    public function testARecoveredUploadWithAnUnreadableHashStateIsHashedFromTheSource(): void
    {
        $this->engine()->transfer($this->request(new FakeSource(self::FILE)));
        $last = $this->checkpoints->last();
        self::assertNotNull($last);
        $broken = new TransferCheckpoint($last->session, $last->parts, $last->fingerprint, 'not a hash state', 'neither');
        $source = new FakeSource(self::FILE);

        $result = $this->engine()->transfer($this->request($source, resumeFrom: $broken));

        self::assertTrue($result->recovered);
        self::assertSame([[0, 10], [10, 10], [20, 5]], $source->reads);
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
    }

    public function testAFullyCheckpointedUploadWhoseSessionExpiredCannotBeResumed(): void
    {
        $this->target->failNextCompletes(new TransientStorageFailure('a'), new TransientStorageFailure('b'), new TransientStorageFailure('c'));

        try {
            $this->engine()->transfer($this->request(new FakeSource(self::FILE)));
            self::fail('Expected RetriesExhausted');
        } catch (RetriesExhausted) {
        }
        $last = $this->checkpoints->last();
        self::assertNotNull($last);
        $this->target->expireSession($last->session);

        $this->expectException(ResumeImpossible::class);
        $this->engine()->transfer($this->request(new FakeSource(self::FILE), resumeFrom: $last));
    }

    public function testAPartTheTargetGotAfterTheLastCheckpointIsSentAgain(): void
    {
        $checkpoint = $this->pausedCheckpoint(1);
        // The worker sent part 2 and the target stored it, but the worker died before it could save a checkpoint.
        $this->target->writePart($checkpoint->session, 2, PartBody::fromString(substr(self::FILE, 10, 10)));
        $source = new FakeSource(self::FILE);

        $result = $this->engine()->transfer($this->request($source, resumeFrom: $checkpoint));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame([[10, 10], [20, 5]], $source->reads, 'Part 2 is read and sent again: its bytes are not in the hash state');
        self::assertSame(hash('sha256', self::FILE), $result->sha256);
        self::assertSame(self::FILE, $this->stored());
    }

    private function engine(): TransferEngine
    {
        return new TransferEngine(
            new ChunkPlanner(preferredPartSize: 10, maxPartSizeInMemory: 1024, alignment: 1),
            new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 1.0, maxDelaySeconds: 10.0, random: static fn (): float => 0.0),
            null,
            function (float $seconds): void {
                $this->slept[] = $seconds;
            },
        );
    }

    private function request(TransferSource $source, ?TransferControl $control = null, ?TransferCheckpoint $resumeFrom = null, ?CommitGate $gate = null): TransferRequest
    {
        return new TransferRequest($source, $this->target, $this->key, $this->checkpoints, $resumeFrom, $control, $gate);
    }

    private function pauseAfter(int $parts): TransferControl
    {
        $checkpoints = $this->checkpoints;

        return new class($checkpoints, $parts) implements TransferControl {
            public function __construct(private readonly InMemoryCheckpointStore $checkpoints, private readonly int $parts)
            {
            }

            public function stopRequested(): ?StopRequest
            {
                return count($this->checkpoints->last()->parts ?? []) >= $this->parts ? StopRequest::Pause : null;
            }
        };
    }

    private function pausedCheckpoint(int $parts = 2): TransferCheckpoint
    {
        $result = $this->engine()->transfer($this->request(new FakeSource(self::FILE), control: $this->pauseAfter($parts)));
        self::assertNotNull($result->checkpoint);

        return $result->checkpoint;
    }

    private function stored(): string
    {
        return implode('', iterator_to_array($this->target->openRead($this->key), false));
    }
}
