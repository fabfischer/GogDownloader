<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\FakeGog\PatternData;
use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\SourceRangeMismatch;
use App\Transfer\Exception\SourceSizeUnknown;
use App\Transfer\Exception\SourceUnavailable;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\SourceFingerprint;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * The fake GOG source over real HTTP, read by HttpRangeSource: every source failure mode of the testing strategy can be
 * triggered with a query parameter.
 */
#[CoversClass(HttpRangeSource::class)]
final class FakeGogSourceTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    private const LAST_MODIFIED = 'Thu, 13 Aug 2026 14:14:23 GMT';

    public function testAMultiGiBFileIsServedInExactRangesWithoutBeingAllocated(): void
    {
        $size = 3 * 1024 * self::MIB + 12345;
        $data = new PatternData('large', $size);
        $source = $this->source(fn (): string => $this->fileUrl('large', $size));

        $fingerprint = $source->open();
        $tail = implode('', iterator_to_array($source->readPart($size - self::MIB, self::MIB, $fingerprint), false));

        self::assertSame($size, $fingerprint->totalSize);
        self::assertSame(sprintf('"large-%d"', $size), $fingerprint->etag);
        self::assertSame($data->slice($size - self::MIB, self::MIB), $tail);
        self::assertLessThan(128 * self::MIB, memory_get_peak_usage(true));
    }

    public function testTheDownlinkAndChecksumXmlLookLikeGogs(): void
    {
        $size = 25 * self::MIB + 7;
        $http = HttpClient::create();

        $downlink = $http->request('GET', self::env('ITEST_FAKE_GOG_URL') . "/downlink/xmlseed/{$size}?ttl=600")->toArray();
        $xml = simplexml_load_string($http->request('GET', $downlink['checksum'])->getContent());

        self::assertSame(['downlink', 'checksum'], array_keys($downlink));
        self::assertGreaterThan(time() + 500, HttpRangeSource::gogExpiry()($downlink['downlink']));
        self::assertNotFalse($xml);
        self::assertSame((string) $size, (string) $xml['total_size']);
        self::assertSame('3', (string) $xml['chunks']);
        self::assertSame((new PatternData('xmlseed', $size))->hash('md5'), (string) $xml['md5']);
        self::assertSame('md5', (string) $xml->chunk[0]['method']);
    }

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function faults(): iterable
    {
        yield 'source ignores Range and returns 200' => ['no-range', RangeNotSupported::class];
        yield 'wrong Content-Range' => ['wrong-range', SourceRangeMismatch::class];
        yield 'wrong length' => ['wrong-length', SourceRangeMismatch::class];
        yield 'premature EOF' => ['early-eof', TransientSourceFailure::class];
        yield 'changed ETag' => ['etag-change', SourceChanged::class];
        yield 'changed size' => ['size-change', SourceChanged::class];
        yield 'unknown size' => ['unknown-size', SourceSizeUnknown::class];
        yield 'HTTP 416' => ['416', SourceChanged::class];
        yield 'HTTP 429' => ['429', TransientSourceFailure::class];
        yield 'HTTP 500' => ['500', TransientSourceFailure::class];
        yield 'HTTP 404, source removed' => ['404', SourceUnavailable::class];
        yield 'HTTP 403, also with a fresh URL' => ['403', SourceUnavailable::class];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('faults')]
    public function testEachSourceFaultBecomesItsFailure(string $fault, string $expected): void
    {
        $size = 4 * self::MIB;
        $source = $this->source(fn (): string => $this->fileUrl('faults', $size, ['fault' => $fault, 'run' => self::runId()]));

        try {
            iterator_to_array($source->readPart(0, self::MIB, $this->fingerprint('faults', $size)));
            self::fail('Expected ' . $expected);
        } catch (Throwable $e) {
            self::assertSame($expected, $e::class, $e->getMessage());
            if ($fault === '429') {
                self::assertInstanceOf(TransientSourceFailure::class, $e);
                self::assertSame(2, $e->retryAfterSeconds);
            }
        }
    }

    public function testAnExpiredSignedUrlIsReplacedByAFreshOne(): void
    {
        $size = 2 * self::MIB;
        $calls = 0;
        $base = self::env('ITEST_FAKE_GOG_URL');
        $source = $this->source(static function () use (&$calls, $base, $size): string {
            ++$calls;

            return $calls === 1
                ? sprintf('%s/token=nva=%d~dirs=1/files/expiry/%d', $base, time() - 10, $size)
                : HttpClient::create()->request('GET', "{$base}/downlink/expiry/{$size}")->toArray()['downlink'];
        });

        $fingerprint = $source->open();

        self::assertSame($size, $fingerprint->totalSize);
        self::assertSame(2, $calls);
    }

    public function testATemporaryFaultClearsOnTheNextAttempt(): void
    {
        $size = 2 * self::MIB;
        $source = $this->source(fn (): string => $this->fileUrl('flaky', $size, ['fault' => '500', 'fail-times' => 1, 'run' => self::runId()]));
        $fingerprint = $this->fingerprint('flaky', $size);

        try {
            iterator_to_array($source->readPart(0, self::MIB, $fingerprint));
            self::fail('Expected a temporary failure first');
        } catch (TransientSourceFailure) {
        }

        self::assertSame(
            (new PatternData('flaky', $size))->slice(0, self::MIB),
            implode('', iterator_to_array($source->readPart(0, self::MIB, $fingerprint), false)),
        );
    }

    /**
     * @param Closure(): string $resolve
     */
    private function source(Closure $resolve): HttpRangeSource
    {
        return new HttpRangeSource(HttpClient::create(), $resolve, self::fakeGogHosts(), HttpRangeSource::gogExpiry());
    }

    /**
     * @param array<string, string|int> $query
     */
    private function fileUrl(string $seed, int $size, array $query = []): string
    {
        return self::fakeGogFileUrl($seed, $size, $query);
    }

    private function fingerprint(string $seed, int $size): SourceFingerprint
    {
        return new SourceFingerprint($size, sprintf('"%s-%d"', $seed, $size), self::LAST_MODIFIED);
    }
}
