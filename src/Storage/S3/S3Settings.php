<?php

declare(strict_types=1);

namespace App\Storage\S3;

use App\Storage\Exception\InvalidObjectKey;
use App\Storage\ObjectKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * One S3 or S3-compatible target: where objects go, how to reach the service, and which optional S3 features to use.
 *
 * Defaults depend on the endpoint. For AWS (no endpoint, or an amazonaws.com host) CRC32 part checksums and
 * conditional create are on; for other endpoints both are off unless enabled, because S3-compatible servers support
 * them unevenly. Part limits default to AWS's multipart limits and can be lowered for providers with smaller ones.
 *
 * Credentials never appear in exception messages or debug output.
 */
final readonly class S3Settings
{
    public const MIB = 1048576;

    public const DEFAULT_MIN_PART_SIZE = 5 * self::MIB;

    public const DEFAULT_MAX_PART_SIZE = 5 * 1024 * self::MIB;

    public const DEFAULT_MAX_PARTS = 10000;

    /** S3 limits object keys to 1,024 bytes of UTF-8, prefix included. */
    public const MAX_KEY_BYTES = 1024;

    /** Key prefix without leading or trailing slash; empty for the bucket root. */
    public string $prefix;

    /** Endpoint URL without trailing slash, or null for AWS S3 in the given region. */
    public ?string $endpoint;

    public S3ChecksumMode $checksums;

    /** Whether CompleteMultipartUpload sends "If-None-Match: *", so the server itself refuses to replace an object. */
    public bool $conditionalCreate;

    public function __construct(
        public string $bucket,
        public string $region,
        #[SensitiveParameter]
        public string $accessKeyId,
        #[SensitiveParameter]
        public string $secretAccessKey,
        #[SensitiveParameter]
        public ?string $sessionToken = null,
        ?string $endpoint = null,
        string $prefix = '',
        /** Path-style addressing (https://host/bucket/key), which many S3-compatible servers need. */
        public bool $pathStyle = false,
        /** Whether to check the server's TLS certificate. Only for test setups; never turn it off for real targets. */
        public bool $verifyTls = true,
        /** Path to a CA bundle for servers with a private certificate authority. */
        public ?string $caBundle = null,
        /** Allows an http:// endpoint, for local test servers. */
        public bool $allowPlainHttp = false,
        /** Storage class for new objects, only when the provider supports it (e.g. STANDARD_IA); null leaves the default. */
        public ?string $storageClass = null,
        ?S3ChecksumMode $checksums = null,
        ?bool $conditionalCreate = null,
        public int $minPartSize = self::DEFAULT_MIN_PART_SIZE,
        public int $maxPartSize = self::DEFAULT_MAX_PART_SIZE,
        public int $maxParts = self::DEFAULT_MAX_PARTS,
        public float $connectTimeoutSeconds = 10.0,
        /** Upper bound for one request (one part upload); reads in openRead() use it as an idle timeout instead. */
        public float $requestTimeoutSeconds = 900.0,
    ) {
        if ($bucket === '' || preg_match('#[/\s]#', $bucket) === 1) {
            throw new InvalidArgumentException('The bucket name must not be empty or contain slashes or spaces.');
        }
        if ($region === '' || preg_match('#^[a-z0-9-]+$#', $region) !== 1) {
            throw new InvalidArgumentException('The region must be a name like "us-east-1".');
        }
        if ($accessKeyId === '' || $secretAccessKey === '' || $sessionToken === '') {
            throw new InvalidArgumentException('The access key, the secret key and a given session token must not be empty.');
        }
        if ($storageClass !== null && preg_match('#^[A-Z][A-Z0-9_]*$#', $storageClass) !== 1) {
            throw new InvalidArgumentException('The storage class must be an S3 storage class name like STANDARD_IA.');
        }
        if ($caBundle === '') {
            throw new InvalidArgumentException('The CA bundle path must not be empty; use null for the system CAs.');
        }
        if ($minPartSize < 1 || $maxPartSize < $minPartSize || $maxParts < 1) {
            throw new InvalidArgumentException('Part limits must be positive and the maximum not below the minimum.');
        }
        if ($connectTimeoutSeconds <= 0 || $requestTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Timeouts must be positive.');
        }

        $this->endpoint = $endpoint === null ? null : self::checkEndpoint($endpoint, $allowPlainHttp);
        $this->prefix = self::checkPrefix($prefix);
        $aws = $this->isAws();
        $this->checksums = $checksums ?? ($aws ? S3ChecksumMode::Crc32 : S3ChecksumMode::Off);
        $this->conditionalCreate = $conditionalCreate ?? $aws;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        $values['accessKeyId'] = '***';
        $values['secretAccessKey'] = '***';
        $values['sessionToken'] = $this->sessionToken === null ? null : '***';

        return $values;
    }

    /**
     * Whether the target is AWS S3 itself rather than another S3-compatible service.
     */
    public function isAws(): bool
    {
        if ($this->endpoint === null) {
            return true;
        }
        $host = strtolower((string) parse_url($this->endpoint, PHP_URL_HOST));

        return str_ends_with($host, '.amazonaws.com') || str_ends_with($host, '.amazonaws.com.cn');
    }

    /**
     * What identifies the target in an upload session: a session is only used again against the same endpoint,
     * bucket and prefix.
     *
     * @return array{endpoint: string, bucket: string, prefix: string}
     */
    public function targetIdentity(): array
    {
        return ['endpoint' => $this->endpoint ?? 'aws:' . $this->region, 'bucket' => $this->bucket, 'prefix' => $this->prefix];
    }

    private static function checkEndpoint(string $endpoint, bool $allowPlainHttp): string
    {
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('The endpoint must be an absolute URL like https://s3.example.com.');
        }
        if ($scheme !== 'https' && !($scheme === 'http' && $allowPlainHttp)) {
            throw new InvalidArgumentException('The endpoint must use https (http only for local test servers).');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The endpoint must not contain credentials, a query or a fragment.');
        }
        if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            throw new InvalidArgumentException('The endpoint must not contain a path; put the bucket and prefix in their own settings.');
        }

        return rtrim($endpoint, '/');
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
            throw new InvalidArgumentException('The prefix is not a valid key path: ' . $e->getMessage(), 0, $e);
        }
    }
}
