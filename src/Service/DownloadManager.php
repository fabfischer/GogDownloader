<?php

namespace App\Service;

use App\DTO\DownloadableItem;
use InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use UnexpectedValueException;

final class DownloadManager
{
    private const BASE_URL = 'https://embed.gog.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AuthenticationManager $authentication,
    ) {
    }

    public function getDownloadUrl(
        DownloadableItem $download
    ): string {
        if (str_starts_with($download->url, '/')) {
            return self::BASE_URL . $download->url;
        }
        return $download->url;
    }

    public function getFilename(
        DownloadableItem $download,
        int                     $httpTimeout = 3
    ): ?string {
        $url = $this->getRealDownloadUrl($download, $httpTimeout);
        if (!$url) {
            return null;
        }

        return urldecode(pathinfo($url, PATHINFO_BASENAME));
    }

    public function download(
        DownloadableItem $download,
        callable                $callback,
        ?int                    $startAt = null,
        int                     $httpTimeout = 3,
        array                   $curlOptions = [],
    ): ResponseStreamInterface {
        $url = $this->getRealDownloadUrl($download, $httpTimeout);

        $headers = [];
        if ($startAt !== null) {
            $headers['Range'] = "bytes={$startAt}-";
        }
        $response = $this->httpClient->request(
            Request::METHOD_GET,
            $url,
            [
                'on_progress' => $callback,
                'auth_bearer' => (string) $this->authentication->getAuthorization(),
                'headers' => $headers,
                'timeout' => $httpTimeout,
                'extra' => [
                    'curl' => $curlOptions,
                ],
            ],
        );

        return $this->httpClient->stream($response);
    }

    /**
     * Resolves a file's download link from the product API (files[].downlink) to a signed CDN URL and the URL of the
     * file's checksum XML. The signed URL grants access for about a day: treat it as a secret.
     *
     * The account's token is only sent to GOG's own API hosts, never to a host named in a payload.
     *
     * @throws InvalidArgumentException if the link doesn't point to api.gog.com or embed.gog.com over https
     * @throws UnexpectedValueException if GOG's answer has no download link
     *
     * @return array{downlink: string, checksum: string|null}
     */
    public function resolveDownlink(string $downlinkUrl, int $httpTimeout = 3): array
    {
        if (preg_match('#^https://(api|embed)\.gog\.com/#', $downlinkUrl) !== 1) {
            throw new InvalidArgumentException('Download links are resolved at api.gog.com or embed.gog.com only.');
        }
        $response = $this->httpClient->request(
            Request::METHOD_GET,
            $downlinkUrl,
            [
                'auth_bearer' => (string) $this->authentication->getAuthorization(),
                'max_redirects' => 0,
                'timeout' => $httpTimeout,
            ]
        );
        $content = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $downlink = is_array($content) ? ($content['downlink'] ?? null) : null;
        if (!is_string($downlink) || $downlink === '') {
            throw new UnexpectedValueException('GOG returned no download link.');
        }
        $checksum = $content['checksum'] ?? null;

        return [
            'downlink' => $downlink,
            'checksum' => is_string($checksum) && $checksum !== '' ? $checksum : null,
        ];
    }

    /**
     * The checksum XML of a file (the MD5 of the file and of every 10 MiB chunk), or null when GOG has none: extras
     * often answer 404.
     */
    public function getChecksumXml(string $checksumUrl, int $httpTimeout = 3): ?string
    {
        if (!str_starts_with($checksumUrl, 'https://')) {
            throw new InvalidArgumentException('Checksum files are only fetched over https.');
        }
        $response = $this->httpClient->request(Request::METHOD_GET, $checksumUrl, ['timeout' => $httpTimeout]);
        if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
            return null;
        }
        $content = $response->getContent();

        return $content === '' ? null : $content;
    }

    private function getRealDownloadUrl(
        DownloadableItem $download,
        int                     $httpTimeout = 3
    ): ?string {
        if (str_starts_with($download->url, '/')) {
            $response = $this->httpClient->request(
                Request::METHOD_HEAD,
                $this->getDownloadUrl($download),
                [
                    'auth_bearer' => (string) $this->authentication->getAuthorization(),
                    'max_redirects' => 0,
                    'timeout' => $httpTimeout,
                ]
            );

            return $response->getHeaders(false)['location'][0] ?? null;
        }

        $response = $this->httpClient->request(
            Request::METHOD_GET,
            $this->getDownloadUrl($download),
            [
                'auth_bearer' => (string) $this->authentication->getAuthorization(),
                'max_redirects' => 0,
                'timeout' => $httpTimeout,
            ]
        );
        try {
            $content = json_decode($response->getContent(), true);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                throw $e;
            }

            return null;
        }

        return $content['downlink'] ?? null;
    }
}
