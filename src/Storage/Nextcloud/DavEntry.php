<?php

declare(strict_types=1);

namespace App\Storage\Nextcloud;

use DateTimeImmutable;

/**
 * One file or folder from a WebDAV PROPFIND answer.
 *
 * @internal
 */
final readonly class DavEntry
{
    public function __construct(
        /** Last path segment, URL-decoded. */
        public string $name,
        public bool $collection,
        public ?int $size,
        /** ETag without quotes. */
        public ?string $etag,
        public ?DateTimeImmutable $lastModified,
    ) {
    }
}
