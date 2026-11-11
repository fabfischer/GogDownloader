<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3StorageAdapter;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use App\Tests\Integration\FakeGog\PatternData;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TempFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * S3 behaviour beyond the shared storage contract, against the S3-compatible test server: prefix, access errors,
 * injected failures, a Complete whose response got lost, a checksum setting changed mid-upload, and no temporary
 * files on the way up or down.
 */
#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    public static function setUpBeforeClass(): void
    {
        S3TestServer::ensureBucket();
    }

    public function testObjectsLandUnderThePrefix(): void
    {
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-prefix/nested'));
        $key = self::newRevisionKey();

        $this->store($adapter, $key, 'hello prefix');

        $head = S3TestServer::client()->headObject(['Bucket' => S3TestServer::BUCKET, 'Key' => 'itest-prefix/nested/' . $key->value]);
        self::assertSame(12, (int) $head['ContentLength']);
    }

    public function testWrongCredentialsAreDenied(): void
    {
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest', secret: 'wrong-secret'));

        $this->expectException(AccessDenied::class);
        $adapter->begin(new UploadRequest(self::newRevisionKey(), 4, 4));
    }

    public function testAMissingBucketIsDenied(): void
    {
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest', bucket: 'missing-' . self::runId()));

        $this->expectException(AccessDenied::class);
        $adapter->begin(new UploadRequest(self::newRevisionKey(), 4, 4));
    }

    public function testAServerErrorIsTemporaryAndThePartGoesThroughOnTheNextTry(): void
    {
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('UploadPart', 1, FaultInjectingHttpHandler::errorResponse(503, 'SlowDown', ['Retry-After' => '1']));
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-faults'), ['http_handler' => $faults]);
        $data = new PatternData('s3-slowdown', 6 * self::MIB);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = PartBody::fromString($data->slice(0, 5 * self::MIB));

        try {
            $adapter->writePart($session, 1, $first);
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertSame(1, $e->retryAfterSeconds);
        }
        $parts = [$adapter->writePart($session, 1, $first), $adapter->writePart($session, 2, PartBody::fromString($data->slice(5 * self::MIB, self::MIB)))];
        $adapter->complete($session, $parts);

        self::assertSame(3, $faults->count('UploadPart'));
        self::assertSame($data->hash('sha256'), $this->sha256Of($adapter, $session->key));
    }

    public function testADroppedConnectionIsTemporary(): void
    {
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('UploadPart', 1, FaultInjectingHttpHandler::connectionLost());
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-faults'), ['http_handler' => $faults]);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 1024, 1024));

        try {
            $adapter->writePart($session, 1, PartBody::fromString(str_repeat('c', 1024)));
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertStringContainsString('connection failed', $e->getMessage());
        }
        $adapter->abort($session);
    }

    public function testAResetWhileSendingToAGoneUploadIsReportedAsGone(): void
    {
        // Without "100 Continue" a server may refuse a part early and reset the connection mid-body; the adapter asks
        // the server what is wrong instead of reporting a network problem that a retry cannot fix.
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('UploadPart', 1, FaultInjectingHttpHandler::connectionLost());
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-faults'), ['http_handler' => $faults]);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 1024, 1024));
        $adapter->abort($session);

        try {
            $adapter->writePart($session, 1, PartBody::fromString(str_repeat('g', 1024)));
            self::fail('Expected UploadSessionNotFound');
        } catch (UploadSessionNotFound) {
        }
        self::assertSame(['UploadPart', 'ListParts'], array_slice($faults->operations, -2));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function overwriteGuards(): iterable
    {
        yield 'conditional create' => [true];
        yield 'key check before Complete' => [false];
    }

    #[DataProvider('overwriteGuards')]
    public function testACompleteWhoseResponseGotLostCanBeRepeated(bool $conditionalCreate): void
    {
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('CompleteMultipartUpload', 1, $faults->responseLost());
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-lost', conditionalCreate: $conditionalCreate), ['http_handler' => $faults]);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 1024, 1024));
        $part = $adapter->writePart($session, 1, PartBody::fromString(str_repeat('l', 1024)));

        try {
            $adapter->complete($session, [$part]);
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure) {
        }
        self::assertSame(1024, $adapter->stat($session->key)?->size, 'The server completed the upload although the client saw a failure');

        $stored = $adapter->complete($session, [$part]);

        self::assertSame(1024, $stored->size);
        self::assertSame(str_repeat('l', 1024), implode('', iterator_to_array($adapter->openRead($session->key), false)));
    }

    public function testAnUploadKeepsItsChecksumModeWhenTheSettingChanges(): void
    {
        $data = new PatternData('s3-mode', 6 * self::MIB);
        $withChecksums = S3StorageAdapter::create(S3TestServer::settings('itest-mode', S3ChecksumMode::Crc32));
        $session = $withChecksums->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = $withChecksums->writePart($session, 1, PartBody::fromString($data->slice(0, 5 * self::MIB)));

        // The owner turns checksums off and the worker restarts with the new settings and the stored session.
        $withoutChecksums = S3StorageAdapter::create(S3TestServer::settings('itest-mode'));
        $restored = UploadSession::fromArray(json_decode((string) json_encode($session->toArray()), true));
        $second = $withoutChecksums->writePart($restored, 2, PartBody::fromString($data->slice(5 * self::MIB, self::MIB)));
        $withoutChecksums->complete($restored, [$first, $second]);

        self::assertNotNull($second->checksum);
        self::assertSame($data->hash('sha256'), $this->sha256Of($withoutChecksums, $restored->key));
    }

    /**
     * @return iterable<string, array{S3ChecksumMode}>
     */
    public static function checksumModes(): iterable
    {
        yield 'no checksums' => [S3ChecksumMode::Off];
        yield 'CRC32' => [S3ChecksumMode::Crc32];
    }

    #[DataProvider('checksumModes')]
    public function testA16MiBPartIsSentWithoutTemporaryFilesOrACopy(S3ChecksumMode $mode): void
    {
        if (!TempFiles::canInspect()) {
            self::markTestSkipped('Needs /proc to look at open files.');
        }
        $faults = new FaultInjectingHttpHandler();
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-memory', $mode), ['http_handler' => $faults]);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 16 * self::MIB, 16 * self::MIB));
        $body = $this->memoryBody(new PatternData('s3-memory', 16 * self::MIB));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $adapter->writePart($session, 1, $body);
        $extra = memory_get_peak_usage() - $before;
        $adapter->abort($session);

        self::assertSame(0, $faults->maxTempBytes, 'No temporary file while the part is sent');
        self::assertLessThan(4 * self::MIB, $extra, sprintf('Sending added %.1f MiB on top of the 16 MiB part', $extra / self::MIB));
    }

    public function testReadingBackStreamsWithoutTemporaryFiles(): void
    {
        if (!TempFiles::canInspect()) {
            self::markTestSkipped('Needs /proc to look at open files.');
        }
        $data = new PatternData('s3-read', 12 * self::MIB);
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-read'));
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 12 * self::MIB, 12 * self::MIB));
        $adapter->complete($session, [$adapter->writePart($session, 1, $this->memoryBody($data))]);

        $hash = hash_init('sha256');
        $maxTemp = 0;
        memory_reset_peak_usage();
        $before = memory_get_usage();
        foreach ($adapter->openRead($session->key, self::MIB) as $chunk) {
            hash_update($hash, $chunk);
            $maxTemp = max($maxTemp, TempFiles::bytesHeld());
        }
        $extra = memory_get_peak_usage() - $before;

        self::assertSame($data->hash('sha256'), hash_final($hash));
        self::assertSame(0, $maxTemp);
        self::assertLessThan(4 * self::MIB, $extra, sprintf('Reading 12 MiB in 1 MiB chunks held %.1f MiB', $extra / self::MIB));
    }

    private function store(S3StorageAdapter $adapter, ObjectKey $key, string $bytes): void
    {
        $session = $adapter->begin(new UploadRequest($key, strlen($bytes), max(strlen($bytes), 5 * self::MIB)));
        $adapter->complete($session, [$adapter->writePart($session, 1, PartBody::fromString($bytes))]);
    }

    /**
     * The whole pattern in a php://memory stream, filled piece by piece like the transfer engine fills a part.
     */
    private function memoryBody(PatternData $data): PartBody
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        foreach ($data->stream(0, $data->size) as $piece) {
            fwrite($stream, $piece);
        }

        return PartBody::fromStream($stream, $data->size);
    }

    private function sha256Of(S3StorageAdapter $adapter, ObjectKey $key): string
    {
        $hash = hash_init('sha256');
        foreach ($adapter->openRead($key) as $chunk) {
            hash_update($hash, $chunk);
        }

        return hash_final($hash);
    }
}
