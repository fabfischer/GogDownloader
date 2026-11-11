<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\ObjectKey;
use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3StorageAdapter;
use App\Tests\Integration\FakeGog\PatternData;
use App\Tests\Integration\IntegrationTestCase;
use App\Transfer\CommitGate;
use App\Transfer\Exception\RetriesExhausted;
use App\Transfer\InMemoryCheckpointStore;
use App\Transfer\RetryPolicy;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\StopRequest;
use App\Transfer\TransferCheckpoint;
use App\Transfer\TransferControl;
use App\Transfer\TransferEngine;
use App\Transfer\TransferRequest;
use App\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Whole transfers from the fake GOG source through the transfer engine into the S3-compatible test server: storage
 * failures in the middle, pause and resume through ListParts, a duplicate that is aborted instead of completed, and a
 * resume after the retry budget ran out.
 */
#[CoversNothing]
final class TransferToS3Test extends IntegrationTestCase
{
    private const MIB = 1048576;

    public static function setUpBeforeClass(): void
    {
        S3TestServer::ensureBucket();
    }

    /**
     * @return iterable<string, array{S3ChecksumMode, bool}>
     */
    public static function targets(): iterable
    {
        yield 'S3-compatible defaults' => [S3ChecksumMode::Off, false];
        yield 'AWS defaults: CRC32 and conditional create' => [S3ChecksumMode::Crc32, true];
    }

    #[DataProvider('targets')]
    public function testAFileStreamsFromTheSourceIntoS3(S3ChecksumMode $checksums, bool $conditionalCreate): void
    {
        $size = 40 * self::MIB + 123;
        $data = new PatternData('to-s3', $size);
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-transfer', $checksums, $conditionalCreate));
        $key = self::newRevisionKey('setup_game_1.0.exe');

        $result = $this->engine()->transfer(new TransferRequest($this->source('to-s3', $size), $adapter, $key, new InMemoryCheckpointStore()));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame($data->hash('sha256'), $result->sha256);
        self::assertSame($data->hash('md5'), $result->md5);
        self::assertSame($size, $adapter->stat($key)?->size);
        self::assertSame($data->hash('sha256'), $this->sha256Of($adapter, $key));
    }

    public function testAStorageFailureInTheMiddleIsRetried(): void
    {
        $size = 40 * self::MIB + 1;
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('UploadPart', 2, FaultInjectingHttpHandler::errorResponse(500, 'InternalError'));
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-transfer'), ['http_handler' => $faults]);
        $key = self::newRevisionKey();

        $result = $this->engine()->transfer(new TransferRequest($this->source('s3-retry', $size), $adapter, $key, new InMemoryCheckpointStore()));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame(4, $faults->count('UploadPart'), 'Three parts plus one repeat');
        self::assertSame((new PatternData('s3-retry', $size))->hash('sha256'), $this->sha256Of($adapter, $key));
    }

    public function testAPausedTransferResumesThroughListParts(): void
    {
        $size = 40 * self::MIB + 5;
        $key = self::newRevisionKey();
        $settings = S3TestServer::settings('itest-transfer', S3ChecksumMode::Crc32);
        $pauseBeforeThirdPart = new class implements TransferControl {
            private int $calls = 0;

            public function stopRequested(): ?StopRequest
            {
                return ++$this->calls === 3 ? StopRequest::Pause : null;
            }
        };

        $paused = $this->engine()->transfer(new TransferRequest($this->source('s3-resume', $size), S3StorageAdapter::create($settings), $key, new InMemoryCheckpointStore(), control: $pauseBeforeThirdPart));
        self::assertSame(TransferStatus::Stopped, $paused->status);
        self::assertNotNull($paused->checkpoint);
        self::assertCount(2, $paused->checkpoint->parts);

        // Another worker picks the job up later: a new adapter and the checkpoint as the database returns it.
        $stored = TransferCheckpoint::fromArray(json_decode((string) json_encode($paused->checkpoint->toArray()), true));
        $faults = new FaultInjectingHttpHandler();
        $adapter = S3StorageAdapter::create($settings, ['http_handler' => $faults]);
        $resumed = $this->engine()->transfer(new TransferRequest($this->source('s3-resume', $size), $adapter, $key, new InMemoryCheckpointStore(), resumeFrom: $stored));

        self::assertSame(TransferStatus::Completed, $resumed->status);
        self::assertSame((new PatternData('s3-resume', $size))->hash('sha256'), $resumed->sha256);
        self::assertSame(1, $faults->count('ListParts'));
        self::assertSame(0, $faults->count('CreateMultipartUpload'));
        self::assertSame(1, $faults->count('UploadPart'), 'Only the third part is sent after the resume');
        self::assertSame($resumed->sha256, $this->sha256Of($adapter, $key));
    }

