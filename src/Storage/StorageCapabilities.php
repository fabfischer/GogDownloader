<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exception\PartRejected;
use InvalidArgumentException;

/**
 * What a storage backend can and cannot do. Callers plan uploads and verification from these values instead of
 * assuming that every backend behaves like S3.
 */
final readonly class StorageCapabilities
{
    public function __construct(
        /** Short backend name for logs and the UI, e.g. "s3" or "nextcloud". */
        public string $backend,
        /** Smallest size of every part except the last one. */
        public int $minPartSize,
        /** Largest size of any part. */
        public int $maxPartSize,
        /** Largest number of parts in one upload. */
        public int $maxParts,
        /** Whether the backend can list the parts of an unfinished upload after a restart (reconciliation). */
        public bool $canListParts,
        /** Seconds of inactivity after which the backend drops an unfinished upload; null if it keeps it. */
        public ?int $sessionIdleTimeoutSeconds,
        /** Whether the backend can refuse to complete onto an existing key on its own (conditional create). */
        public bool $conditionalCreate,
        /** Whether stat() reports a content checksum that can verify the stored bytes. */
        public bool $contentChecksumOnStat,
    ) {
        if ($minPartSize < 1 || $maxPartSize < $minPartSize || $maxParts < 1) {
            throw new InvalidArgumentException('Part limits must be positive and the maximum not below the minimum.');
        }
    }

    /**
     * Checks one part against the limits. The last part may be smaller than the minimum.
     *
     * @throws PartRejected
     */
    public function assertPartAllowed(int $number, int $length, bool $isLast): void
    {
        if ($number < 1 || $number > $this->maxParts) {
            throw new PartRejected(sprintf('Part number %d is outside 1..%d.', $number, $this->maxParts));
        }
        if ($length < 1 || $length > $this->maxPartSize) {
            throw new PartRejected(sprintf('Part %d has %d bytes; the maximum is %d.', $number, $length, $this->maxPartSize));
        }
        if (!$isLast && $length < $this->minPartSize) {
            throw new PartRejected(sprintf('Part %d has %d bytes; every part but the last needs at least %d.', $number, $length, $this->minPartSize));
        }
    }
}
