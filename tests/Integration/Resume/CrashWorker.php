<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Storage\ObjectKey;
use App\Transfer\TransferRequest;

/**
 * Runs one transfer in its own process (crash-worker.php) and stops at a chosen crash point: it prints a line with
 * MARKER and waits there, and WorkerCrashResumeTest kills it with SIGKILL. Nothing in the process gets to clean up,
 * as when a worker container dies. The checkpoints it saved are in a JSON file.
 */
final class CrashWorker
{
    /** Killed while the body of part 3 is half sent. Parts 1 and 2 are checkpointed. */
    public const SENDING_PART_3 = 'sending-part-3';

    /** Killed after the target acknowledged part 3, before the engine saved a checkpoint for it. */
    public const PART_3_STORED = 'part-3-stored';

    /** Killed after the target completed the upload, before the engine returned. Every part is checkpointed. */
    public const COMPLETED = 'completed';

    public const MARKER = 'CRASH-POINT';

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        if (count($argv) !== 6) {
            fwrite(STDERR, "usage: crash-worker.php <backend> <crash point> <seed> <object key> <checkpoint file>\n");

            return 2;
        }
        [, $backend, $crashPoint, $seed, $key, $checkpointFile] = $argv;

        $stop = static function (string $where): void {
            fwrite(STDOUT, sprintf("%s %s\n", self::MARKER, $where));
            fflush(STDOUT);
            // The test kills the process while it waits here.
            sleep(600);
            exit(3);
        };
        $stall = $crashPoint !== self::SENDING_PART_3 ? null : [
            'part' => 3,
            'afterBytes' => intdiv(CrashSetup::PART_SIZE, 2),
            'onStall' => static function (int $sent) use ($stop): void {
                $stop(sprintf('while sending part 3 (%d bytes handed to the HTTP client)', $sent));
            },
        ];
        $target = new ObservedTarget(
            CrashSetup::adapter($backend, $stall),
            afterWrite: $crashPoint !== self::PART_3_STORED ? null : static function (int $number) use ($stop): void {
                if ($number === 3) {
                    $stop('after the target stored part 3');
                }
            },
            afterComplete: $crashPoint !== self::COMPLETED ? null : static function () use ($stop): void {
                $stop('after the target completed the upload');
            },
        );

        CrashSetup::engine()->transfer(new TransferRequest(CrashSetup::source($seed), $target, ObjectKey::fromString($key), new JsonFileCheckpointStore($checkpointFile)));
        fwrite(STDOUT, "FINISHED without reaching the crash point\n");

        return 1;
    }
}
