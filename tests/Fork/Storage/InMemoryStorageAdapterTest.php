<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\InMemory\InMemoryStorageAdapter;
use App\Storage\PartBody;
use App\Storage\StorageCapabilities;
use App\Storage\UploadRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorageAdapter::class)]
final class InMemoryStorageAdapterTest extends ArchiveStorageAdapterContractTestCase
{
    public function testInjectedWriteFailuresHappenOnceAndStoreNothing(): void
    {
        $adapter = new InMemoryStorageAdapter();
        $session = $adapter->begin(new UploadRequest($this->newKey(), 4, 4));
        $adapter->failNextWrites(new TransientStorageFailure('connection reset', 3));

        try {
            $adapter->writePart($session, 1, PartBody::fromString('abcd'));
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertSame(3, $e->retryAfterSeconds);
        }
        self::assertSame([], $adapter->listCompletedParts($session));

        $adapter->writePart($session, 1, PartBody::fromString('abcd'));
        self::assertCount(1, $adapter->listCompletedParts($session));
    }

    public function testAnExpiredSessionIsReportedAsNotFound(): void
    {
        $adapter = new InMemoryStorageAdapter();
        $session = $adapter->begin(new UploadRequest($this->newKey(), 4, 4));
        $adapter->expireSession($session);

        $this->expectException(UploadSessionNotFound::class);
        $adapter->listCompletedParts($session);
    }

    public function testBackendPartLimitsAreEnforcedLikeS3(): void
    {
        $adapter = new InMemoryStorageAdapter(new StorageCapabilities('s3-like', 5 * 1048576, 5 * 1073741824, 10000, true, null, false, false));

        $this->expectException(PartRejected::class);
        $adapter->begin(new UploadRequest($this->newKey(), 3 * 1048576, 1048576));
    }

    public function testTooManyPartsAreRejectedBeforeAnyUpload(): void
    {
        $adapter = new InMemoryStorageAdapter(new StorageCapabilities('tiny', 1, 1024, 3, true, null, true, true));

        $this->expectException(PartRejected::class);
        $adapter->begin(new UploadRequest($this->newKey(), 4, 1));
    }

    public function testCompletingClosesTheSession(): void
    {
        $adapter = new InMemoryStorageAdapter();
        $session = $adapter->begin(new UploadRequest($this->newKey(), 4, 4));
        $adapter->complete($session, [$adapter->writePart($session, 1, PartBody::fromString('abcd'))]);

        self::assertSame(0, $adapter->openSessionCount());
        self::assertSame([$session->key->value], $adapter->objectKeys());
    }

    protected function createAdapter(): ArchiveStorageAdapter
    {
        return new InMemoryStorageAdapter();
    }
}
