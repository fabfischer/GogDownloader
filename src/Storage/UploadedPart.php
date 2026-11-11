<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * A part the backend acknowledged. Only acknowledged parts go into a checkpoint.
 */
final readonly class UploadedPart
{
    public function __construct(
        public int $number,
        public int $offset,
        public int $length,
        /** The backend's reference for the part: S3 ETag, Nextcloud chunk name, ... */
        public string $remoteId,
    ) {
    }

    /**
     * @return array{number: int, offset: int, length: int, remoteId: string}
     */
    public function toArray(): array
    {
        return ['number' => $this->number, 'offset' => $this->offset, 'length' => $this->length, 'remoteId' => $this->remoteId];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['number'], (int) $data['offset'], (int) $data['length'], (string) $data['remoteId']);
    }
}
