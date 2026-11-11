<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\StorageCapabilities;
use App\Transfer\Exception\TransferPlanImpossible;
use InvalidArgumentException;

/**
 * Picks the part size for a file: the preferred size (16 MiB by default), raised to the target's minimum and, for very
 * large files, raised further (in whole alignment steps) so the file fits into the target's maximum part count.
 * Refuses plans whose parts would exceed the target's maximum part size or the memory budget for one part.
 */
final readonly class ChunkPlanner
{
    public const DEFAULT_PART_SIZE = 16 * 1024 * 1024;

    public const ALIGNMENT = 1024 * 1024;

    public function __construct(
        private int $preferredPartSize = self::DEFAULT_PART_SIZE,
        /** Upper bound for one part in memory; parts are held in RAM while they are sent. */
        private int $maxPartSizeInMemory = 64 * 1024 * 1024,
        /** Part sizes that have to grow are rounded up to a multiple of this. */
        private int $alignment = self::ALIGNMENT,
    ) {
        if ($preferredPartSize < 1 || $maxPartSizeInMemory < 1 || $alignment < 1) {
            throw new InvalidArgumentException('Part sizes and alignment must be positive.');
        }
    }

    public function plan(int $totalSize, StorageCapabilities $target): PartPlan
    {
        if ($totalSize < 1) {
            throw new TransferPlanImpossible('A transfer needs at least one byte.');
        }

        $partSize = max($this->preferredPartSize, $target->minPartSize);
        $needed = intdiv($totalSize + $target->maxParts - 1, $target->maxParts);
        if ($needed > $partSize) {
            $partSize = intdiv($needed + $this->alignment - 1, $this->alignment) * $this->alignment;
        }

        $limit = min($target->maxPartSize, $this->maxPartSizeInMemory);
        if ($partSize > $limit) {
            throw new TransferPlanImpossible(sprintf(
                '%d bytes need parts of %d bytes to stay within %d parts, but parts may have at most %d bytes (target limit %d, memory budget %d).',
                $totalSize,
                $partSize,
                $target->maxParts,
                $limit,
                $target->maxPartSize,
                $this->maxPartSizeInMemory,
            ));
        }

        return new PartPlan($totalSize, $partSize);
    }
}
