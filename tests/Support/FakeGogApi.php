<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * In-process fake of the GOG endpoints upstream calls, for characterization tests.
 *
 * All data is synthetic. The response shapes contain the fields upstream's code reads; whether real GOG
 * responses look exactly like this still has to be checked against the live API.
 *
 * Every request is recorded with its method, URL and normalized options so tests can assert on what
 * upstream sends (for example which hosts receive the bearer token).
 */
final class FakeGogApi
{
    public const ACCOUNT = 'https://embed.gog.com/account';

    public const API = 'https://api.gog.com';

    public const AUTH = 'https://auth.gog.com';

    public const CDN = 'https://cdn.gog.test';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, callable(string, string, array<string, mixed>): ResponseInterface> "METHOD URL" => handler */
    private array $routes = [];

    /** @var array<string, callable(string, string, array<string, mixed>): ResponseInterface> "METHOD URL-prefix" => handler */
    private array $prefixRoutes = [];

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $handler = $this->routes["{$method} {$url}"] ?? null;
            foreach ($this->prefixRoutes as $prefix => $candidate) {
                if ($handler === null && str_starts_with("{$method} {$url}", $prefix)) {
                    $handler = $candidate;
                }
            }
            if ($handler === null) {
                throw new RuntimeException("FakeGogApi: no route for {$method} {$url}");
            }

            return $handler($method, $url, $options);
        });
    }

    /**
     * @param callable(string, string, array<string, mixed>): ResponseInterface|ResponseInterface $response
     */
    public function on(string $method, string $url, callable|ResponseInterface $response): self
    {
        $this->routes["{$method} {$url}"] = is_callable($response)
            ? $response
            : static fn (): ResponseInterface => $response;

        return $this;
    }

    /**
     * Route for every URL that starts with $urlPrefix (used where upstream builds query strings itself).
     *
     * @param callable(string, string, array<string, mixed>): ResponseInterface $handler
     */
    public function onPrefix(string $method, string $urlPrefix, callable $handler): self
    {
        $this->prefixRoutes["{$method} {$urlPrefix}"] = $handler;

        return $this;
    }

    /**
     * @param array<mixed> $data
     */
    public function onJson(string $method, string $url, array $data, int $status = 200): self
    {
        return $this->on($method, $url, static fn (): ResponseInterface => new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));
    }

    /**
     * Registers the whole lookup chain upstream's OwnedItemsManager::getChecksum() follows for one file:
     * downlink JSON (signed CDN URL + checksum URL) and the checksum XML with an md5 attribute.
     */
    public function addFile(string $downlinkUrl, string $fileName, ?string $md5, int $size): self
    {
        $cdnUrl = self::cdnUrl($fileName);
        $checksumUrl = self::cdnUrl($fileName . '.xml');
        $this->onJson('GET', $downlinkUrl, ['downlink' => $cdnUrl, 'checksum' => $checksumUrl]);

        if ($md5 === null) {
            $this->on('GET', $checksumUrl, new MockResponse('', ['http_code' => 404]));
        } else {
            $xml = sprintf(
                '<file name="%s" available="1" notavailablemsg="" md5="%s" chunks="1" timestamp="2026-01-01 00:00:00" total_size="%d">'
                . '<chunk id="0" from="0" to="%d" method="md5">%s</chunk></file>',
                htmlspecialchars($fileName, ENT_XML1),
                $md5,
                $size,
                max(0, $size - 1),
                $md5,
            );
            $this->on('GET', $checksumUrl, new MockResponse($xml));
        }

        return $this;
    }

    /**
     * Signed-CDN-style URL with the token in the path, like GOG's CDN links (no query string).
     */
    public static function cdnUrl(string $fileName): string
    {
        return self::CDN . '/token=fake-signed-token~hmac=fake/secure/' . rawurlencode($fileName);
    }

    /**
     * @return list<string> "METHOD URL" of every recorded request
     */
    public function requestLines(): array
    {
        return array_map(static fn (array $r): string => "{$r['method']} {$r['url']}", $this->requests);
    }

    /**
     * Value of the Authorization header a recorded request carried, or null.
     *
     * @param array{method: string, url: string, options: array<string, mixed>} $request
     */
    public static function authorizationOf(array $request): ?string
    {
        $headers = $request['options']['normalized_headers']['authorization'] ?? [];

        return $headers === [] ? null : (string) $headers[0];
    }
}
