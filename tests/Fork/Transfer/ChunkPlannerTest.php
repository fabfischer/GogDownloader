<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Storage\StorageCapabilities;
use App\Transfer\ChunkPlanner;
use App\Transfer\Exception\TransferPlanImpossible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChunkPlanner::class)]
final class ChunkPlannerTest extends TestCase
{
    private const MIB = 1024 * 1024;

    private const GIB = 1024 * self::MIB;

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function plans(): iterable
    {
        yield 'one byte' => [1, 16 * self::MIB, 1];
        yield '100 MiB' => [100 * self::MIB, 16 * self::MIB, 7];
        yield 'exactly 10,000 default parts' => [10000 * 16 * self::MIB, 16 * self::MIB, 10000];
        yield 'one byte more than 10,000 parts' => [10000 * 16 * self::MIB + 1, 17 * self::MIB, 9412];
        yield '200 GiB' => [200 * self::GIB, 21 * self::MIB, 9753];
    }

    #[DataProvider('plans')]
    public function testPartSizeStaysWithinTheS3PartLimit(int $totalSize, int $partSize, int $partCount): void
    {
        $plan = (new ChunkPlanner())->plan($totalSize, $this->s3());

        self::assertSame($partSize, $plan->partSize);
        self::assertSame($partCount, $plan->partCount());
        self::assertLessThanOrEqual(10000, $plan->partCount());
        self::assertSame($totalSize, ($plan->partCount() - 1) * $plan->partSize + $plan->lastPartSize());
        self::assertGreaterThan(0, $plan->lastPartSize());
    }

    public function testThePreferredSizeIsRaisedToTheTargetMinimum(): void
    {
        $plan = (new ChunkPlanner(preferredPartSize: self::MIB))->plan(100 * self::MIB, $this->s3());

        self::assertSame(5 * self::MIB, $plan->partSize);
    }

    public function testAFileThatNeedsPartsAboveTheMemoryBudgetIsRefused(): void
    {
        $this->expectException(TransferPlanImpossible::class);
        (new ChunkPlanner())->plan(700 * self::GIB, $this->s3());
    }

    public function testAFileThatNeedsPartsAboveTheTargetMaximumIsRefused(): void
    {
        $target = new StorageCapabilities('small', 5 * self::MIB, 8 * self::MIB, 10000, true, null, false, false);

        $this->expectException(TransferPlanImpossible::class);
        (new ChunkPlanner())->plan(100 * self::GIB, $target);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(TransferPlanImpossible::class);
        (new ChunkPlanner())->plan(0, $this->s3());
    }

    private function s3(): StorageCapabilities
    {
        return new StorageCapabilities('s3', 5 * self::MIB, 5 * self::GIB, 10000, true, null, true, false);
    }
}
