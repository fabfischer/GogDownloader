<?php

declare(strict_types=1);

namespace App\Tests\Upstream;

use App\DTO\GameInstaller;
use App\Tests\Support\FakeGogApi;
use App\Tests\Support\UpstreamServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Characterization of upstream's URL resolution and download request (src/Service/DownloadManager.php).
 *
 * The last test pins two properties that are unsuitable for archiving (docs/fork/findings.md, F2-F4):
 * the GOG bearer token is also sent to the CDN host, and response buffering is left at its default.
 */
#[CoversClass(\App\Service\DownloadManager::class)]
final class DownloadManagerTest extends TestCase
{
    private const DOWNLINK = FakeGogApi::API . '/products/1001/downlink/installer/en1installer0';

    public function testRelativeUrlsAreResolvedAgainstEmbedGog(): void
    {
        $services = UpstreamServices::withValidToken((new FakeGogApi())->client());

        self::assertSame(
            'https://embed.gog.com/downloads/synthetic_quest/en1installer0',
            $services->downloads->getDownloadUrl($this->installer('/downloads/synthetic_quest/en1installer0')),
        );
        self::assertSame(self::DOWNLINK, $services->downloads->getDownloadUrl($this->installer(self::DOWNLINK)));
    }

    public function testFilenameOfRelativeUrlComesFromTheRedirectLocation(): void
    {
        $gog = new FakeGogApi();
        $gog->on('HEAD', 'https://embed.gog.com/downloads/synthetic_quest/en1installer0', new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => FakeGogApi::cdnUrl('setup_synthetic quest_(1.2.0).exe')],
        ]));
        $services = UpstreamServices::withValidToken($gog->client());

        $filename = $services->downloads->getFilename($this->installer('/downloads/synthetic_quest/en1installer0'));

        self::assertSame('setup_synthetic quest_(1.2.0).exe', $filename);
        self::assertSame(0, $gog->requests[0]['options']['max_redirects']);
        self::assertSame('Authorization: Bearer test-access-token', FakeGogApi::authorizationOf($gog->requests[0]));
    }

    public function testFilenameOfApiDownlinkComesFromTheDownlinkJson(): void
    {
        $gog = new FakeGogApi();
        $gog->addFile(self::DOWNLINK, 'setup_synthetic_quest_1.2.0.exe', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1000);
        $services = UpstreamServices::withValidToken($gog->client());

        self::assertSame('setup_synthetic_quest_1.2.0.exe', $services->downloads->getFilename($this->installer(self::DOWNLINK)));
    }

    public function testFilenameIsNullWhenTheDownlinkIsGone(): void
    {
        $gog = new FakeGogApi();
        $gog->on('GET', self::DOWNLINK, new MockResponse('', ['http_code' => 404]));
        $services = UpstreamServices::withValidToken($gog->client());

        self::assertNull($services->downloads->getFilename($this->installer(self::DOWNLINK)));
    }

    public function testDownloadUsesAnOpenEndedRangeAndSendsTheBearerTokenToTheCdn(): void
    {
        $gog = new FakeGogApi();
        $gog->addFile(self::DOWNLINK, 'setup_synthetic_quest_1.2.0.exe', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1000);
        $cdnUrl = FakeGogApi::cdnUrl('setup_synthetic_quest_1.2.0.exe');
        $gog->on('GET', $cdnUrl, new MockResponse('remaining-bytes', ['http_code' => 206]));
        $services = UpstreamServices::withValidToken($gog->client());

        $body = '';
        foreach ($services->downloads->download($this->installer(self::DOWNLINK), static function (): void {
        }, startAt: 100) as $chunk) {
            $body .= $chunk->getContent();
        }

        self::assertSame('remaining-bytes', $body);
        self::assertSame(['GET ' . self::DOWNLINK, 'GET ' . $cdnUrl], $gog->requestLines());

        $cdnRequest = $gog->requests[1];
        // Open-ended range from the resume offset, not a bounded part.
        self::assertSame(['Range: bytes=100-'], $cdnRequest['options']['normalized_headers']['range']);
        // Known flaw: the GOG access token goes to the CDN host as well.
        self::assertSame('Authorization: Bearer test-access-token', FakeGogApi::authorizationOf($cdnRequest));
        // Not observable through MockHttpClient: upstream leaves "buffer" at its default (true), which makes
        // Symfony HttpClient copy the body into php://temp. See docs/fork/findings.md (F2).
    }

    private function installer(string $url): GameInstaller
    {
        return new GameInstaller('en', 'windows', 'Synthetic Quest', 1000, $url, null, 1001);
    }
}
