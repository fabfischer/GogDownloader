<?php

declare(strict_types=1);

namespace App\Transfer\Source;

use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\Exception\RedirectNotAllowed;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\SourceRangeMismatch;
use App\Transfer\Exception\SourceSizeUnknown;
use App\Transfer\Exception\SourceUnavailable;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\HostAllowlist;
use App\Transfer\SourceFingerprint;
use App\Transfer\TransferSource;
use Closure;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads a file from a signed download URL in bounded byte ranges:
 *
 * - every request is `Range: bytes=a-b` with `buffer => false`, so nothing is copied to php://temp;
 * - a part is accepted only from a 206 with exactly `Content-Range: bytes a-b/<total>` and exactly the requested
 *   number of body bytes; the total and the validators (ETag, Last-Modified) must match the fingerprint;
 * - redirects are followed by hand, only to allowlisted https hosts;
 * - no Authorization header or token is ever sent: the signed URL carries its own permission;
 * - the URL is fetched again through $resolveUrl when it is about to expire or the source answers 403/410.
 *
 * The URL itself is never put into exception messages, because it is signed.
 */
final class HttpRangeSource implements TransferSource
{
    private const MAX_REDIRECTS = 3;

    private ?string $url = null;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param Closure(): string            $resolveUrl returns a fresh signed URL (the caller talks to the GOG API)
     * @param (Closure(string): ?int)|null $expiresAt  Unix time a URL stops working, if it can be read from it
     * @param (Closure(): int)|null        $now
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Closure $resolveUrl,
        private readonly HostAllowlist $allowedHosts,
        private readonly ?Closure $expiresAt = null,
        private readonly int $refreshBeforeExpirySeconds = 300,
        private readonly float $timeoutSeconds = 30.0,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    /**
     * Reads the `nva` ("not valid after") Unix timestamp from a GOG CDN URL.
     *
     * @return Closure(string): ?int
     */
    public static function gogExpiry(): Closure
    {
        return static fn (string $url): ?int => preg_match('/(?:^|[^A-Za-z])nva=(\d{9,11})(?!\d)/', $url, $match) === 1 ? (int) $match[1] : null;
    }

    public function isResumable(): bool
    {
        return true;
    }

    public function open(): SourceFingerprint
    {
        // A one-byte range proves range support and returns the real size and validators.
        $response = $this->rangeResponse(0, 0, null);
        $fingerprint = $this->fingerprintOf($response);
        $this->drain($response, 1);

        return $fingerprint;
    }

    public function readPart(int $offset, int $length, SourceFingerprint $expected): iterable
    {
        if ($offset < 0 || $length < 1 || $offset + $length > $expected->totalSize) {
            throw new SourceRangeMismatch(sprintf('Range %d+%d lies outside the file of %d bytes.', $offset, $length, $expected->totalSize));
        }
        $response = $this->rangeResponse($offset, $offset + $length - 1, $expected);

        $received = 0;

        try {
            foreach ($this->client->stream($response, $this->timeoutSeconds) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();

                    throw new TransientSourceFailure('The source stopped sending data (timeout).');
                }
                $content = $chunk->getContent();
                if ($content === '') {
                    continue;
                }
                $received += strlen($content);
                if ($received > $length) {
                    $response->cancel();

                    throw new SourceRangeMismatch(sprintf('The source sent more than the %d requested bytes.', $length));
                }

                yield $content;
            }
        } catch (TransportExceptionInterface $e) {
            throw new TransientSourceFailure('The connection to the source failed while reading.', null, $e);
        }

