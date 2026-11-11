<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Storage\Nextcloud\NextcloudSettings;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The two test Nextclouds of the "test" compose profile: "plain" keeps files on its local disk and assembles chunks on
 * the final MOVE; "objectstore" keeps them in a bucket on the S3 test server and uses Valkey as its distributed cache,
 * which switches on Nextcloud's chunked-write code path. URLs and the fixed test credentials come from the
 * ITEST_NEXTCLOUD_* variables in docker/dev/compose.yaml.
 */
final class NextcloudTestServer
{
    public const PLAIN = 'plain';

    public const OBJECTSTORE = 'objectstore';

    /** @var array<string, string> */
    private static array $appPasswords = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        yield 'local storage' => [self::PLAIN];
        yield 'object storage' => [self::OBJECTSTORE];
    }

    public static function url(string $server): string
    {
        return self::env($server === self::OBJECTSTORE ? 'ITEST_NEXTCLOUD_OBJECTSTORE_URL' : 'ITEST_NEXTCLOUD_URL');
    }

    /**
     * Settings with an app password, as for a real target. $password replaces it, for tests of wrong credentials.
     */
    public static function settings(string $server, string $prefix, ?string $password = null, bool $quotaUser = false): NextcloudSettings
    {
        return new NextcloudSettings(
            baseUrl: self::url($server),
            loginName: self::login($quotaUser)[0],
            appPassword: $password ?? self::appPassword($server, $quotaUser),
            prefix: $prefix,
            allowPlainHttp: true,
            idleTimeoutSeconds: 60.0,
            assemblyTimeoutSeconds: 120.0,
        );
    }

    /**
     * An app password for the test account, fetched once per server and account. Nextcloud checks the account password
     * with a slow password hash on every request (about 0.6 s on the test containers); an app password takes 0.03 s.
     */
    public static function appPassword(string $server, bool $quotaUser = false): string
    {
        $cacheKey = $server . ($quotaUser ? ':quota' : '');
        if (!isset(self::$appPasswords[$cacheKey])) {
            $response = HttpClient::create(['timeout' => 60])->request('GET', self::url($server) . '/ocs/v2.php/core/getapppassword?format=json', [
                'auth_basic' => self::login($quotaUser),
                'headers' => ['OCS-APIRequest' => 'true'],
            ]);
            $data = json_decode($response->getContent(false), true);
            $password = is_array($data) ? ($data['ocs']['data']['apppassword'] ?? null) : null;
            if (!is_string($password) || $password === '') {
                throw new RuntimeException(sprintf('The test Nextcloud "%s" gave no app password (HTTP %d).', $server, $response->getStatusCode()));
            }
            self::$appPasswords[$cacheKey] = $password;
        }

        return self::$appPasswords[$cacheKey];
    }

    /**
     * A plain client with the test admin's app password, for looking at the server from outside the adapter.
     */
    public static function client(string $server): HttpClientInterface
    {
        return HttpClient::create(['auth_basic' => [self::env('ITEST_NEXTCLOUD_USER'), self::appPassword($server)], 'max_redirects' => 0, 'timeout' => 60]);
    }

    public static function filesUrl(string $server, string $path): string
    {
        return self::url($server) . '/remote.php/dav/files/' . rawurlencode(self::env('ITEST_NEXTCLOUD_USER')) . '/'
            . implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    public static function uploadUrl(string $server, string $sessionId): string
    {
        return self::url($server) . '/remote.php/dav/uploads/' . rawurlencode(self::env('ITEST_NEXTCLOUD_USER')) . '/' . $sessionId;
    }

    /**
     * Empties the object-storage Nextcloud's distributed cache, as when its entries expire or the cache restarts.
     * Nextcloud then no longer knows its open chunked uploads.
     */
    public static function flushCache(): void
    {
        $socket = @fsockopen(self::env('ITEST_VALKEY_HOST'), 6379, $errno, $error, 5.0);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Cannot reach the test cache: %s', $error));
        }

        try {
            fwrite($socket, "*1\r\n\$8\r\nFLUSHALL\r\n");
            $answer = fgets($socket);
        } finally {
            fclose($socket);
        }
        if ($answer === false || !str_starts_with($answer, '+OK')) {
            throw new RuntimeException('The test cache did not confirm FLUSHALL.');
        }
    }

    /**
     * @return array{string, string} login name and account password
     */
    private static function login(bool $quotaUser): array
    {
        return $quotaUser
            ? [self::env('ITEST_NEXTCLOUD_QUOTA_USER'), self::env('ITEST_NEXTCLOUD_QUOTA_PASSWORD')]
            : [self::env('ITEST_NEXTCLOUD_USER'), self::env('ITEST_NEXTCLOUD_PASSWORD')];
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('%s is not set. Run the integration tests with: make test-integration', $name));
        }

        return $value;
    }
}
