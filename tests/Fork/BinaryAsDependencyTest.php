<?php

declare(strict_types=1);

namespace App\Tests\Fork;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * bin/app.php must also run when this package is installed as a Composer dependency. Then there is no vendor/
 * next to bin/, and Composer's bin proxy (vendor/bin/app.php) passes the autoloader path in
 * $GLOBALS['_composer_autoload_path'] (Composer 2.2+):
 * https://getcomposer.org/doc/articles/vendor-binaries.md#finding-the-composer-autoloader-from-a-binary
 *
 * The test copies bin/app.php into an empty directory and includes it the way the proxy does.
 */
#[CoversNothing]
final class BinaryAsDependencyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gog-downloader-bin-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/bin', 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/bin/app.php');
        @unlink($this->directory . '/proxy.php');
        @rmdir($this->directory . '/bin');
        @rmdir($this->directory);
    }

    public function testTheBinaryUsesTheAutoloaderComposerPassesIn(): void
    {
        $root = dirname(__DIR__, 2);
        copy($root . '/bin/app.php', $this->directory . '/bin/app.php');
        file_put_contents($this->directory . '/proxy.php', sprintf(
            "<?php\n\$GLOBALS['_composer_autoload_path'] = %s;\ninclude %s;\n",
            var_export($root . '/vendor/autoload.php', true),
            var_export($this->directory . '/bin/app.php', true),
        ));

        exec(sprintf('%s %s list --raw 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($this->directory . '/proxy.php')), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $commands = array_map(static fn (string $line): string => strtok($line, ' ') ?: '', $output);
        self::assertContains('download', $commands);
    }
}
