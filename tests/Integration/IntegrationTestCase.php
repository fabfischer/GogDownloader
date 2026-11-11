<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Storage\ObjectKey;
use App\Storage\ObjectKeyBuilder;
use App\Transfer\HostAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests against the disposable test services of the "test" compose profile (make test-integration).
 * Endpoints and the fixed test credentials come from ITEST_* variables set in docker/dev/compose.yaml.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::fail(sprintf('%s is not set. Run the integration tests with: make test-integration', $name));
        }

        return $value;
    }

    /**
     * A fresh ID so that request counters and object names of different tests never meet.
     */
    protected static function runId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * URL of a file on the fake GOG source; the query switches faults on (see FakeGog/router.php).
     *
     * @param array<string, string|int> $query
     */
    protected static function fakeGogFileUrl(string $seed, int $size, array $query = []): string
    {
        return sprintf('%s/files/%s/%d%s', self::env('ITEST_FAKE_GOG_URL'), $seed, $size, $query === [] ? '' : '?' . http_build_query($query));
    }

    protected static function fakeGogHosts(): HostAllowlist
    {
        return new HostAllowlist(['fake-gog'], allowPlainHttp: true);
    }

    /**
     * A fresh revision key in the archive layout, so objects of different tests and runs never meet.
     */
    protected static function newRevisionKey(string $fileName = 'file.bin'): ObjectKey
    {
        $uuid = static fn (): string => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

        return (new ObjectKeyBuilder())->revisionKey(7, 'Integration test', $uuid(), $uuid(), $fileName);
    }
}
