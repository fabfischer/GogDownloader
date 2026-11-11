<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3Settings;
use Aws\S3\S3Client;
use RuntimeException;

/**
 * Settings and a plain SDK client for the S3-compatible test server of the "test" compose profile. Endpoint and the
 * fixed test credentials come from the ITEST_S3_* variables in docker/dev/compose.yaml.
 */
final class S3TestServer
{
    public const BUCKET = 'archive-test';

    public static function settings(
        string $prefix,
        S3ChecksumMode $checksums = S3ChecksumMode::Off,
        bool $conditionalCreate = false,
        ?string $secret = null,
        string $bucket = self::BUCKET,
    ): S3Settings {
        return new S3Settings(
            bucket: $bucket,
            region: 'us-east-1',
            accessKeyId: self::env('ITEST_S3_ACCESS_KEY'),
            secretAccessKey: $secret ?? self::env('ITEST_S3_SECRET_KEY'),
            endpoint: self::env('ITEST_S3_ENDPOINT'),
            prefix: $prefix,
            pathStyle: true,
            allowPlainHttp: true,
            checksums: $checksums,
            conditionalCreate: $conditionalCreate,
            requestTimeoutSeconds: 60.0,
        );
    }

    /**
     * A plain SDK client, for looking at the bucket from outside the adapter.
     */
    public static function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => self::env('ITEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => self::env('ITEST_S3_ACCESS_KEY'), 'secret' => self::env('ITEST_S3_SECRET_KEY')],
            'request_checksum_calculation' => 'when_required',
            'use_aws_shared_config_files' => false,
        ]);
    }

    public static function ensureBucket(): void
    {
        $client = self::client();
        if (!$client->doesBucketExistV2(self::BUCKET)) {
            $client->createBucket(['Bucket' => self::BUCKET]);
        }
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
