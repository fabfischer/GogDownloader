<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\QuotaExceeded;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use App\Tests\Integration\FakeGog\PatternData;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TempFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Nextcloud behaviour beyond the shared storage contract, against both test Nextclouds (local storage, and object
 * storage with a distributed cache): names that need encoding, wrong credentials, a full quota, expired and lost
 * uploads, cancelling, a MOVE whose answer got lost, proxy failures, a destination folder deleted mid-upload, an
 * existing file that must stay intact, and no temporary files on the way up or down.
 */
#[CoversClass(NextcloudStorageAdapter::class)]
final class NextcloudStorageAdapterTest extends IntegrationTestCase
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
    public function testNamesThatNeedEncodingLandWhereExpected(string $server): void
    {
        $prefix = 'itest-names-' . self::runId() . '/Archiv Ü';
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, $prefix));
        $key = ObjectKey::fromString('objects/games/7-spiel/Spiel Ü #1 100% (v2) & más.bin');

        $this->store($adapter, $key, 'hello names');

        $response = NextcloudTestServer::client($server)->request('GET', NextcloudTestServer::filesUrl($server, $prefix . '/' . $key->value));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello names', $response->getContent());
        self::assertSame(11, $adapter->stat($key)?->size);
    }

    #[DataProvider('servers')]
    public function testWrongCredentialsAreDenied(string $server): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest', password: 'wrong-app-password'));

        $this->expectException(AccessDenied::class);
        $adapter->begin(new UploadRequest(self::newRevisionKey(), 4, 4));
    }

    #[DataProvider('servers')]
    public function testAFullQuotaIsReported(string $server): void
    {
        // The quota user has 1 MB. Nextcloud refuses the first chunk when the announced total does not fit.
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-quota', quotaUser: true));

        $this->expectException(QuotaExceeded::class);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));

        try {
            $adapter->writePart($session, 1, PartBody::fromString(str_repeat('q', 5 * self::MIB)));
        } finally {
            $adapter->abort($session);
        }
    }

    /**
     * Nextcloud's cleanup job deletes an upload folder once nothing in it changed for a day (cache_chunk_gc_ttl). The
     * test deletes it the same way, through WebDAV, instead of waiting.
     */
    #[DataProvider('servers')]
    public function testAnExpiredUploadHasToStartAgain(string $server): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-expiry'));
        $data = new PatternData('nc-expiry', 6 * self::MIB);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = $adapter->writePart($session, 1, PartBody::fromString($data->slice(0, 5 * self::MIB)));

        $this->deleteUploadFolder($server, $session);

        $this->assertGone(fn () => $adapter->listCompletedParts($session));
        $this->assertGone(fn () => $adapter->writePart($session, 2, PartBody::fromString($data->slice(5 * self::MIB, self::MIB))));
        $this->assertGone(fn () => $adapter->complete($session, [$first, new UploadedPart(2, 5 * self::MIB, self::MIB, '00002')]));
        $adapter->abort($session);
        self::assertNull($adapter->stat($session->key));
    }

    /**
     * With object storage, Nextcloud keeps each chunked upload's state in its distributed cache. When that entry
     * expires or the cache restarts, the chunks are no longer listed and new ones are refused.
     */
    public function testAnUploadWhoseStateTheCacheLostHasToStartAgain(): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings(NextcloudTestServer::OBJECTSTORE, 'itest-cache'));
        $data = new PatternData('nc-cache', 6 * self::MIB);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = $adapter->writePart($session, 1, PartBody::fromString($data->slice(0, 5 * self::MIB)));
        self::assertCount(1, $adapter->listCompletedParts($session));

        NextcloudTestServer::flushCache();

        self::assertSame([], $adapter->listCompletedParts($session), 'The chunk is no longer listed, so a resume starts again');
        $this->assertGone(fn () => $adapter->writePart($session, 2, PartBody::fromString($data->slice(5 * self::MIB, self::MIB))));

        try {
            $adapter->complete($session, [$first, new UploadedPart(2, 5 * self::MIB, self::MIB, '00002')]);
            self::fail('Expected IncompleteUpload');
        } catch (IncompleteUpload) {
        }
        $adapter->abort($session);
        self::assertNull($adapter->stat($session->key));
    }

    /**
     * The part list is complete, but the server lacks a chunk. With object storage Nextcloud would move whatever chunks
     * it has into place, so the adapter checks the upload folder first.
     */
    #[DataProvider('servers')]
    public function testCompletingIsRefusedWhenTheServerLacksAChunk(string $server): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-lacking'));
        $data = new PatternData('nc-lacking', 6 * self::MIB);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = $adapter->writePart($session, 1, PartBody::fromString($data->slice(0, 5 * self::MIB)));

        try {
            $adapter->complete($session, [$first, new UploadedPart(2, 5 * self::MIB, self::MIB, '00002')]);
            self::fail('Expected IncompleteUpload');
        } catch (IncompleteUpload) {
        }

        self::assertNull($adapter->stat($session->key));
        $adapter->abort($session);
    }

    #[DataProvider('servers')]
    public function testCancellingDropsTheUploadFolderAndKeepsTheKeyFree(string $server): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-cancel'));
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $adapter->writePart($session, 1, PartBody::fromString(str_repeat('c', 5 * self::MIB)));

        $adapter->abort($session);

        self::assertSame(404, NextcloudTestServer::client($server)->request('PROPFIND', NextcloudTestServer::uploadUrl($server, $session->sessionId), ['headers' => ['Depth' => '0']])->getStatusCode());
        self::assertNull($adapter->stat($session->key));
    }

    #[DataProvider('servers')]
    public function testACompleteWhoseAnswerGotLostCanBeRepeated(string $server): void
    {
        $faults = new FaultInjectingHttpClient();
        $faults->failOn('MOVE', 1, FaultInjectingHttpClient::ANSWER_LOST);
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-lost'), $faults);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 1024, 1024));
        $part = $adapter->writePart($session, 1, PartBody::fromString(str_repeat('l', 1024)));

        try {
            $adapter->complete($session, [$part]);
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure) {
        }
        self::assertSame(1024, $adapter->stat($session->key)?->size, 'Nextcloud assembled the file although the client saw a failure');

        $stored = $adapter->complete($session, [$part]);

        self::assertSame(1024, $stored->size);
        self::assertSame(str_repeat('l', 1024), implode('', iterator_to_array($adapter->openRead($session->key), false)));
    }

    #[DataProvider('servers')]
    public function testProxyFailuresAreTemporaryAndThePartGoesThroughOnTheNextTry(string $server): void
    {
        $faults = new FaultInjectingHttpClient();
        $faults->failOn('PUT', 1, 502);
        $faults->failOn('PUT', 2, 504);
        $faults->failOn('PUT', 3, FaultInjectingHttpClient::CONNECTION_LOST);
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-proxy'), $faults);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 2048, 2048));
        $body = PartBody::fromString(str_repeat('p', 2048));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $adapter->writePart($session, 1, $body);
                self::fail('Expected TransientStorageFailure');
            } catch (TransientStorageFailure) {
            }
        }
        $adapter->complete($session, [$adapter->writePart($session, 1, $body)]);

        self::assertSame(4, $faults->count('PUT'));
        self::assertSame(str_repeat('p', 2048), implode('', iterator_to_array($adapter->openRead($session->key), false)));
    }

    #[DataProvider('servers')]
    public function testAProxyThatRefusesLargeRequestsAsksForSmallerParts(string $server): void
    {
        $faults = new FaultInjectingHttpClient();
        $faults->failOn('PUT', 1, 413);
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-proxy'), $faults);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 1024, 1024));

        try {
            $adapter->writePart($session, 1, PartBody::fromString(str_repeat('b', 1024)));
            self::fail('Expected PartRejected');
        } catch (PartRejected $e) {
            self::assertStringContainsString('lower the maximum part size', $e->getMessage());
        } finally {
            $adapter->abort($session);
        }
    }

    #[DataProvider('servers')]
    public function testADestinationFolderDeletedDuringTheUploadIsCreatedAgain(string $server): void
    {
        $prefix = 'itest-vanish-' . self::runId();
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, $prefix));
        $data = new PatternData('nc-vanish', 6 * self::MIB);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 6 * self::MIB, 5 * self::MIB));
        $first = $adapter->writePart($session, 1, PartBody::fromString($data->slice(0, 5 * self::MIB)));

        // Someone deletes the archive folder in the Nextcloud web interface.
        self::assertSame(204, NextcloudTestServer::client($server)->request('DELETE', NextcloudTestServer::filesUrl($server, $prefix))->getStatusCode());

        $second = PartBody::fromString($data->slice(5 * self::MIB, self::MIB));

        try {
            $adapter->writePart($session, 2, $second);
            self::fail('Expected TransientStorageFailure');
        } catch (TransientStorageFailure $e) {
            self::assertStringContainsString('created again', $e->getMessage());
        }
        $adapter->complete($session, [$first, $adapter->writePart($session, 2, $second)]);

        self::assertSame($data->hash('sha256'), $this->sha256Of($adapter, $session->key));
    }

    /**
     * With object storage, an upload aimed at an existing file changes that file's recorded size, even if the final
     * MOVE is refused. So begin() looks first and never opens an upload for a key that is taken.
     */
    #[DataProvider('servers')]
    public function testAnExistingFileStaysIntactWhenTheSameKeyIsUploadedAgain(string $server): void
    {
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-intact'));
        $key = self::newRevisionKey();
        $original = (new PatternData('nc-intact', 5 * self::MIB))->slice(0, 5 * self::MIB);
        $this->store($adapter, $key, $original);

        try {
            $adapter->begin(new UploadRequest($key, 5 * self::MIB, 5 * self::MIB));
            self::fail('Expected ObjectAlreadyExists');
        } catch (ObjectAlreadyExists) {
        }

        self::assertSame(5 * self::MIB, $adapter->stat($key)?->size);
        self::assertSame(hash('sha256', $original), $this->sha256Of($adapter, $key));
    }

    #[DataProvider('servers')]
    public function testA16MiBPartIsSentWithoutTemporaryFilesOrACopy(string $server): void
    {
        if (!TempFiles::canInspect()) {
            self::markTestSkipped('Needs /proc to look at open files.');
        }
        $faults = new FaultInjectingHttpClient();
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-memory'), $faults);
        $session = $adapter->begin(new UploadRequest(self::newRevisionKey(), 16 * self::MIB, 16 * self::MIB));
        $body = $this->memoryBody(new PatternData('nc-memory', 16 * self::MIB));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $adapter->writePart($session, 1, $body);
        $extra = memory_get_peak_usage() - $before;
        $adapter->abort($session);

        self::assertSame(0, $faults->maxTempBytes, 'No temporary file while the part is sent');
        self::assertLessThan(4 * self::MIB, $extra, sprintf('Sending added %.1f MiB on top of the 16 MiB part', $extra / self::MIB));
    }

    #[DataProvider('servers')]
    public function testReadingBackStreamsWithoutTemporaryFiles(string $server): void
    {
        if (!TempFiles::canInspect()) {
            self::markTestSkipped('Needs /proc to look at open files.');
        }
        $data = new PatternData('nc-read', 12 * self::MIB);
        $adapter = NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, 'itest-read'));
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
        // Symfony's client takes in whatever the socket holds when it reads, so the peak varies with the network; the
        // kernel's receive buffer caps it (6 MiB at most on the test container), whatever the file size.
        self::assertLessThan(8 * self::MIB, $extra, sprintf('Reading 12 MiB in 1 MiB chunks held %.1f MiB', $extra / self::MIB));
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertGone(callable $call): void
    {
        try {
            $call();
            self::fail('Expected UploadSessionNotFound');
        } catch (UploadSessionNotFound) {
            $this->addToAssertionCount(1);
        }
    }

    private function deleteUploadFolder(string $server, UploadSession $session): void
    {
        self::assertSame(204, NextcloudTestServer::client($server)->request('DELETE', NextcloudTestServer::uploadUrl($server, $session->sessionId))->getStatusCode());
    }

    private function store(NextcloudStorageAdapter $adapter, ObjectKey $key, string $bytes): void
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

    private function sha256Of(NextcloudStorageAdapter $adapter, ObjectKey $key): string
    {
        $hash = hash_init('sha256');
        foreach ($adapter->openRead($key) as $chunk) {
            hash_update($hash, $chunk);
        }

        return hash_final($hash);
    }
}
