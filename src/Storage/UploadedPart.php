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
        /**
         * The backend's checksum of the part as "algorithm:value", when the upload declared one and completing needs
         * it again (S3 with CRC32); null otherwise.
         */
        public ?string $checksum = null,
    ) {
    }

    /**
     * @return array{number: int, offset: int, length: int, remoteId: string, checksum: string|null}
     */
    public function toArray(): array
    {
        return ['number' => $this->number, 'offset' => $this->offset, 'length' => $this->length, 'remoteId' => $this->remoteId, 'checksum' => $this->checksum];
    }

    /**
     * @param array<string, mixed> $data checkpoints written before the checksum existed have no "checksum" entry
     */
    public static function fromArray(array $data): self
    {
        $checksum = $data['checksum'] ?? null;

        return new self((int) $data['number'], (int) $data['offset'], (int) $data['length'], (string) $data['remoteId'], $checksum === null ? null : (string) $checksum);
    }
}
