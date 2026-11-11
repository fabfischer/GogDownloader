<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Transfer\BandwidthLimiter;
use App\Transfer\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(BandwidthLimiter::class)]
final class RetryAndBandwidthTest extends TestCase
{
    public function testBackoffDoublesWithJitterAndHonoursRetryAfter(): void
    {
        $noJitter = new RetryPolicy(5, 1.0, 60.0, static fn (): float => 0.0);
        $fullJitter = new RetryPolicy(5, 1.0, 60.0, static fn (): float => 0.999999);

        self::assertSame([0.5, 1.0, 2.0, 4.0], array_map($noJitter->delayAfter(...), [1, 2, 3, 4]));
        self::assertEqualsWithDelta(8.0, $fullJitter->delayAfter(4), 0.0001);
        self::assertSame(30.0, $noJitter->delayAfter(10)); // capped at 60, half of it fixed
        self::assertSame(7.0, $noJitter->delayAfter(1, 7));
        self::assertSame(60.0, $noJitter->delayAfter(1, 3600));
    }

    public function testTheLimiterSleepsWhenAheadOfTheRate(): void
    {
        $clock = 0.0;
        $slept = [];
        $limiter = new BandwidthLimiter(
            5_000_000,
            static function () use (&$clock): float {
                return $clock;
            },
            static function (float $seconds) use (&$clock, &$slept): void {
                $slept[] = $seconds;
                $clock += $seconds;
            },
        );

        for ($i = 0; $i < 10; ++$i) {
            $limiter->consume(1_000_000);
        }

        self::assertEqualsWithDelta(2.0, array_sum($slept), 0.000001);
    }

    public function testWithoutALimitNothingSleeps(): void
    {
        BandwidthLimiter::unlimited()->consume(PHP_INT_MAX);
        $this->addToAssertionCount(1);
    }
}
