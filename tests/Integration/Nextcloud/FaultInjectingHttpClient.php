<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Tests\Support\TempFiles;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Wraps the real HTTP client for tests against the test Nextclouds. It counts requests per method, notes how many bytes
 * the process held in temporary files while a request ran, and fails chosen requests:
 * - a status code: the request never reaches Nextcloud and the answer has that status, as from a proxy in front of it;
 * - CONNECTION_LOST: the request never reaches Nextcloud and the client sees a broken connection;
 * - ANSWER_LOST: the request reaches Nextcloud and completes there, but the client sees a broken connection.
 *
 * Faults only work for requests whose answer is read in full; streamed downloads go through unchanged.
 */
final class FaultInjectingHttpClient implements HttpClientInterface
{
    public const CONNECTION_LOST = 'connection lost';

    public const ANSWER_LOST = 'answer lost';

    /** Most bytes held in temporary files while a request was running. */
    public int $maxTempBytes = 0;

    /** @var list<string> method and last path segment of every request, in order */
    public array $requests = [];

    /** @var array<string, array<int, int|string>> */
    private array $faults = [];

    /** @var array<string, int> */
    private array $counts = [];

    private HttpClientInterface $inner;

    public function __construct(?HttpClientInterface $inner = null)
    {
        $this->inner = $inner ?? HttpClient::create();
    }

    /**
     * Fails the $nth request with $method (counted from 1).
     */
    public function failOn(string $method, int $nth, int|string $fault): void
    {
        $this->faults[$method][$nth] = $fault;
    }

    public function count(string $method): int
    {
        return $this->counts[$method] ?? 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $nth = $this->counts[$method] = ($this->counts[$method] ?? 0) + 1;
        $this->requests[] = $method . ' ' . basename((string) parse_url($url, PHP_URL_PATH));
        $fault = $this->faults[$method][$nth] ?? null;

        if ($fault === self::ANSWER_LOST) {
            $real = $this->inner->request($method, $url, $options);
            $real->getContent(false);

            return self::fake($method, $url, new MockResponse('', ['error' => 'Connection reset by peer (injected after the server answered)']));
        }
        if ($fault === self::CONNECTION_LOST) {
            return self::fake($method, $url, new MockResponse('', ['error' => 'Connection reset by peer (injected)']));
        }
        if (is_int($fault)) {
            return self::fake($method, $url, new MockResponse('<html><body>injected</body></html>', ['http_code' => $fault]));
        }

        $options['on_progress'] = function (): void {
            $this->maxTempBytes = max($this->maxTempBytes, TempFiles::bytesHeld());
        };

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    private static function fake(string $method, string $url, MockResponse $response): ResponseInterface
    {
        return (new MockHttpClient($response))->request($method, $url);
    }
}
