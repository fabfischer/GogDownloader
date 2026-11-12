<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Storage\InMemory\InMemoryStorageAdapter;
use App\Storage\ObjectKey;
use App\Storage\ObjectKeyBuilder;
use App\Storage\PartBody;
use App\Storage\StorageCapabilities;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use App\Transfer\Exception\ResumeImpossible;
use App\Transfer\ReconcileOutcome;
use App\Transfer\Reconciler;
use App\Transfer\Reconciliation;
use App\Transfer\SourceFingerprint;
use App\Transfer\TransferCheckpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reconciler::class)]
#[CoversClass(Reconciliation::class)]
final class ReconcilerTest extends TestCase
{
    /** 25 bytes in parts of 10: 10 + 10 + 5. */
    private const FILE = 'abcdefghijklmnopqrstuvwxy';

    private InMemoryStorageAdapter $target;

    private ObjectKey $key;

    protected function setUp(): void
    {
        $this->target = new InMemoryStorageAdapter();
        $this->key = (new ObjectKeyBuilder())->revisionKey(1, 'Reconcile test', '0190f5e2-7c1a-7d3e-9a4b-1c2d3e4f5a6b', '0190f5e2-8d2b-7e4f-8b5c-2d3e4f5a6b7c', 'file.bin');
    }

    public function testAnOpenSessionWithEveryCheckpointedPartIsResumable(): void
    {
        [$session, $parts] = $this->upload(2);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::Resumable, $state->outcome);
        self::assertSame(3, $state->nextPartNumber());
        self::assertSame([], $state->extraParts);
        self::assertTrue($state->listed);
    }

    public function testPartsTheTargetHoldsBeyondTheCheckpointAreReported(): void
    {
        [$session, $parts] = $this->upload(3);

        // The checkpoint was saved after part 1; parts 2 and 3 reached the target, but their acknowledgements didn't
        // reach a checkpoint.
        $state = (new Reconciler())->reconcile($this->checkpoint($session, [$parts[0]]), $this->target);

        self::assertSame(ReconcileOutcome::Resumable, $state->outcome);
        self::assertSame(2, $state->nextPartNumber());
        self::assertSame([2, 3], $state->extraParts);
    }

    public function testACheckpointedPartTheTargetHoldsDifferentlyIsMissing(): void
    {
        [$session, $parts] = $this->upload(2);
        $changed = new UploadedPart(2, 10, 10, 'another-etag');

        $state = (new Reconciler())->reconcile($this->checkpoint($session, [$parts[0], $changed]), $this->target);

        self::assertSame(ReconcileOutcome::PartMissing, $state->outcome);
        self::assertSame(2, $state->missingPart);
    }

    public function testAGoneSessionWithMissingPartsIsGone(): void
    {
        [$session, $parts] = $this->upload(2);
        $this->target->expireSession($session);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::SessionGone, $state->outcome);
    }

    public function testAGoneSessionWithEveryPartAndTheObjectAtTheKeyWasCompleted(): void
    {
        [$session, $parts] = $this->upload(3);
        $this->target->complete($session, $parts);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::AlreadyCompleted, $state->outcome);
        self::assertSame(25, $state->object?->size);
        self::assertTrue($state->object->key->equals($this->key));
    }

    public function testAGoneSessionWithEveryPartButNoObjectIsGone(): void
    {
        [$session, $parts] = $this->upload(3);
        $this->target->expireSession($session);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::SessionGone, $state->outcome);
    }

    public function testAnObjectOfAnotherSizeAtTheKeyDoesNotCountAsCompleted(): void
    {
        [$session, $parts] = $this->upload(3);
        $this->target->expireSession($session);
        $other = $this->target->begin(new UploadRequest($this->key, 5, 5));
        $this->target->complete($other, [$this->target->writePart($other, 1, PartBody::fromString('12345'))]);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::SessionGone, $state->outcome);
    }

    public function testReconcilingOnlyReads(): void
    {
        [$session, $parts] = $this->upload(2);
        $changed = new UploadedPart(1, 0, 10, 'another-etag');

        (new Reconciler())->reconcile($this->checkpoint($session, [$changed, $parts[1]]), $this->target);

        self::assertSame(1, $this->target->openSessionCount(), 'A missing part is reported, not aborted');
        self::assertCount(2, $this->target->listCompletedParts($session));
        self::assertSame([], $this->target->objectKeys());
    }

    public function testATargetThatCannotListPartsTrustsTheCheckpoint(): void
    {
        $this->target = new InMemoryStorageAdapter(new StorageCapabilities('memory', 1, 1024, 10000, canListParts: false, sessionIdleTimeoutSeconds: null, conditionalCreate: true, contentChecksumOnStat: false));
        [$session, $parts] = $this->upload(1);

        $state = (new Reconciler())->reconcile($this->checkpoint($session, $parts), $this->target);

        self::assertSame(ReconcileOutcome::Resumable, $state->outcome);
        self::assertFalse($state->listed);
    }

    public function testACheckpointOfAnotherBackendIsRefused(): void
    {
        [$session, $parts] = $this->upload(1);
        $foreign = new UploadSession('s3', $session->key, $session->sessionId, $session->totalSize, $session->partSize, $session->startedAt);

        $this->expectException(ResumeImpossible::class);
        (new Reconciler())->reconcile($this->checkpoint($foreign, $parts), $this->target);
    }

    /**
     * Opens a session for FILE in parts of 10 and uploads the first $count parts.
     *
     * @return array{UploadSession, list<UploadedPart>}
     */
    private function upload(int $count): array
    {
        $session = $this->target->begin(new UploadRequest($this->key, strlen(self::FILE), 10));
        $parts = [];
        for ($number = 1; $number <= $count; ++$number) {
            $parts[] = $this->target->writePart($session, $number, PartBody::fromString(substr(self::FILE, $session->offsetOf($number), $session->lengthOf($number))));
        }

        return [$session, $parts];
    }

    /**
     * @param list<UploadedPart> $parts
     */
    private function checkpoint(UploadSession $session, array $parts): TransferCheckpoint
    {
        return new TransferCheckpoint($session, $parts, new SourceFingerprint(strlen(self::FILE), '"etag-1"'), serialize(hash_init('sha256')), serialize(hash_init('md5')));
    }
}
