<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/**
 * Everything an adapter needs to open an upload session for one object.
 */
final readonly class UploadRequest
{
    public function __construct(
        public ObjectKey $key,
        /** Exact size of the object in bytes, known before the upload starts. */
        public int $totalSize,
        /** Size of every part except the last one. */
        public int $partSize,
        public ?string $contentType = null,
    ) {
        if ($totalSize < 1) {
            throw new InvalidArgumentException('The object size must be at least one byte.');
        }
        if ($partSize < 1) {
            throw new InvalidArgumentException('The part size must be positive.');
        }
    }

    public function partCount(): int
    {
        return intdiv($this->totalSize + $this->partSize - 1, $this->partSize);
    }
}
