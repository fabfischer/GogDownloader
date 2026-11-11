<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;

/**
 * What stat() reports about an existing object.
 */
final readonly class RemoteObjectInfo
{
    public function __construct(
        public ObjectKey $key,
        public int $size,
        public ?string $remoteETag,
        public ?DateTimeImmutable $lastModified,
        /**
         * A checksum of the stored bytes as "algorithm:hex", only when the backend reports one that really covers the
         * content (see StorageCapabilities::$contentChecksumOnStat). Never derived from a multipart ETag.
         */
        public ?string $contentChecksum = null,
    ) {
    }
}
