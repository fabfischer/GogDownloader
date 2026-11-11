<?php

declare(strict_types=1);

namespace App\Transfer;

use Closure;

/**
 * Keeps the average read rate at or below $bytesPerSecond by sleeping when the transfer is ahead of schedule.
 * A null rate means no limit.
 */
final class BandwidthLimiter
{
    private ?float $startedAt = null;

    private int $bytes = 0;

    /**
     * @param Closure(): float     $now   seconds, monotonic
     * @param Closure(float): void $sleep
     */
    public function __construct(
        private readonly ?int $bytesPerSecond,
        private readonly Closure $now,
        private readonly Closure $sleep,
    ) {
    }

    public static function unlimited(): self
    {
        return new self(null, static fn (): float => 0.0, static function (float $seconds): void {
        });
    }

    public function consume(int $bytes): void
    {
        if ($this->bytesPerSecond === null || $this->bytesPerSecond < 1) {
            return;
        }
        $this->startedAt ??= ($this->now)();
        $this->bytes += $bytes;

        $due = $this->startedAt + $this->bytes / $this->bytesPerSecond;
        $ahead = $due - ($this->now)();
        if ($ahead > 0) {
            ($this->sleep)($ahead);
        }
    }
}
