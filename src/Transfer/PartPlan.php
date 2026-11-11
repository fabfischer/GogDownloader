<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * How a file of $totalSize bytes is cut into parts: every part has $partSize bytes except possibly the last one.
 */
final readonly class PartPlan
{
    public function __construct(
        public int $totalSize,
        public int $partSize,
    ) {
    }

    public function partCount(): int
    {
        return intdiv($this->totalSize + $this->partSize - 1, $this->partSize);
    }

    public function lastPartSize(): int
    {
        return $this->totalSize - ($this->partCount() - 1) * $this->partSize;
    }
}
