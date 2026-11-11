<?php

declare(strict_types=1);

namespace App\Storage\Nextcloud;

use App\Storage\Exception\UnexpectedStorageResponse;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Exception;

/**
 * Reads a WebDAV "207 Multi-Status" answer to PROPFIND into DavEntry values. Only properties the server reports with
 * status 200 count. Never loads anything from the network.
 *
 * @internal
 */
final class MultiStatus
{
    private const DAV = 'DAV:';

    /**
     * @return list<DavEntry>
     */
    public static function parse(string $xml): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new UnexpectedStorageResponse('Nextcloud answered PROPFIND with something that is not valid XML.');
        }

        $entries = [];
        foreach ($document->getElementsByTagNameNS(self::DAV, 'response') as $response) {
            $href = self::first($response, 'href')?->textContent;
            if ($href === null || trim($href) === '') {
                throw new UnexpectedStorageResponse('Nextcloud answered PROPFIND with an entry without a path.');
            }

            $size = null;
            $etag = null;
            $modified = null;
            $collection = false;
            foreach ($response->getElementsByTagNameNS(self::DAV, 'propstat') as $propstat) {
                if (preg_match('#^HTTP/\S+\s+200\b#', trim(self::first($propstat, 'status')->textContent ?? '')) !== 1) {
                    continue;
                }
                $length = trim(self::first($propstat, 'getcontentlength')->textContent ?? '');
                if ($length !== '' && ctype_digit($length)) {
                    $size = (int) $length;
                }
                $tag = trim(self::first($propstat, 'getetag')->textContent ?? '', " \t\n\r\"");
                if ($tag !== '') {
                    $etag = $tag;
                }
                $modified = self::date(trim(self::first($propstat, 'getlastmodified')->textContent ?? '')) ?? $modified;
                $type = self::first($propstat, 'resourcetype');
                if ($type !== null && $type->getElementsByTagNameNS(self::DAV, 'collection')->length > 0) {
                    $collection = true;
                }
            }

            $path = rtrim((string) parse_url(trim($href), PHP_URL_PATH), '/');
            $entries[] = new DavEntry(rawurldecode(substr($path, (int) strrpos($path, '/') + 1)), $collection, $size, $etag, $modified);
        }

        return $entries;
    }

    private static function first(DOMElement $parent, string $name): ?DOMElement
    {
        $node = $parent->getElementsByTagNameNS(self::DAV, $name)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
