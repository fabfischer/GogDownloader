<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Tests\Support\RecordingHttpClient;
use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\Exception\RedirectNotAllowed;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\SourceRangeMismatch;
use App\Transfer\Exception\SourceSizeUnknown;
use App\Transfer\Exception\SourceUnavailable;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\HostAllowlist;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\SourceFingerprint;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpRangeSource::class)]
#[CoversClass(HostAllowlist::class)]
final class HttpRangeSourceTest extends TestCase
{
    private const URL = 'https://cdn.gog.test/secure/token=nva=2000000000~hash/setup.bin';

    private const LAST_MODIFIED = 'Thu, 13 Aug 2026 14:14:23 GMT';

    private string $file;

    protected function setUp(): void
    {
        $this->file = '';
        for ($i = 0; strlen($this->file) < 100; ++$i) {
            $this->file .= hash('sha256', (string) $i);
        }
        $this->file = substr($this->file, 0, 100);
    }

    public function testItProbesOneByteThenReadsExactlyTheRequestedRange(): void
    {
        $client = $this->client($this->honestServer());
        $source = $this->source($client);

        $fingerprint = $source->open();
        $bytes = implode('', iterator_to_array($source->readPart(10, 20, $fingerprint), false));

        self::assertEquals(new SourceFingerprint(100, '"v1"', self::LAST_MODIFIED), $fingerprint);
        self::assertSame(substr($this->file, 10, 20), $bytes);
        self::assertCount(2, $client->requests);
        foreach ($client->requests as $index => $request) {
            self::assertSame(['bytes=0-0', 'bytes=10-29'][$index], $request['options']['headers']['Range']);
            self::assertFalse($request['options']['buffer'], 'Responses must not be buffered to php://temp');
            self::assertSame(0, $request['options']['max_redirects']);
            self::assertArrayNotHasKey('auth_bearer', $request['options']);
            self::assertArrayNotHasKey('auth_basic', $request['options']);
            self::assertArrayNotHasKey('Authorization', $request['options']['headers']);
        }
    }

    public function testAWholeFileAnswerIsRangeNotSupported(): void
    {
        $source = $this->source($this->client(fn (): MockResponse => new MockResponse($this->file, ['http_code' => 200])));

        $this->expectException(RangeNotSupported::class);
        $source->open();
    }

    public function testAnotherRangeThanRequestedIsRejected(): void
    {
        $source = $this->source($this->client($this->honestServer(rangeShift: 1)));
        $fingerprint = new SourceFingerprint(100, '"v1"', self::LAST_MODIFIED);

        $this->expectException(SourceRangeMismatch::class);
        iterator_to_array($source->readPart(10, 20, $fingerprint));
    }

    public function testAShortBodyIsATransientFailure(): void
    {
        $source = $this->source($this->client($this->honestServer(bodyLengthDelta: -5, withContentLength: false)));

        $this->expectException(TransientSourceFailure::class);
        iterator_to_array($source->readPart(10, 20, new SourceFingerprint(100, '"v1"', self::LAST_MODIFIED)));
    }

    public function testALongerBodyThanRequestedIsRejected(): void
    {
        $source = $this->source($this->client($this->honestServer(bodyLengthDelta: 5, withContentLength: false)));

        $this->expectException(SourceRangeMismatch::class);
        iterator_to_array($source->readPart(10, 20, new SourceFingerprint(100, '"v1"', self::LAST_MODIFIED)));
    }

