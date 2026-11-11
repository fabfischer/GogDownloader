<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Storage\ObjectKey;
use App\Tests\Integration\FakeGog\PatternData;
use App\Tests\Integration\IntegrationTestCase;
use App\Transfer\CommitGate;
use App\Transfer\Exception\ResumeImpossible;
use App\Transfer\InMemoryCheckpointStore;
use App\Transfer\RetryPolicy;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\StopRequest;
use App\Transfer\TransferCheckpoint;
use App\Transfer\TransferControl;
use App\Transfer\TransferEngine;
use App\Transfer\TransferRequest;
use App\Transfer\TransferResult;
use App\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Whole transfers from the fake GOG source through the transfer engine into both test Nextclouds: a failure in the
 * middle, pause and resume through the upload folder's listing, cancelling, a duplicate that is aborted instead of
 * moved into place, and an upload that expired between pause and resume.
 */
#[CoversNothing]
final class TransferToNextcloudTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        return NextcloudTestServer::servers();
    }

    #[DataProvider('servers')]
    public function testAFileStreamsFromTheSourceIntoNextcloud(string $server): void
    {
        $size = 40 * self::MIB + 123;
        $data = new PatternData('to-nc', $size);
        $adapter = $this->adapter($server);
        $key = self::newRevisionKey('setup_game_1.0.exe');

        $result = $this->engine()->transfer(new TransferRequest($this->source('to-nc', $size), $adapter, $key, new InMemoryCheckpointStore()));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame($data->hash('sha256'), $result->sha256);
        self::assertSame($data->hash('md5'), $result->md5);
        self::assertSame($size, $adapter->stat($key)?->size);
        self::assertSame($data->hash('sha256'), $this->sha256Of($adapter, $key));
    }

    #[DataProvider('servers')]
    public function testAFailureInTheMiddleIsRetried(string $server): void
    {
        $size = 40 * self::MIB + 1;
        $faults = new FaultInjectingHttpClient();
        $faults->failOn('PUT', 2, 503);
        $adapter = $this->adapter($server, $faults);
        $key = self::newRevisionKey();

        $result = $this->engine()->transfer(new TransferRequest($this->source('nc-retry', $size), $adapter, $key, new InMemoryCheckpointStore()));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame(4, $faults->count('PUT'), 'Three chunks plus one repeat');
        self::assertSame((new PatternData('nc-retry', $size))->hash('sha256'), $this->sha256Of($adapter, $key));
    }

    #[DataProvider('servers')]
    public function testAPausedTransferResumesThroughTheUploadFolderListing(string $server): void
    {
        $size = 40 * self::MIB + 5;
        $key = self::newRevisionKey();
        $paused = $this->pausedAfterTwoParts($server, 'nc-resume', $size, $key);

        // Another worker picks the job up later: a new adapter and the checkpoint as the database returns it.
        $stored = TransferCheckpoint::fromArray(json_decode((string) json_encode($paused->checkpoint?->toArray()), true));
        $faults = new FaultInjectingHttpClient();
        $adapter = $this->adapter($server, $faults);
        $resumed = $this->engine()->transfer(new TransferRequest($this->source('nc-resume', $size), $adapter, $key, new InMemoryCheckpointStore(), resumeFrom: $stored));

        self::assertSame(TransferStatus::Completed, $resumed->status);
        self::assertSame((new PatternData('nc-resume', $size))->hash('sha256'), $resumed->sha256);
        self::assertSame(1, $faults->count('PUT'), 'Only the third part is sent after the resume');
        self::assertSame(0, $faults->count('MKCOL'), 'No new upload folder');
        self::assertSame(1, $faults->count('MOVE'));
        self::assertSame($resumed->sha256, $this->sha256Of($adapter, $key));
    }

    #[DataProvider('servers')]
    public function testAnUploadThatExpiredWhilePausedHasToStartAgain(string $server): void
    {
        $size = 40 * self::MIB;
        $key = self::newRevisionKey();
        $paused = $this->pausedAfterTwoParts($server, 'nc-expired', $size, $key);
        self::assertNotNull($paused->checkpoint);

        // Nextcloud's cleanup job removed the upload folder; with object storage, losing the cache entry has the same
        // effect on the listing.
        if ($server === NextcloudTestServer::OBJECTSTORE) {
            NextcloudTestServer::flushCache();
        } else {
            self::assertSame(204, NextcloudTestServer::client($server)->request('DELETE', NextcloudTestServer::uploadUrl($server, $paused->checkpoint->session->sessionId))->getStatusCode());
        }

        try {
            $this->engine()->transfer(new TransferRequest($this->source('nc-expired', $size), $this->adapter($server), $key, new InMemoryCheckpointStore(), resumeFrom: $paused->checkpoint));
            self::fail('Expected ResumeImpossible');
        } catch (ResumeImpossible) {
        }

        // The job starts again from byte zero with a fresh revision key.
        $fresh = self::newRevisionKey();
        $result = $this->engine()->transfer(new TransferRequest($this->source('nc-expired', $size), $this->adapter($server), $fresh, new InMemoryCheckpointStore()));
        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertNull($this->adapter($server)->stat($key));
    }

    #[DataProvider('servers')]
    public function testCancellingAbortsTheUpload(string $server): void
    {
        $size = 40 * self::MIB;
        $key = self::newRevisionKey();
        $cancelBeforeThirdPart = new class implements TransferControl {
            private int $calls = 0;

            public function stopRequested(): ?StopRequest
            {
                return ++$this->calls === 3 ? StopRequest::Cancel : null;
            }
        };

        $result = $this->engine()->transfer(new TransferRequest($this->source('nc-cancel', $size), $this->adapter($server), $key, new InMemoryCheckpointStore(), control: $cancelBeforeThirdPart));

        self::assertSame(TransferStatus::Stopped, $result->status);
        self::assertNotNull($result->checkpoint);
        self::assertSame(404, NextcloudTestServer::client($server)->request('PROPFIND', NextcloudTestServer::uploadUrl($server, $result->checkpoint->session->sessionId), ['headers' => ['Depth' => '0']])->getStatusCode());
        self::assertNull($this->adapter($server)->stat($key));
    }

    #[DataProvider('servers')]
    public function testADuplicateIsAbortedInsteadOfMovedIntoPlace(string $server): void
    {
        $size = 12 * self::MIB;
        $faults = new FaultInjectingHttpClient();
        $adapter = $this->adapter($server, $faults);
        $key = self::newRevisionKey();
        $alreadyArchived = new class implements CommitGate {
            public function allowCommit(string $sha256, string $md5, int $size): bool
            {
                return false;
            }
        };

        $result = $this->engine()->transfer(new TransferRequest($this->source('nc-duplicate', $size), $adapter, $key, new InMemoryCheckpointStore(), commitGate: $alreadyArchived));

        self::assertSame(TransferStatus::Deduplicated, $result->status);
        self::assertNull($adapter->stat($key));
        self::assertSame(0, $faults->count('MOVE'));
        self::assertSame(1, $faults->count('DELETE'));
    }

    private function pausedAfterTwoParts(string $server, string $seed, int $size, ObjectKey $key): TransferResult
    {
        $pauseBeforeThirdPart = new class implements TransferControl {
            private int $calls = 0;

            public function stopRequested(): ?StopRequest
            {
                return ++$this->calls === 3 ? StopRequest::Pause : null;
            }
        };

        $paused = $this->engine()->transfer(new TransferRequest($this->source($seed, $size), $this->adapter($server), $key, new InMemoryCheckpointStore(), control: $pauseBeforeThirdPart));
        self::assertSame(TransferStatus::Stopped, $paused->status);
        self::assertCount(2, $paused->checkpoint->parts ?? []);

        return $paused;
    }

    private function adapter(string $server, ?FaultInjectingHttpClient $faults = null): NextcloudStorageAdapter
    {
        return NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-transfer'), $faults);
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

    private function sha256Of(NextcloudStorageAdapter $adapter, ObjectKey $key): string
    {
        $hash = hash_init('sha256');
        foreach ($adapter->openRead($key) as $chunk) {
            hash_update($hash, $chunk);
        }

        return hash_final($hash);
    }
}
