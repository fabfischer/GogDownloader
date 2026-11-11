<?php

declare(strict_types=1);

namespace App\Tests\Upstream;

use App\AppKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real upstream entry point (bin/app.php) in a separate process: boots the kernel, compiles the
 * container from config/services.yaml and lists the commands. Catches broken service wiring after dependency
 * bumps.
 *
 * Offline by design: without bin/appversion the version is "dev-version", and upstream's PreCommandListener
 * only contacts GitHub for other versions.
 */
#[CoversClass(AppKernel::class)]
final class CliKernelSmokeTest extends TestCase
{
    public function testTheContainerCompilesAndAllUpstreamCommandsAreRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileDoesNotExist($root . '/bin/appversion', 'bin/appversion would enable the GitHub version check');

        exec(sprintf('%s %s list --raw 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root . '/bin/app.php')), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $commands = array_map(static fn (string $line): string => strtok($line, ' ') ?: '', $output);
        foreach (['code-login', 'config', 'download', 'download-saves', 'games', 'languages', 'login', 'total-size', 'update-database'] as $expected) {
            self::assertContains($expected, $commands);
        }
    }
}
