<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\S3;

use App\Storage\Exception\StorageException;
use App\Storage\ObjectKey;
use App\Storage\S3\S3Settings;
use App\Storage\S3\S3StorageAdapter;
use App\Storage\UploadRequest;
use PHPUnit\Framework\TestCase;

/**
 * The S3 client follows no redirect (SSRF): against a local endpoint that redirects every request to another path, the
 * real AWS SDK client with its Guzzle handler must fail, and nothing may reach the redirect's target.
 */
final class S3RedirectTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $endpoint = '';

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$log = (string) tempnam(sys_get_temp_dir(), 's3-redirect-canary-');
        for ($attempt = 0; $attempt < 10 && self::$server === null; ++$attempt) {
            $port = random_int(20000, 40000);
            $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/redirect-server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, env_vars: ['REDIRECT_CANARY_LOG' => self::$log] + getenv());
            for ($wait = 0; is_resource($process) && $wait < 50; ++$wait) {
                $socket = @fsockopen('127.0.0.1', $port, timeout: 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server = $process;
                    self::$endpoint = 'http://127.0.0.1:' . $port;
                    break;
                }
                usleep(50_000);
            }
            if (self::$server === null && is_resource($process)) {
                proc_terminate($process);
            }
        }
        self::assertNotNull(self::$server, 'The local redirect server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        @unlink(self::$log);
    }

    public function testNoRequestFollowsARedirect(): void
    {
        $adapter = S3StorageAdapter::create(new S3Settings(
            bucket: 'archive',
            region: 'us-east-1',
            accessKeyId: 'redirect-test-key',
            secretAccessKey: 'redirect-test-secret-not-real',
            endpoint: self::$endpoint,
            pathStyle: true,
            allowPlainHttp: true,
        ));
        $key = ObjectKey::fromString('games/setup.exe');
        $failures = 0;
        $calls = [
            static fn () => $adapter->stat($key),
            static function () use ($adapter, $key): void {
                foreach ($adapter->openRead($key) as $chunk) {
                }
            },
            static fn () => $adapter->begin(new UploadRequest($key, 16, 16)),
        ];
        foreach ($calls as $call) {
            try {
                $call();
            } catch (StorageException) {
                ++$failures;
            }
        }

        self::assertSame(3, $failures, 'every call fails on the redirect');
        self::assertSame('', (string) file_get_contents(self::$log), 'a request followed the redirect');
    }
}
