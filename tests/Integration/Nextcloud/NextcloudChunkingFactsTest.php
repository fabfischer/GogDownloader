<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the Nextcloud adapter relies on, checked with raw WebDAV requests against both test Nextclouds (Nextcloud
 * 35.0.2). If a Nextcloud update changes one of these, the adapter has to be looked at again.
 */
#[CoversNothing]
final class NextcloudChunkingFactsTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    private string $server;

    private HttpClientInterface $http;

    private string $folder;

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        return NextcloudTestServer::servers();
    }

    #[DataProvider('servers')]
    public function testAChunkIsRefusedWhileTheDestinationFolderIsMissing(string $server): void
    {
        $this->on($server);
        $upload = $this->upload("{$this->folder}/missing/file.bin");

        self::assertSame(404, $this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/missing/file.bin"));
        self::assertSame(409, $this->move($upload, "{$this->folder}/missing/file.bin", 5 * self::MIB));
    }

    #[DataProvider('servers')]
    public function testOverwriteFalseKeepsAnExistingFileAndTheUploadFolder(string $server): void
    {
        $this->on($server);
        $this->storeViaChunks("{$this->folder}/taken.bin", 'o');
        $upload = $this->upload("{$this->folder}/other.bin");
        self::assertContains($this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/other.bin", 'n'), [200, 201]);

        self::assertSame(412, $this->move($upload, "{$this->folder}/taken.bin", 5 * self::MIB));
        self::assertSame(207, $this->statusOf('PROPFIND', $upload, ['Depth' => '0']), 'The upload folder stays');
        self::assertSame(str_repeat('o', 5 * self::MIB), $this->http->request('GET', $this->files("{$this->folder}/taken.bin"))->getContent());
    }

    #[DataProvider('servers')]
    public function testARepeatedMoveMeetsItsOwnFileAndTheUploadFolderIsGone(string $server): void
    {
        $this->on($server);
        $upload = $this->storeViaChunks("{$this->folder}/done.bin", 'd');

        self::assertSame(412, $this->move($upload, "{$this->folder}/done.bin", 5 * self::MIB));
        self::assertSame(404, $this->statusOf('PROPFIND', $upload, ['Depth' => '0']));
    }

    #[DataProvider('servers')]
    public function testUploadFoldersCannotBeListedOrReachedForOtherAccounts(string $server): void
    {
        $this->on($server);
        $base = NextcloudTestServer::url($server) . '/remote.php/dav/uploads/';

        self::assertSame(405, $this->statusOf('PROPFIND', $base . rawurlencode((string) getenv('ITEST_NEXTCLOUD_USER')), ['Depth' => '1']));
        self::assertSame(403, $this->statusOf('MKCOL', $base . rawurlencode((string) getenv('ITEST_NEXTCLOUD_QUOTA_USER')) . '/gog-archive-' . self::runId()));
    }

    public function testWithLocalStorageTheMoveChecksTheTotalSize(): void
    {
        $this->on(NextcloudTestServer::PLAIN);
        $upload = $this->upload("{$this->folder}/short.bin");
        $this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/short.bin", 'a', total: 6 * self::MIB);

        self::assertSame(400, $this->move($upload, "{$this->folder}/short.bin", 6 * self::MIB));
        self::assertSame(207, $this->statusOf('PROPFIND', $upload, ['Depth' => '0']));
    }

    /**
     * So the adapter checks the chunks itself before it moves.
     */
    public function testWithObjectStorageTheMoveAssemblesWhateverChunksExist(): void
    {
        $this->on(NextcloudTestServer::OBJECTSTORE);
        $upload = $this->upload("{$this->folder}/short.bin");
        $this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/short.bin", 'a', total: 6 * self::MIB);

        self::assertSame(201, $this->move($upload, "{$this->folder}/short.bin", 6 * self::MIB));
        self::assertMatchesRegularExpression('#<d:getcontentlength>' . (5 * self::MIB) . '<#', $this->http->request('PROPFIND', $this->files("{$this->folder}/short.bin"), ['headers' => ['Depth' => '0']])->getContent());
    }

    /**
     * So the adapter never opens an upload for a key that is taken.
     */
    public function testWithObjectStorageAnUploadAimedAtAnExistingFileChangesItsRecordedSize(): void
    {
        $this->on(NextcloudTestServer::OBJECTSTORE);
        $this->storeViaChunks("{$this->folder}/existing.bin", 'e');
        $upload = $this->upload("{$this->folder}/existing.bin");
        $this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/existing.bin", 'z');
        self::assertSame(412, $this->move($upload, "{$this->folder}/existing.bin", 5 * self::MIB));
        $this->statusOf('DELETE', $upload);

        self::assertMatchesRegularExpression('#<d:getcontentlength>' . (10 * self::MIB) . '<#', $this->http->request('PROPFIND', $this->files("{$this->folder}/existing.bin"), ['headers' => ['Depth' => '0']])->getContent());
    }

    public function testWithObjectStorageALostCacheEntryEndsTheUpload(): void
    {
        $this->on(NextcloudTestServer::OBJECTSTORE);
        $upload = $this->upload("{$this->folder}/cache.bin");
        $this->chunk($upload, 1, 5 * self::MIB, "{$this->folder}/cache.bin", 'c', total: 10 * self::MIB);

        NextcloudTestServer::flushCache();

        self::assertSame(412, $this->chunk($upload, 2, 5 * self::MIB, "{$this->folder}/cache.bin", 'c', total: 10 * self::MIB));
        self::assertSame(204, $this->statusOf('DELETE', $upload));
    }

    private function on(string $server): void
    {
        $this->server = $server;
        $this->http = NextcloudTestServer::client($server);
        $this->folder = 'itest-facts-' . self::runId();
        self::assertSame(201, $this->statusOf('MKCOL', $this->files($this->folder)));
    }

    private function upload(string $destination): string
    {
        $url = NextcloudTestServer::uploadUrl($this->server, 'facts-' . self::runId());
        self::assertSame(201, $this->statusOf('MKCOL', $url, ['Destination' => $this->files($destination)]));

        return $url;
    }

    /**
     * Uploads one 5 MiB chunk of $byte and moves it to $path; returns the upload folder's URL.
     */
    private function storeViaChunks(string $path, string $byte): string
    {
        $upload = $this->upload($path);
        self::assertContains($this->chunk($upload, 1, 5 * self::MIB, $path, $byte), [200, 201]);
        self::assertSame(201, $this->move($upload, $path, 5 * self::MIB));

        return $upload;
    }

    private function chunk(string $upload, int $number, int $length, string $destination, string $byte = 'x', ?int $total = null): int
    {
        return $this->statusOf('PUT', sprintf('%s/%05d', $upload, $number), [
            'Destination' => $this->files($destination),
            'OC-Total-Length' => (string) ($total ?? $length),
            'Content-Type' => 'application/octet-stream',
        ], str_repeat($byte, $length));
    }

    private function move(string $upload, string $destination, int $total): int
    {
        return $this->statusOf('MOVE', $upload . '/.file', ['Destination' => $this->files($destination), 'OC-Total-Length' => (string) $total, 'Overwrite' => 'F']);
    }

    private function files(string $path): string
    {
        return NextcloudTestServer::filesUrl($this->server, $path);
    }

    /**
     * @param array<string, string> $headers
     */
    private function statusOf(string $method, string $url, array $headers = [], ?string $body = null): int
    {
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['body'] = $body;
        }

        return $this->http->request($method, $url, $options)->getStatusCode();
    }
}
