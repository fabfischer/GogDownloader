<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/**
 * The bytes of one part, held in a seekable memory stream (ADR "upload part bodies in memory"): backends may read a
 * part more than once (checksums, request signing, retries), and a PHP string or a caching stream would end up in
 * temporary files.
 */
final readonly class PartBody
{
    /**
     * @param resource $stream seekable, readable, positioned anywhere; adapters rewind it before reading
     */
    private function __construct(
        public mixed $stream,
        public int $length,
    ) {
    }

    /**
     * @param resource $stream
     */
    public static function fromStream(mixed $stream, int $length): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A part body must be a stream resource.');
        }
        $meta = stream_get_meta_data($stream);
        if (!$meta['seekable']) {
            throw new InvalidArgumentException('A part body must be seekable.');
        }
        if ($length < 1) {
            throw new InvalidArgumentException('A part body must not be empty.');
        }
        $stat = fstat($stream);
        if ($stat !== false && $stat['size'] !== $length) {
            throw new InvalidArgumentException(sprintf('The part body holds %d bytes, not the declared %d.', $stat['size'], $length));
        }

        return new self($stream, $length);
    }

    /**
     * Copies bytes into a new php://memory stream. Meant for tests and small values; the transfer engine fills the
     * memory stream directly from the source.
     */
    public static function fromString(string $bytes): self
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new InvalidArgumentException('Could not open a memory stream.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return self::fromStream($stream, strlen($bytes));
    }

    /**
     * Reads the whole part, from the beginning. Only for backends and tests that need the bytes as a string.
     */
    public function contents(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }
}
