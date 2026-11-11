<?php

declare(strict_types=1);

namespace App\Transfer\Source;

use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\Exception\SourceChanged;
use App\Transfer\Exception\SourceSizeUnknown;
use App\Transfer\Exception\SourceUnavailable;
use App\Transfer\Exception\TransientSourceFailure;
use App\Transfer\HostAllowlist;
use App\Transfer\SourceFingerprint;
use App\Transfer\TransferSource;
use Closure;
use Generator;
use Iterator;
use LogicException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a file once, front to back, from a single stream, for sources that don't honour byte ranges
 * (the fallback when ranges are not available). Parts must be read in order. The source is not resumable: if it fails midway, the transfer
 * starts again from byte zero. It holds at most one network chunk beyond the current part, never the file.
 */
final class SequentialSource implements TransferSource
{
    /** @var Iterator<int, string>|null */
    private ?Iterator $chunks = null;

    private string $pending = '';

    private int $position = 0;

    /**
     * @param Closure(): array{SourceFingerprint, iterable<string>} $openStream opens the stream from byte zero
     */
    public function __construct(
        private readonly Closure $openStream,
    ) {
    }

    /**
     * A sequential source for a plain GET of a signed URL: no range, no buffering to php://temp, no auth header,
     * redirects only to allowlisted hosts, and the size must be known from Content-Length.
     *
     * @param Closure(): string $resolveUrl
     */
    public static function http(HttpClientInterface $client, Closure $resolveUrl, HostAllowlist $allowedHosts, float $timeoutSeconds = 30.0): self
    {
        return new self(static function () use ($client, $resolveUrl, $allowedHosts, $timeoutSeconds): array {
            $url = $resolveUrl();
            for ($hops = 0; ; ++$hops) {
                $allowedHosts->assertAllowed($url);
                $response = $client->request('GET', $url, ['max_redirects' => 0, 'buffer' => false, 'timeout' => $timeoutSeconds]);

                try {
                    $status = $response->getStatusCode();
                    $headers = $response->getHeaders(false);
                } catch (TransportExceptionInterface $e) {
                    throw new TransientSourceFailure('The source could not be reached.', null, $e);
                }
                if ($status >= 300 && $status < 400 && isset($headers['location'][0]) && $hops < 3) {
                    $response->cancel();
                    $url = $headers['location'][0];

                    continue;
                }
                if ($status !== 200) {
                    $response->cancel();

                    throw $status >= 500 || $status === 429
                        ? new TransientSourceFailure(sprintf('The source answered %d.', $status))
                        : new SourceUnavailable(sprintf('The source answered %d.', $status));
                }
                $length = $headers['content-length'][0] ?? null;
                if ($length === null || !ctype_digit($length)) {
                    $response->cancel();

                    throw new SourceSizeUnknown('The source does not state the size (no Content-Length).');
                }

                $fingerprint = new SourceFingerprint((int) $length, $headers['etag'][0] ?? null, $headers['last-modified'][0] ?? null);
                $chunks = (static function () use ($client, $response, $timeoutSeconds): Generator {
                    try {
                        foreach ($client->stream($response, $timeoutSeconds) as $chunk) {
                            if ($chunk->isTimeout()) {
                                $response->cancel();

                                throw new TransientSourceFailure('The source stopped sending data (timeout).');
                            }
                            $content = $chunk->getContent();
                            if ($content !== '') {
                                yield $content;
                            }
                        }
                    } catch (TransportExceptionInterface $e) {
                        throw new TransientSourceFailure('The connection to the source failed while reading.', null, $e);
                    }
                })();

                return [$fingerprint, $chunks];
            }
        });
    }

    public function isResumable(): bool
    {
        return false;
    }

    public function open(): SourceFingerprint
    {
        [$fingerprint, $chunks] = ($this->openStream)();
        $this->chunks = $chunks instanceof Iterator ? $chunks : (static fn (): Generator => yield from $chunks)();
        $this->chunks->rewind();
        $this->pending = '';
        $this->position = 0;

        return $fingerprint;
    }

    public function readPart(int $offset, int $length, SourceFingerprint $expected): iterable
    {
        if ($this->chunks === null) {
            throw new LogicException('open() must be called before reading.');
        }
        if ($offset !== $this->position) {
            throw new RangeNotSupported(sprintf('A sequential source is at byte %d and cannot read from %d.', $this->position, $offset));
        }
        if ($offset + $length > $expected->totalSize) {
            throw new SourceChanged('The requested part lies beyond the size the source announced.');
        }

        $remaining = $length;
        while ($remaining > 0) {
            if ($this->pending === '') {
                if (!$this->chunks->valid()) {
                    throw new TransientSourceFailure(sprintf('The source ended after %d of %d bytes.', $this->position, $expected->totalSize));
                }
                $this->pending = $this->chunks->current();
                $this->chunks->next();
            }
            $take = substr($this->pending, 0, $remaining);
            $this->pending = (string) substr($this->pending, strlen($take));
            $remaining -= strlen($take);
            $this->position += strlen($take);

            yield $take;
        }
    }
}
