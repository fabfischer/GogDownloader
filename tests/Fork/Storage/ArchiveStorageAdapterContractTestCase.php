<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\ObjectKey;
use App\Storage\ObjectKeyBuilder;
use App\Storage\PartBody;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every ArchiveStorageAdapter must show. Extend it once per adapter: the in-memory fake here, the S3 and
 * Nextcloud adapters in their integration tests. Sizes follow the adapter's minimum part size, so the same tests
 * work against backends with a 5 MiB minimum.
 */
abstract class ArchiveStorageAdapterContractTestCase extends TestCase
{
    protected ArchiveStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createAdapter();
    }

    public function testPartsCompleteIntoExactlyTheUploadedBytes(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes(2 * $partSize + intdiv($partSize, 3));
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize, 'application/octet-stream'));
        self::assertSame(3, $session->partCount());

        $parts = $this->uploadAll($session, $bytes);
        $stored = $this->adapter->complete($session, $parts);

        self::assertTrue($stored->key->equals($session->key));
        self::assertSame(strlen($bytes), $stored->size);
        self::assertSame(strlen($bytes), $this->adapter->stat($session->key)?->size);
        self::assertSame(hash('sha256', $bytes), hash('sha256', implode('', iterator_to_array($this->adapter->openRead($session->key), false))));
    }

    public function testAcknowledgedPartsCanBeListedForReconciliation(): void
    {
        if (!$this->adapter->capabilities()->canListParts) {
            self::markTestSkipped('The backend cannot list the parts of an unfinished upload.');
        }
        $partSize = $this->partSize();
        $bytes = $this->bytes(3 * $partSize);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize));

        $written = [
            $this->adapter->writePart($session, 2, PartBody::fromString(substr($bytes, $partSize, $partSize))),
            $this->adapter->writePart($session, 1, PartBody::fromString(substr($bytes, 0, $partSize))),
        ];

        $listed = $this->adapter->listCompletedParts($session);
        self::assertSame([1, 2], array_map(static fn (UploadedPart $part): int => $part->number, $listed));
        self::assertSame([0, $partSize], array_map(static fn (UploadedPart $part): int => $part->offset, $listed));
        self::assertSame($written[1]->remoteId, $listed[0]->remoteId);
        $this->adapter->abort($session);
    }

    public function testWritingAPartAgainReplacesIt(): void
    {
        $partSize = $this->partSize();
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), $partSize, $partSize));

        $this->adapter->writePart($session, 1, PartBody::fromString(str_repeat('a', $partSize)));
        $second = $this->adapter->writePart($session, 1, PartBody::fromString(str_repeat('b', $partSize)));
        $this->adapter->complete($session, [$second]);

        self::assertSame(str_repeat('b', $partSize), implode('', iterator_to_array($this->adapter->openRead($session->key), false)));
    }

    public function testCompletingWithAMissingPartFailsAndKeepsTheKeyFree(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes(2 * $partSize);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize));
        $first = $this->adapter->writePart($session, 1, PartBody::fromString(substr($bytes, 0, $partSize)));

        try {
            $this->adapter->complete($session, [$first]);
            self::fail('Expected IncompleteUpload');
        } catch (IncompleteUpload) {
        }

        self::assertNull($this->adapter->stat($session->key));
        $this->adapter->abort($session);
    }

    public function testAPartWithTheWrongLengthIsRejected(): void
    {
        $partSize = $this->partSize();
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), 2 * $partSize, $partSize));

        $this->expectException(PartRejected::class);

        try {
            $this->adapter->writePart($session, 1, PartBody::fromString(str_repeat('x', $partSize - 1)));
        } finally {
            $this->adapter->abort($session);
        }
    }

    public function testAnExistingObjectIsNeverOverwritten(): void
    {
        $partSize = $this->partSize();
        $key = $this->newKey();
        $original = $this->bytes($partSize);
        $first = $this->adapter->begin(new UploadRequest($key, $partSize, $partSize));
        $this->adapter->complete($first, [$this->adapter->writePart($first, 1, PartBody::fromString($original))]);

        try {
            $second = $this->adapter->begin(new UploadRequest($key, $partSize, $partSize));
            $part = $this->adapter->writePart($second, 1, PartBody::fromString(str_repeat('z', $partSize)));
            $this->adapter->complete($second, [$part]);
            self::fail('Expected ObjectAlreadyExists');
        } catch (ObjectAlreadyExists) {
        }

        self::assertSame($original, implode('', iterator_to_array($this->adapter->openRead($key), false)));
    }

    public function testCompletingAgainAfterSuccessReturnsTheStoredObject(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes(2 * $partSize + 7);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize));
        $parts = $this->uploadAll($session, $bytes);
        $this->adapter->complete($session, $parts);

        // The answer to the first call got lost on the way back, so the caller repeats it.
        $again = $this->adapter->complete($session, $parts);

        self::assertTrue($again->key->equals($session->key));
        self::assertSame(strlen($bytes), $again->size);
        self::assertSame(hash('sha256', $bytes), hash('sha256', implode('', iterator_to_array($this->adapter->openRead($session->key), false))));
    }

    public function testCompletingAGoneSessionWithNothingAtTheKeyFails(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes($partSize + 3);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize));
        $parts = $this->uploadAll($session, $bytes);
        $this->adapter->abort($session);

        try {
            $this->adapter->complete($session, $parts);
            self::fail('Expected UploadSessionNotFound');
        } catch (UploadSessionNotFound) {
        }

        self::assertNull($this->adapter->stat($session->key));
    }

    public function testAbortDropsTheSessionAndCanBeRepeated(): void
    {
        $partSize = $this->partSize();
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), $partSize, $partSize));
        $this->adapter->abort($session);
        $this->adapter->abort($session);

        $this->expectException(UploadSessionNotFound::class);
        $this->adapter->writePart($session, 1, PartBody::fromString(str_repeat('x', $partSize)));
    }

    public function testASessionResumesFromItsSerializedForm(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes(2 * $partSize + 1);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), strlen($bytes), $partSize));
        $first = $this->adapter->writePart($session, 1, PartBody::fromString(substr($bytes, 0, $partSize)));

        // What a checkpoint stores, read back by another process.
        $restored = UploadSession::fromArray(json_decode((string) json_encode($session->toArray()), true));
        $firstRestored = UploadedPart::fromArray(json_decode((string) json_encode($first->toArray()), true));

        $parts = [$firstRestored];
        for ($number = 2; $number <= $restored->partCount(); ++$number) {
            $parts[] = $this->adapter->writePart($restored, $number, PartBody::fromString(substr($bytes, $restored->offsetOf($number), $restored->lengthOf($number))));
        }
        $this->adapter->complete($restored, $parts);

        self::assertSame(strlen($bytes), $this->adapter->stat($restored->key)?->size);
    }

    public function testStatAndDelete(): void
    {
        $partSize = $this->partSize();
        $key = $this->newKey();
        self::assertNull($this->adapter->stat($key));

        $session = $this->adapter->begin(new UploadRequest($key, $partSize, $partSize));
        $this->adapter->complete($session, [$this->adapter->writePart($session, 1, PartBody::fromString($this->bytes($partSize)))]);
        self::assertNotNull($this->adapter->stat($key));

        $this->adapter->delete($key);
        self::assertNull($this->adapter->stat($key));
    }

    public function testOpenReadYieldsBoundedChunks(): void
    {
        $partSize = $this->partSize();
        $bytes = $this->bytes($partSize);
        $session = $this->adapter->begin(new UploadRequest($this->newKey(), $partSize, $partSize));
        $this->adapter->complete($session, [$this->adapter->writePart($session, 1, PartBody::fromString($bytes))]);

        $chunkSize = max(1, intdiv($partSize, 3));
        $chunks = iterator_to_array($this->adapter->openRead($session->key, $chunkSize), false);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual($chunkSize, strlen($chunk));
        }
        self::assertSame($bytes, implode('', $chunks));
    }

    abstract protected function createAdapter(): ArchiveStorageAdapter;

    /**
     * Part size used by the contract tests: the backend's minimum, but at least 1 KiB.
     */
    protected function partSize(): int
    {
        return max(1024, $this->adapter->capabilities()->minPartSize);
    }

    protected function newKey(): ObjectKey
    {
        $uuid = static fn (): string => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

        return (new ObjectKeyBuilder())->revisionKey(1, 'Contract test', $uuid(), $uuid(), 'part-test.bin');
    }

    /**
     * Deterministic, non-repeating test bytes, so a part placed at the wrong offset changes the hash.
     */
    protected function bytes(int $length): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $length; ++$i) {
            $out .= hash('sha256', (string) $i, true);
        }

        return substr($out, 0, $length);
    }

    /**
     * @return list<UploadedPart>
     */
    protected function uploadAll(UploadSession $session, string $bytes): array
    {
        $parts = [];
        for ($number = 1; $number <= $session->partCount(); ++$number) {
            $parts[] = $this->adapter->writePart($session, $number, PartBody::fromString(substr($bytes, $session->offsetOf($number), $session->lengthOf($number))));
        }

        return $parts;
    }
}
