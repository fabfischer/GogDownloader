<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Identity of a source file at the time a transfer starts: the real size plus the validators the server sends. Every
 * later response must match it, or the source changed.
 */
final readonly class SourceFingerprint
{
    public function __construct(
        public int $totalSize,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }

    /**
     * True if $other describes the same file: same size, and every validator known here is present and equal there.
     */
    public function matches(self $other): bool
    {
        if ($this->totalSize !== $other->totalSize) {
            return false;
        }
        if ($this->etag !== null && $this->etag !== $other->etag) {
            return false;
        }

        return $this->lastModified === null || $this->lastModified === $other->lastModified;
    }

    /**
     * @return array{totalSize: int, etag: ?string, lastModified: ?string}
     */
    public function toArray(): array
    {
        return ['totalSize' => $this->totalSize, 'etag' => $this->etag, 'lastModified' => $this->lastModified];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['totalSize'],
            isset($data['etag']) ? (string) $data['etag'] : null,
            isset($data['lastModified']) ? (string) $data['lastModified'] : null,
        );
    }
}
