<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;

/**
 * An object that complete() made visible at its final key. Its content still has to be verified before the archive
 * counts it (size, hashes).
 */
final readonly class StoredObject
{
    public function __construct(
        public ObjectKey $key,
        public int $size,
        /** The backend's identifier for the stored version (S3 ETag, WebDAV ETag); not a content hash. */
        public ?string $remoteETag,
        public DateTimeImmutable $completedAt,
    ) {
    }
}
