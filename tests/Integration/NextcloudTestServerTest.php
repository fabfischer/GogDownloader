<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The test Nextcloud supports what the Nextcloud adapter needs: WebDAV chunked upload v2 (MKCOL with Destination,
 * numbered chunk PUTs with OC-Total-Length, MOVE of ".file"), plus the failures it must handle: 507 for a full quota
 * and 401 for wrong credentials.
 */
#[CoversNothing]
final class NextcloudTestServerTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    private HttpClientInterface $http;

    protected function setUp(): void
    {
        $this->http = HttpClient::create(['timeout' => 60]);
    }

    public function testChunkedUploadV2AssemblesTheFile(): void
    {
        $user = self::env('ITEST_NEXTCLOUD_USER');
        $run = self::runId();
        $folder = $this->files($user) . "/itest-{$run}";
        $destination = "{$folder}/setup.bin";
        $session = $this->dav() . "/uploads/{$user}/upload-{$run}";
        $total = 5 * self::MIB + 1024;

        self::assertSame(201, $this->request('MKCOL', $folder));
        self::assertSame(201, $this->request('MKCOL', $session, ['Destination' => $destination]));
        self::assertSame(201, $this->request('PUT', "{$session}/00001", ['Destination' => $destination, 'OC-Total-Length' => (string) $total], str_repeat('a', 5 * self::MIB)));
        self::assertSame(201, $this->request('PUT', "{$session}/00002", ['Destination' => $destination, 'OC-Total-Length' => (string) $total], str_repeat('b', 1024)));
        self::assertContains($this->request('MOVE', "{$session}/.file", ['Destination' => $destination, 'OC-Total-Length' => (string) $total]), [201, 204]);

        $props = $this->http->request('PROPFIND', $destination, $this->options(['Depth' => '0']))->getContent();
        self::assertMatchesRegularExpression('#<d:getcontentlength>' . $total . '</d:getcontentlength>#', $props);
        self::assertSame(404, $this->request('PROPFIND', $session, ['Depth' => '0']), 'The upload session is gone after MOVE');
    }

    public function testAFullQuotaAnswers507(): void
    {
        $quotaUser = self::env('ITEST_NEXTCLOUD_QUOTA_USER');
        $status = $this->http->request('PUT', $this->files($quotaUser) . '/too-big-' . self::runId() . '.bin', [
            'auth_basic' => [$quotaUser, self::env('ITEST_NEXTCLOUD_QUOTA_PASSWORD')],
            'body' => str_repeat('q', 2 * self::MIB),
        ])->getStatusCode();

        self::assertSame(507, $status);
    }

    public function testWrongCredentialsAnswer401(): void
    {
        $status = $this->http->request('PROPFIND', $this->files(self::env('ITEST_NEXTCLOUD_USER')), [
            'auth_basic' => [self::env('ITEST_NEXTCLOUD_USER'), 'wrong-password'],
            'headers' => ['Depth' => '0'],
        ])->getStatusCode();

        self::assertSame(401, $status);
    }

    private function dav(): string
    {
        return self::env('ITEST_NEXTCLOUD_URL') . '/remote.php/dav';
    }

    private function files(string $user): string
    {
        return $this->dav() . '/files/' . rawurlencode($user);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): int
    {
        $options = $this->options($headers);
        if ($body !== null) {
            $options['body'] = $body;
        }

        return $this->http->request($method, $url, $options)->getStatusCode();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function options(array $headers): array
    {
        return ['auth_basic' => [self::env('ITEST_NEXTCLOUD_USER'), self::env('ITEST_NEXTCLOUD_PASSWORD')], 'headers' => $headers];
    }
}