    /**
     * @return iterable<string, array{SourceFingerprint}>
     */
    public static function changedFingerprints(): iterable
    {
        yield 'other ETag' => [new SourceFingerprint(100, '"v0"', self::LAST_MODIFIED)];
        yield 'other Last-Modified' => [new SourceFingerprint(100, '"v1"', 'Mon, 27 Jul 2026 10:40:42 GMT')];
        yield 'other size' => [new SourceFingerprint(120, '"v1"', self::LAST_MODIFIED)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedFingerprints')]
    public function testAChangedSourceIsDetected(SourceFingerprint $expected): void
    {
        $source = $this->source($this->client($this->honestServer()));

        $this->expectException(SourceChanged::class);
        iterator_to_array($source->readPart(10, 20, $expected));
    }

    public function testAnUnknownTotalSizeIsRejected(): void
    {
        $source = $this->source($this->client(static fn (): MockResponse => new MockResponse('x', [
            'http_code' => 206,
            'response_headers' => ['Content-Range' => 'bytes 0-0/*'],
        ])));

        $this->expectException(SourceSizeUnknown::class);
        $source->open();
    }

    /**
     * @return iterable<string, array{int, class-string, ?int}>
     */
    public static function errorStatuses(): iterable
    {
        yield '416' => [416, SourceChanged::class, null];
        yield '429 with Retry-After' => [429, TransientSourceFailure::class, 7];
        yield '503' => [503, TransientSourceFailure::class, null];
        yield '404' => [404, SourceUnavailable::class, null];
    }

    /**
     * @param class-string $exception
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorStatuses')]
    public function testErrorStatusesMapToTheRightFailure(int $status, string $exception, ?int $retryAfter): void
    {
        $source = $this->source($this->client(static fn (): MockResponse => new MockResponse('', [
            'http_code' => $status,
            'response_headers' => $status === 429 ? ['Retry-After' => '7'] : [],
        ])));

        try {
            $source->open();
            self::fail('Expected ' . $exception);
        } catch (TransientSourceFailure $e) {
            self::assertSame($exception, $e::class);
            self::assertSame($retryAfter, $e->retryAfterSeconds);
        } catch (\Throwable $e) {
            self::assertSame($exception, $e::class);
        }
    }

    public function testAForbiddenUrlIsResolvedAgainOnce(): void
    {
        $calls = 0;
        $server = $this->honestServer();
        $client = $this->client(static function (string $method, string $url, array $options) use ($server): MockResponse {
            return str_contains($url, 'old') ? new MockResponse('', ['http_code' => 403]) : $server($method, $url, $options);
        });
        $source = $this->source($client, static function () use (&$calls): string {
            return ++$calls === 1 ? 'https://cdn.gog.test/old/setup.bin' : 'https://cdn.gog.test/new/setup.bin';
        });

        self::assertSame(100, $source->open()->totalSize);
        self::assertSame(2, $calls);
    }

    public function testAUrlForbiddenTwiceIsUnavailable(): void
    {
        $source = $this->source($this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 403])));

        $this->expectException(SourceUnavailable::class);
        $source->open();
    }

    public function testAUrlCloseToItsExpiryIsResolvedAgain(): void
    {
        $calls = 0;
        $source = new HttpRangeSource(
            $this->client($this->honestServer()),
            static function () use (&$calls): string {
                ++$calls;

                return 'https://cdn.gog.test/secure/token=nva=1000000100~x/setup.bin';
            },
            new HostAllowlist(['*.gog.test']),
            HttpRangeSource::gogExpiry(),
            refreshBeforeExpirySeconds: 300,
            now: static fn (): int => 1000000000,
        );

        $fingerprint = $source->open();
        iterator_to_array($source->readPart(0, 10, $fingerprint));

        self::assertSame(2, $calls);
    }

    public function testARedirectToAnAllowedHostIsFollowed(): void
    {
        $server = $this->honestServer();
        $client = $this->client(static function (string $method, string $url, array $options) use ($server): MockResponse {
            return str_contains($url, 'edge')
                ? $server($method, $url, $options)
                : new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://edge.gog.test/setup.bin']]);
        });

        self::assertSame(100, $this->source($client)->open()->totalSize);
        self::assertSame('https://edge.gog.test/setup.bin', $client->requests[1]['url']);
    }

    public function testARedirectToAnotherHostIsRefused(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.example/setup.bin']]));

        $this->expectException(RedirectNotAllowed::class);
        $this->source($client)->open();
    }

    public function testPlainHttpAndForeignHostsAreRefusedBeforeAnyRequest(): void
    {
        foreach (['http://cdn.gog.test/setup.bin', 'https://gog.test.evil.example/setup.bin'] as $url) {
            $client = $this->client($this->honestServer());

            try {
                $this->source($client, static fn (): string => $url)->open();
                self::fail('Expected RedirectNotAllowed for ' . $url);
            } catch (RedirectNotAllowed) {
                self::assertSame([], $client->requests);
            }
        }
    }

    public function testTheGogExpiryIsReadFromTheUrl(): void
    {
        $expiry = HttpRangeSource::gogExpiry();

        self::assertSame(1731340000, $expiry('https://gog-cdn-fastly.gog.com/token=nva=1731340000~dirs=1~hash=x/f.bin'));
        self::assertSame(1731340000, $expiry('https://cdn.gog.test/f.bin?nva=1731340000&x=1'));
        self::assertNull($expiry('https://cdn.gog.test/f.bin?canva=1731340000'));
        self::assertNull($expiry('https://cdn.gog.test/f.bin'));
    }

    /**
     * A server that answers ranges of $this->file, with optional faults.
     *
     * @return Closure(string, string, array<string, mixed>): MockResponse
     */
    private function honestServer(int $rangeShift = 0, int $bodyLengthDelta = 0, bool $withContentLength = true): Closure
    {
        return function (string $method, string $url, array $options) use ($rangeShift, $bodyLengthDelta, $withContentLength): MockResponse {
            $range = $options['normalized_headers']['range'][0] ?? '';
            if (preg_match('/bytes=(\d+)-(\d+)$/', $range, $match) !== 1) {
                return new MockResponse($this->file, ['http_code' => 200]);
            }
            [$from, $to] = [(int) $match[1] + $rangeShift, (int) $match[2] + $rangeShift];
            $body = substr($this->file, $from, max(0, $to - $from + 1 + $bodyLengthDelta));
            $headers = [
                'Content-Range' => "bytes {$from}-{$to}/100",
                'ETag' => '"v1"',
                'Last-Modified' => self::LAST_MODIFIED,
                'Accept-Ranges' => 'bytes',
            ];
            if ($withContentLength) {
                $headers['Content-Length'] = (string) ($to - $from + 1);
            }

            return new MockResponse($body, ['http_code' => 206, 'response_headers' => $headers]);
        };
    }

    private function client(Closure $factory): RecordingHttpClient
    {
        return new RecordingHttpClient(new MockHttpClient($factory));
    }

    /**
     * @param (Closure(): string)|null $resolve
     */
    private function source(RecordingHttpClient $client, ?Closure $resolve = null): HttpRangeSource
    {
        return new HttpRangeSource($client, $resolve ?? static fn (): string => self::URL, new HostAllowlist(['*.gog.test']), HttpRangeSource::gogExpiry());
    }
}
