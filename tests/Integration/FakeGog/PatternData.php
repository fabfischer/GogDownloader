<?php

declare(strict_types=1);

namespace App\Tests\Integration\FakeGog;

use Generator;

/**
 * Deterministic synthetic file content of any size, computed per byte range so that even multi-GiB files are never
 * held in memory. Every 1 MiB block repeats a 32-byte SHA-256 of (seed, block index), so a part placed at the wrong
 * offset changes the hash of the whole file.
 */
final readonly class PatternData
{
    public const BLOCK = 1048576;

    public function __construct(
        private string $seed,
        public int $size,
    ) {
    }

    /**
     * Bytes [$offset, $offset + $length), clipped to the file size.
     */
    public function slice(int $offset, int $length): string
    {
        $out = '';
        foreach ($this->stream($offset, $length) as $chunk) {
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Yields the range in pieces of at most one block.
     *
     * @return Generator<string>
     */
    public function stream(int $offset, int $length, int $piece = self::BLOCK): Generator
    {
        $end = min($this->size, $offset + $length);
        for ($position = max(0, $offset); $position < $end;) {
            $block = intdiv($position, self::BLOCK);
            $within = $position % self::BLOCK;
            $take = min($piece, self::BLOCK - $within, $end - $position);
            yield substr($this->block($block), $within, $take);
            $position += $take;
        }
    }

    public function hash(string $algorithm): string
    {
        $context = hash_init($algorithm);
        foreach ($this->stream(0, $this->size) as $chunk) {
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    private function block(int $index): string
    {
        return str_repeat(hash('sha256', $this->seed . '/' . $index, true), self::BLOCK / 32);
    }
}
