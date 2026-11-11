<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Storage\InMemory\InMemoryStorageAdapter;
use App\Storage\ObjectKeyBuilder;
use App\Tests\Integration\FakeGog\PatternData;
use App\Transfer\Exception\RangeNotSupported;
use App\Transfer\HostAllowlist;
use App\Transfer\InMemoryCheckpointStore;
use App\Transfer\RetryPolicy;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\Source\SequentialSource;
use App\Transfer\TransferEngine;
use App\Transfer\TransferRequest;
use App\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The transfer engine reading from the fake GOG source over HTTP, into the in-memory adapter. Transfers into the real
 * backends: S3/TransferToS3Test.
 */
#[CoversNothing]
final class TransferThroughHarnessTest extends IntegrationTestCase
{
    private const MIB = 1048576;

    public function testAFileStreamsThroughTheEngineInRanges(): void
    {
        $size = 40 * self::MIB + 123;
        $result = $this->transfer(new HttpRangeSource(HttpClient::create(), fn (): string => $this->url('engine', $size), $this->hosts()));

        self::assertSame(TransferStatus::Completed, $result['result']->status);
        self::assertSame((new PatternData('engine', $size))->hash('sha256'), $result['result']->sha256);
        self::assertCount(1 + 3, $result['checkpoints']->saved);
    }

    public function testAFaultInTheMiddleOfATransferIsRetried(): void
    {
        $size = 20 * self::MIB;
        $url = $this->url('midfault', $size, ['fault' => 'early-eof', 'skip' => 2, 'fail-times' => 1, 'run' => self::runId()]);

        $result = $this->transfer(new HttpRangeSource(HttpClient::create(), static fn (): string => $url, $this->hosts()));

        self::assertSame((new PatternData('midfault', $size))->hash('sha256'), $result['result']->sha256);
    }

    public function testASourceWithoutRangeSupportFallsBackToOneSequentialRead(): void
    {
        $size = 18 * self::MIB;
        $url = $this->url('norange', $size, ['fault' => 'no-range', 'run' => self::runId()]);

        try {
            (new HttpRangeSource(HttpClient::create(), static fn (): string => $url, $this->hosts()))->open();
            self::fail('Expected RangeNotSupported');
        } catch (RangeNotSupported) {
        }
        $result = $this->transfer(SequentialSource::http(HttpClient::create(), static fn (): string => $url, $this->hosts()));

        self::assertSame((new PatternData('norange', $size))->hash('sha256'), $result['result']->sha256);
    }

    /**
     * @return array{result: \App\Transfer\TransferResult, checkpoints: InMemoryCheckpointStore}
     */
    private function transfer(\App\Transfer\TransferSource $source): array
    {
        $checkpoints = new InMemoryCheckpointStore();
        $key = (new ObjectKeyBuilder())->revisionKey(1, 'Harness', '0190f5e2-7c1a-7d3e-9a4b-1c2d3e4f5a6b', '0190f5e2-8d2b-7e4f-8b5c-' . substr(self::runId(), 0, 12), 'setup.bin');
        $engine = new TransferEngine(retry: new RetryPolicy(3, 0.1, 0.5), sleep: static fn (float $seconds) => usleep((int) ($seconds * 1e6)));

        return ['result' => $engine->transfer(new TransferRequest($source, new InMemoryStorageAdapter(), $key, $checkpoints)), 'checkpoints' => $checkpoints];
    }

    private function hosts(): HostAllowlist
    {
        return self::fakeGogHosts();
    }

    /**
     * @param array<string, string|int> $query
     */
    private function url(string $seed, int $size, array $query = []): string
    {
        return self::fakeGogFileUrl($seed, $size, $query);
    }
}
