<?php

declare(strict_types=1);

namespace App\Transfer;

use Closure;
use InvalidArgumentException;

/**
 * How often and how long to wait before retrying a part after a temporary failure: exponential backoff with jitter,
 * or the server's Retry-After when it sends one (capped).
 */
final readonly class RetryPolicy
{
    /** @var Closure(): float random factor in [0, 1) */
    private Closure $random;

    /**
     * @param (Closure(): float)|null $random
     */
    public function __construct(
        public int $maxAttempts = 5,
        private float $baseDelaySeconds = 1.0,
        private float $maxDelaySeconds = 60.0,
        ?Closure $random = null,
    ) {
        if ($maxAttempts < 1 || $baseDelaySeconds < 0 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException('Invalid retry policy.');
        }
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Seconds to wait before attempt $attempt + 1, after $attempt failed attempts.
     */
    public function delayAfter(int $attempt, ?int $retryAfterSeconds = null): float
    {
        if ($retryAfterSeconds !== null) {
            return min((float) $retryAfterSeconds, $this->maxDelaySeconds);
        }
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * 2 ** max(0, $attempt - 1));

        // "Equal jitter": half fixed, half random, so retries of many workers spread out.
        return $delay / 2 + $delay / 2 * ($this->random)();
    }
}
