<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Storage\S3\S3StorageAdapter;
use App\Tests\Integration\Nextcloud\FaultInjectingHttpClient;
use App\Tests\Integration\Nextcloud\NextcloudTestServer;
use App\Tests\Integration\S3\FaultInjectingHttpHandler;
use App\Tests\Integration\S3\S3TestServer;
use App\Transfer\ChunkPlanner;
use App\Transfer\HostAllowlist;
use App\Transfer\RetryPolicy;
use App\Transfer\Source\HttpRangeSource;
use App\Transfer\TransferEngine;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;

/**
 * What the crashing worker (CrashWorker) and the test that resumes after it (WorkerCrashResumeTest) share: the file,
 * the targets, the source and the engine settings.
 */
final class CrashSetup
{
    public const MIB = 1048576;

    /** The smallest part S3 and Nextcloud accept. */
    public const PART_SIZE = 5 * self::MIB;

    /** Ten parts: nine of 5 MiB and a last one of 1 MiB and 17 bytes. */
    public const FILE_SIZE = 9 * self::PART_SIZE + self::MIB + 17;

    public const S3 = 's3';

    public const NEXTCLOUD_PLAIN = 'nextcloud-plain';

    public const NEXTCLOUD_OBJECTSTORE = 'nextcloud-objectstore';

    private const PREFIX = 'itest-crash';

    /**
     * @return list<string>
     */
    public static function backends(): array
    {
        return [self::S3, self::NEXTCLOUD_PLAIN, self::NEXTCLOUD_OBJECTSTORE];
    }

    /**
     * The adapter for a backend; with $stall, its HTTP transport calls the callback in the middle of sending the body
     * of the $stall['part']-th part upload.
     *
     * @param array{part: int, afterBytes: int, onStall: Closure(int): void}|null $stall
     */
    public static function adapter(string $backend, ?array $stall = null): ArchiveStorageAdapter
    {
        if ($backend === self::S3) {
            $handler = new FaultInjectingHttpHandler();
            if ($stall !== null) {
                $handler->stallOn('UploadPart', $stall['part'], $stall['afterBytes'], $stall['onStall']);
            }

            return S3StorageAdapter::create(S3TestServer::settings(self::PREFIX), ['http_handler' => $handler]);
        }

        $server = match ($backend) {
            self::NEXTCLOUD_PLAIN => NextcloudTestServer::PLAIN,
            self::NEXTCLOUD_OBJECTSTORE => NextcloudTestServer::OBJECTSTORE,
            default => throw new InvalidArgumentException(sprintf('Unknown backend "%s".', $backend)),
        };
        $client = new FaultInjectingHttpClient();
        if ($stall !== null) {
            // The adapter sends one PUT per chunk and no other PUT.
            $client->stallOn('PUT', $stall['part'], $stall['afterBytes'], $stall['onStall']);
        }

        return NextcloudStorageAdapter::create(NextcloudTestServer::settings($server, self::PREFIX), $client);
    }

    public static function source(string $seed): HttpRangeSource
    {
        $base = getenv('ITEST_FAKE_GOG_URL');
        if ($base === false || $base === '') {
            throw new RuntimeException('ITEST_FAKE_GOG_URL is not set. Run the integration tests with: make test-integration');
        }
        $url = sprintf('%s/files/%s/%d', $base, $seed, self::FILE_SIZE);

        return new HttpRangeSource(HttpClient::create(), static fn (): string => $url, new HostAllowlist(['fake-gog'], allowPlainHttp: true));
    }

    public static function engine(): TransferEngine
    {
        return new TransferEngine(new ChunkPlanner(self::PART_SIZE), new RetryPolicy(3, 0.0, 0.0), sleep: static function (float $seconds): void {
        });
    }
}
