<?php

declare(strict_types=1);

namespace App\Storage\Nextcloud;

use App\Storage\Exception\InvalidObjectKey;
use App\Storage\ObjectKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * One Nextcloud target: the server, the account (login name and app password), and the folder in that account's
 * files that holds the archive.
 *
 * The login name authenticates; the user ID names the account's WebDAV folders (/remote.php/dav/files/{userId}). They
 * are the same for most accounts, but differ for example with LDAP or e-mail logins, so the user ID can be set on its
 * own.
 *
 * Part limits default to Nextcloud's documented chunking limits (5 MB to 5 GB per chunk except the last, chunk numbers
 * 1 to 10,000). A reverse proxy in front of Nextcloud often allows smaller requests; then the maximum part size has to
 * come down.
 *
 * The app password never appears in exception messages or debug output.
 */
final readonly class NextcloudSettings
{
    public const MIB = 1048576;

    /** Nextcloud documents chunks of "5MB" to "5GB"; these defaults are safe whether MB means 10^6 or 2^20 bytes. */
    public const DEFAULT_MIN_PART_SIZE = 5 * self::MIB;

    public const DEFAULT_MAX_PART_SIZE = 5_000_000_000;

    public const DEFAULT_MAX_PARTS = 10000;

    /** Nextcloud drops an upload folder after this long without a write (system setting cache_chunk_gc_ttl). */
    public const DEFAULT_SESSION_IDLE_TIMEOUT = 86400;

    /** Base URL without trailing slash, e.g. https://cloud.example.com or https://example.com/nextcloud. */
    public string $baseUrl;

    /** The account's user ID, used in WebDAV paths. */
    public string $userId;

    /** Folder below the account's files root, without leading or trailing slash; empty for the files root. */
    public string $prefix;

    public function __construct(
        string $baseUrl,
        public string $loginName,
        #[SensitiveParameter]
        public string $appPassword,
        string $prefix = '',
        ?string $userId = null,
        /** Whether to check the server's TLS certificate. Only for test setups; never turn it off for real targets. */
        public bool $verifyTls = true,
        /** Path to a CA bundle for servers with a private certificate authority. */
        public ?string $caBundle = null,
        /** Allows an http:// base URL, for local test servers. */
        public bool $allowPlainHttp = false,
        public int $minPartSize = self::DEFAULT_MIN_PART_SIZE,
        public int $maxPartSize = self::DEFAULT_MAX_PART_SIZE,
        public int $maxParts = self::DEFAULT_MAX_PARTS,
        /** What the server's cache_chunk_gc_ttl is set to, if an administrator changed it. */
        public int $sessionIdleTimeoutSeconds = self::DEFAULT_SESSION_IDLE_TIMEOUT,
        /** How long a request may go without any data moving, connecting included, before it counts as failed. */
        public float $idleTimeoutSeconds = 120.0,
        /**
         * The same for the final MOVE, during which the server assembles the file and sends nothing. Assembling a
         * large file on slow storage takes minutes.
         */
        public float $assemblyTimeoutSeconds = 3600.0,
    ) {
        if ($loginName === '' || $appPassword === '') {
            throw new InvalidArgumentException('The login name and the app password must not be empty.');
        }
        if ($caBundle === '') {
            throw new InvalidArgumentException('The CA bundle path must not be empty; use null for the system CAs.');
        }
        if ($minPartSize < 1 || $maxPartSize < $minPartSize || $maxParts < 1 || $maxParts > 10000) {
            throw new InvalidArgumentException('Part limits must be positive, the maximum not below the minimum, and at most 10,000 parts.');
        }
        if ($sessionIdleTimeoutSeconds < 60) {
            throw new InvalidArgumentException('The session idle timeout must be at least a minute.');
        }
        if ($idleTimeoutSeconds <= 0 || $assemblyTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Timeouts must be positive.');
        }

        $this->baseUrl = self::checkBaseUrl($baseUrl, $allowPlainHttp);
        $this->userId = self::checkUserId($userId ?? $loginName);
        $this->prefix = self::checkPrefix($prefix);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        $values['appPassword'] = '***';

        return $values;
    }

    /**
     * What identifies the target in an upload session: a session is only used again against the same server, account
     * and folder.
     *
     * @return array{baseUrl: string, userId: string, prefix: string}
     */
    public function targetIdentity(): array
    {
        return ['baseUrl' => $this->baseUrl, 'userId' => $this->userId, 'prefix' => $this->prefix];
    }

    private static function checkBaseUrl(string $url, bool $allowPlainHttp): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('The base URL must be an absolute URL like https://cloud.example.com.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https' && !($scheme === 'http' && $allowPlainHttp)) {
            throw new InvalidArgumentException('The base URL must use https (http only for local test servers).');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The base URL must not contain credentials, a query or a fragment.');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_contains($path, 'remote.php')) {
            throw new InvalidArgumentException('The base URL is the address of Nextcloud itself, without /remote.php/…');
        }

        return rtrim($url, '/');
    }

    private static function checkUserId(string $userId): string
    {
        if ($userId === '' || preg_match('#[/\\\\\x00-\x1F\x7F]#', $userId) === 1) {
            throw new InvalidArgumentException('The user ID must not be empty or contain slashes or control characters.');
        }

        return $userId;
    }

    private static function checkPrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return '';
        }

        try {
            return ObjectKey::fromString($prefix)->value;
        } catch (InvalidObjectKey $e) {
            throw new InvalidArgumentException('The prefix is not a valid folder path: ' . $e->getMessage(), 0, $e);
        }
    }
}
