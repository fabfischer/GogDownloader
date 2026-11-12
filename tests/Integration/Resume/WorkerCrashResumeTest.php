<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\ObjectKey;
use App\Tests\Integration\FakeGog\PatternData;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\S3\S3TestServer;
use App\Transfer\ReconcileOutcome;
use App\Transfer\Reconciler;
use App\Transfer\TransferRequest;
use App\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A worker process transfers a 10-part file and is killed with SIGKILL at a chosen moment (CrashWorker); another
 * worker then resumes from the last checkpoint the dead one saved. On S3 and on both kinds of Nextcloud:
 *
 * - killed while sending part 3: parts 1 and 2 are not sent again;
 * - killed after the target stored part 3 but before its checkpoint: part 3 is sent again (its bytes are not in the
 *   hash state), parts 1 and 2 are not;
 * - killed after completing, before reporting: nothing is sent again and the source is not needed.
 */
#[CoversNothing]
final class WorkerCrashResumeTest extends IntegrationTestCase
{
    /** @var list<string> files to remove after the test */
    private array $files = [];

    public static function setUpBeforeClass(): void
    {
        S3TestServer::ensureBucket();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function crashes(): iterable
    {
        foreach (CrashSetup::backends() as $backend) {
            yield $backend . ': killed while sending part 3' => [$backend, CrashWorker::SENDING_PART_3];
            yield $backend . ': killed after part 3 was stored, before its checkpoint' => [$backend, CrashWorker::PART_3_STORED];
            yield $backend . ': killed after completing, before reporting' => [$backend, CrashWorker::COMPLETED];
        }
    }

    #[DataProvider('crashes')]
    public function testAKilledWorkerResumesFromItsLastCheckpoint(string $backend, string $crashPoint): void
    {
        $seed = 'crash-' . self::runId();
        $key = self::newRevisionKey();
        $store = new JsonFileCheckpointStore($this->file('checkpoint', 'json'));
        $data = new PatternData($seed, CrashSetup::FILE_SIZE);

        $marker = $this->runWorkerUntilKilled($backend, $crashPoint, $seed, $key, $store);
        if ($crashPoint === CrashWorker::SENDING_PART_3) {
            // The kill came in the middle of part 3's body: half of it was handed to the HTTP client, not all of it.
            self::assertSame(1, preg_match('/while sending part 3 \((\d+) bytes/', $marker, $match), $marker);
            $sent = (int) ($match[1] ?? -1);
            self::assertGreaterThanOrEqual(intdiv(CrashSetup::PART_SIZE, 2), $sent);
            self::assertLessThan(CrashSetup::PART_SIZE, $sent);
        }

        // Another worker takes the job over: the last checkpoint the dead one saved, new connections.
        $checkpoint = $store->load();
        self::assertNotNull($checkpoint, 'The worker saved checkpoints before it died');
        self::assertSame(10, $checkpoint->session->partCount());
        $adapter = CrashSetup::adapter($backend);
        $reconciled = (new Reconciler())->reconcile($checkpoint, $adapter);
        $target = new ObservedTarget($adapter);
        $source = new RecordingSource(CrashSetup::source($seed));

        $result = CrashSetup::engine()->transfer(new TransferRequest($source, $target, $key, $store, resumeFrom: $checkpoint));

        self::assertSame(TransferStatus::Completed, $result->status);
        self::assertSame($data->hash('sha256'), $result->sha256);
        self::assertSame($data->hash('md5'), $result->md5);
        self::assertSame(CrashSetup::FILE_SIZE, $result->stored?->size);
        self::assertSame($data->hash('sha256'), $this->sha256Of($adapter, $key), 'The stored object has exactly the source bytes');
        self::assertSame(0, $target->begins, 'The upload session of the dead worker is used');

        if ($crashPoint === CrashWorker::COMPLETED) {
            self::assertCount(10, $checkpoint->parts);
            self::assertSame(ReconcileOutcome::AlreadyCompleted, $reconciled->outcome);
            self::assertTrue($result->recovered);
            self::assertSame([], $target->writtenParts, 'Nothing is sent again');
            self::assertSame(0, $target->completes);
            self::assertSame(0, $source->opens, 'Recovering a completed upload needs no source');

            return;
        }

        self::assertCount(2, $checkpoint->parts, 'Parts 1 and 2 were checkpointed before the worker died');
        self::assertSame(ReconcileOutcome::Resumable, $reconciled->outcome);
        self::assertSame($crashPoint === CrashWorker::PART_3_STORED ? [3] : [], $reconciled->extraParts, 'Parts the target holds beyond the checkpoint');
        self::assertFalse($result->recovered);
        self::assertSame(range(3, 10), $target->writtenParts, 'Parts 1 and 2 are not sent again');
        self::assertSame(array_map(static fn (int $number): int => ($number - 1) * CrashSetup::PART_SIZE, range(3, 10)), $source->readOffsets, 'Nor read again: the hash state comes from the checkpoint');
        self::assertSame(1, $target->completes);
    }

    /**
     * @return string the worker's line at its crash point
     */
    private function runWorkerUntilKilled(string $backend, string $crashPoint, string $seed, ObjectKey $key, JsonFileCheckpointStore $store): string
    {
        $errors = $this->file('crash-worker', 'log');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/crash-worker.php', $backend, $crashPoint, $seed, $key->value, $store->path],
            [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = '';

        try {
            $deadline = microtime(true) + 120.0;
            while (!str_contains($output, CrashWorker::MARKER)) {
                $read = [$pipes[1]];
                $write = null;
                $except = null;
                if (stream_select($read, $write, $except, 1) > 0) {
                    $chunk = (string) fread($pipes[1], 8192);
                    if ($chunk === '') {
                        self::fail(sprintf("The worker ended before its crash point.\nOutput: %s\nErrors: %s", $output, (string) file_get_contents($errors)));
                    }
                    $output .= $chunk;
                }
                if (microtime(true) > $deadline) {
                    self::fail(sprintf("The worker did not reach its crash point within 120 s.\nOutput: %s\nErrors: %s", $output, (string) file_get_contents($errors)));
                }
            }

            // The worker waits at its crash point. Kill it the hard way: no shutdown functions, destructors or
            // finally blocks run, and its connections just break.
            proc_terminate($process, 9);
            $status = $this->waitForExit($process);
            self::assertTrue($status['signaled'] && $status['termsig'] === 9, 'The worker died from SIGKILL');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            proc_close($process);
        }

        return $output;
    }

    /**
     * @param resource $process
     *
     * @return array{signaled: bool, termsig: int}
     */
    private function waitForExit($process): array
    {
        for ($attempt = 0; $attempt < 500; ++$attempt) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return ['signaled' => $status['signaled'], 'termsig' => $status['termsig']];
            }
            usleep(10_000);
        }
        self::fail('The worker did not die within 5 s of SIGKILL.');
    }

    private function file(string $name, string $extension): string
    {
        $path = sprintf('%s/%s-%s.%s', sys_get_temp_dir(), $name, self::runId(), $extension);
        $this->files[] = $path;
        $this->files[] = $path . '.tmp';

        return $path;
    }

    private function sha256Of(ArchiveStorageAdapter $adapter, ObjectKey $key): string
    {
        $hash = hash_init('sha256');
        foreach ($adapter->openRead($key) as $chunk) {
            hash_update($hash, $chunk);
        }

        return hash_final($hash);
    }
}
