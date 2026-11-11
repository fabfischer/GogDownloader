<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
}
