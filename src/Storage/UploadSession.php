<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An open upload at the backend: S3 multipart upload ID, or Nextcloud upload directory. The values survive a process
 * restart through toArray()/fromArray(), so a checkpoint can store them and another worker can resume.
 */
final readonly class UploadSession
{
    private const FORMAT = 1;

    public function __construct(
        /** Backend name from StorageCapabilities::$backend, so a session is never resumed on another backend type. */
        public string $backend,
        public ObjectKey $key,
        /** The backend's own handle: S3 UploadId, Nextcloud upload directory name, or the fake's ID. */
        public string $sessionId,
        public int $totalSize,
        public int $partSize,
        public DateTimeImmutable $startedAt,
        /** @var array<string, string> backend-specific values that are not secrets */
        public array $extra = [],
    ) {
        if ($sessionId === '') {
            throw new InvalidArgumentException('An upload session needs an ID.');
        }
    }

    public function partCount(): int
    {
        return intdiv($this->totalSize + $this->partSize - 1, $this->partSize);
    }

    public function offsetOf(int $number): int
    {
        return ($number - 1) * $this->partSize;
    }

    public function lengthOf(int $number): int
    {
        return min($this->partSize, $this->totalSize - $this->offsetOf($number));
    }

    /**
     * @return array{format: int, backend: string, key: string, sessionId: string, totalSize: int, partSize: int, startedAt: string, extra: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'backend' => $this->backend,
            'key' => $this->key->value,
            'sessionId' => $this->sessionId,
            'totalSize' => $this->totalSize,
            'partSize' => $this->partSize,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'extra' => $this->extra,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Unknown upload session format.');
        }
        $extra = $data['extra'] ?? [];
        if (!is_array($extra)) {
            throw new InvalidArgumentException('Upload session extra values must be an array.');
        }

        return new self(
            backend: (string) $data['backend'],
            key: ObjectKey::fromString((string) $data['key']),
            sessionId: (string) $data['sessionId'],
            totalSize: (int) $data['totalSize'],
            partSize: (int) $data['partSize'],
            startedAt: new DateTimeImmutable((string) $data['startedAt']),
            extra: array_map(strval(...), $extra),
        );
    }
}