        if ($received !== $length) {
            throw new TransientSourceFailure(sprintf('The source ended after %d of %d bytes.', $received, $length));
        }
    }

    /**
     * Sends a validated range request; refreshes the URL once on 403/410 or when it is about to expire.
     */
    private function rangeResponse(int $from, int $to, ?SourceFingerprint $expected): ResponseInterface
    {
        for ($refreshed = false; ; $refreshed = true) {
            $url = $this->currentUrl($refreshed);
            $response = $this->followRedirects($url, $from, $to);

            try {
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
            } catch (TransportExceptionInterface $e) {
                throw new TransientSourceFailure('The source could not be reached.', null, $e);
            }

            if ($status === 206) {
                $this->assertRange($headers, $from, $to, $expected);

                return $response;
            }

            $response->cancel();
            if (($status === 403 || $status === 410) && !$refreshed) {
                continue;
            }

            throw match (true) {
                $status === 200 => new RangeNotSupported('The source ignored the range request and offered the whole file.'),
                $status === 416 => new SourceChanged('The source refused the range; the file is shorter than expected.'),
                $status === 429 => new TransientSourceFailure('The source is rate limiting.', $this->retryAfter($headers)),
                $status >= 500 => new TransientSourceFailure(sprintf('The source answered %d.', $status), $this->retryAfter($headers)),
                default => new SourceUnavailable(sprintf('The source answered %d.', $status)),
            };
        }
    }

    private function followRedirects(string $url, int $from, int $to): ResponseInterface
    {
        for ($hops = 0; ; ++$hops) {
            $this->allowedHosts->assertAllowed($url);
            // No auth options: the signed URL is the permission (fork finding F3).
            $response = $this->client->request('GET', $url, [
                'headers' => ['Range' => "bytes={$from}-{$to}"],
                'max_redirects' => 0,
                'buffer' => false,
                'timeout' => $this->timeoutSeconds,
            ]);

            try {
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                throw new TransientSourceFailure('The source could not be reached.', null, $e);
            }
            if ($status < 300 || $status >= 400) {
                return $response;
            }

            $location = $response->getHeaders(false)['location'][0] ?? null;
            $response->cancel();
            if ($location === null || $hops >= self::MAX_REDIRECTS) {
                throw new RedirectNotAllowed('The source redirected too often or without a target.');
            }
            $url = $this->absolute($location, $url);
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function assertRange(array $headers, int $from, int $to, ?SourceFingerprint $expected): void
    {
        $range = $headers['content-range'][0] ?? '';
        if (preg_match('/^bytes (\d+)-(\d+)\/(\d+|\*)$/', trim($range), $match) !== 1) {
            throw new SourceRangeMismatch('The source sent no valid Content-Range.');
        }
        if ($match[3] === '*') {
            throw new SourceSizeUnknown('The source does not state the total size.');
        }
        if ((int) $match[1] !== $from || (int) $match[2] !== $to) {
            throw new SourceRangeMismatch(sprintf('Asked for bytes %d-%d, got %s-%s.', $from, $to, $match[1], $match[2]));
        }
        $length = $headers['content-length'][0] ?? null;
        if ($length !== null && (int) $length !== $to - $from + 1) {
            throw new SourceRangeMismatch(sprintf('Content-Length %s does not match the range of %d bytes.', $length, $to - $from + 1));
        }
        if ($expected !== null && !$expected->matches($this->fingerprintFromHeaders($headers, (int) $match[3]))) {
            throw new SourceChanged('The source size, ETag or Last-Modified changed during the transfer.');
        }
    }

    private function fingerprintOf(ResponseInterface $response): SourceFingerprint
    {
        $headers = $response->getHeaders(false);
        preg_match('/\/(\d+)$/', trim($headers['content-range'][0] ?? ''), $match);

        return $this->fingerprintFromHeaders($headers, (int) ($match[1] ?? 0));
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function fingerprintFromHeaders(array $headers, int $total): SourceFingerprint
    {
        return new SourceFingerprint($total, $headers['etag'][0] ?? null, $headers['last-modified'][0] ?? null);
    }

    private function currentUrl(bool $forceRefresh): string
    {
        $expired = false;
        if ($this->url !== null && $this->expiresAt !== null) {
            $expiry = ($this->expiresAt)($this->url);
            $expired = $expiry !== null && $expiry - ($this->now)() <= $this->refreshBeforeExpirySeconds;
        }
        if ($this->url === null || $forceRefresh || $expired) {
            $this->url = ($this->resolveUrl)();
        }

        return $this->url;
    }

    private function drain(ResponseInterface $response, int $length): void
    {
        $received = 0;

        try {
            foreach ($this->client->stream($response, $this->timeoutSeconds) as $chunk) {
                if ($chunk->isTimeout()) {
                    break;
                }
                $received += strlen($chunk->getContent());
            }
        } catch (TransportExceptionInterface $e) {
            throw new TransientSourceFailure('The connection to the source failed while reading.', null, $e);
        }
        if ($received !== $length) {
            throw new SourceRangeMismatch(sprintf('The probe returned %d bytes instead of %d.', $received, $length));
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);

        return sprintf('%s://%s/%s', $scheme, $host, ltrim($location, '/'));
    }
}