    public function testADuplicateIsAbortedInsteadOfCompleted(): void
    {
        $size = 12 * self::MIB;
        $faults = new FaultInjectingHttpHandler();
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-transfer'), ['http_handler' => $faults]);
        $key = self::newRevisionKey();
        $alreadyArchived = new class implements CommitGate {
            public ?string $seenSha256 = null;

            public function allowCommit(string $sha256, string $md5, int $size): bool
            {
                $this->seenSha256 = $sha256;

                return false;
            }
        };

        $result = $this->engine()->transfer(new TransferRequest($this->source('s3-duplicate', $size), $adapter, $key, new InMemoryCheckpointStore(), commitGate: $alreadyArchived));

        self::assertSame(TransferStatus::Deduplicated, $result->status);
        self::assertSame((new PatternData('s3-duplicate', $size))->hash('sha256'), $alreadyArchived->seenSha256);
        self::assertNull($adapter->stat($key));
        self::assertSame(0, $faults->count('CompleteMultipartUpload'));
        self::assertSame(1, $faults->count('AbortMultipartUpload'));
        self::assertNotNull($result->checkpoint);
        $this->expectException(UploadSessionNotFound::class);
        $adapter->listCompletedParts($result->checkpoint->session);
    }

    public function testExhaustedRetriesLeaveTheUploadForALaterResume(): void
    {
        $size = 40 * self::MIB;
        $key = self::newRevisionKey();
        $faults = new FaultInjectingHttpHandler();
        $faults->failOn('UploadPart', 2, FaultInjectingHttpHandler::connectionLost());
        $faults->failOn('UploadPart', 3, FaultInjectingHttpHandler::connectionLost());
        $checkpoints = new InMemoryCheckpointStore();
        $twoAttempts = new TransferEngine(retry: new RetryPolicy(2, 0.0, 0.0), sleep: static function (float $seconds): void {
        });

        try {
            $twoAttempts->transfer(new TransferRequest($this->source('s3-exhausted', $size), S3StorageAdapter::create(S3TestServer::settings('itest-transfer'), ['http_handler' => $faults]), $key, $checkpoints));
            self::fail('Expected RetriesExhausted');
        } catch (RetriesExhausted) {
        }
        $last = $checkpoints->last();
        self::assertNotNull($last);
        self::assertCount(1, $last->parts);

        // Later, with the target reachable again, the job resumes from its last checkpoint.
        $adapter = S3StorageAdapter::create(S3TestServer::settings('itest-transfer'));
        $resumed = $this->engine()->transfer(new TransferRequest($this->source('s3-exhausted', $size), $adapter, $key, new InMemoryCheckpointStore(), resumeFrom: $last));

        self::assertSame(TransferStatus::Completed, $resumed->status);
        self::assertSame((new PatternData('s3-exhausted', $size))->hash('sha256'), $this->sha256Of($adapter, $key));
    }

    private function engine(): TransferEngine
    {
        return new TransferEngine(retry: new RetryPolicy(3, 0.0, 0.0), sleep: static function (float $seconds): void {
        });
    }

    private function source(string $seed, int $size): HttpRangeSource
    {
        $url = self::fakeGogFileUrl($seed, $size);

        return new HttpRangeSource(HttpClient::create(), static fn (): string => $url, self::fakeGogHosts());
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
