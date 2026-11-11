<?php

declare(strict_types=1);

namespace App\Tests\Fork\Storage\S3;

use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(S3Settings::class)]
final class S3SettingsTest extends TestCase
{
    public function testAwsGetsChecksumsAndConditionalCreateByDefault(): void
    {
        foreach ([null, 'https://s3.eu-central-1.amazonaws.com'] as $endpoint) {
            $settings = $this->settings(endpoint: $endpoint);

            self::assertTrue($settings->isAws());
            self::assertSame(S3ChecksumMode::Crc32, $settings->checksums);
            self::assertTrue($settings->conditionalCreate);
        }
    }

    public function testOtherEndpointsGetNeitherUnlessEnabled(): void
    {
        $plain = $this->settings(endpoint: 'https://s3.example.test/');
        $enabled = new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: 'https://s3.example.test', checksums: S3ChecksumMode::Crc32, conditionalCreate: true);

        self::assertFalse($plain->isAws());
        self::assertSame('https://s3.example.test', $plain->endpoint);
        self::assertSame(S3ChecksumMode::Off, $plain->checksums);
        self::assertFalse($plain->conditionalCreate);
        self::assertSame(S3ChecksumMode::Crc32, $enabled->checksums);
        self::assertTrue($enabled->conditionalCreate);
    }

    public function testThePrefixIsNormalizedAndChecked(): void
    {
        self::assertSame('GOGArchive/main', $this->settings(prefix: '/GOGArchive/main/')->prefix);
        self::assertSame('', $this->settings(prefix: '/')->prefix);

        $this->expectException(InvalidArgumentException::class);
        $this->settings(prefix: 'archive/../other');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function badEndpoints(): iterable
    {
        yield 'plain http without opt-in' => ['http://s3.example.test', false];
        yield 'no scheme' => ['s3.example.test', true];
        yield 'credentials in the URL' => ['https://user:pass@s3.example.test', false];
        yield 'query' => ['https://s3.example.test?x=1', false];
        yield 'path' => ['https://s3.example.test/bucket', false];
        yield 'other scheme' => ['ftp://s3.example.test', true];
    }

    #[DataProvider('badEndpoints')]
    public function testBadEndpointsAreRejected(string $endpoint, bool $allowPlainHttp): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: $endpoint, allowPlainHttp: $allowPlainHttp);
    }

    public function testPlainHttpNeedsAnOptIn(): void
    {
        $settings = new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: 'http://s3:7070', allowPlainHttp: true);

        self::assertSame('http://s3:7070', $settings->endpoint);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badValues(): iterable
    {
        yield 'empty bucket' => [['bucket' => '']];
        yield 'bucket with slash' => [['bucket' => 'a/b']];
        yield 'bad region' => [['region' => 'EU Central']];
        yield 'empty secret' => [['secretAccessKey' => '']];
        yield 'empty session token' => [['sessionToken' => '']];
        yield 'bad storage class' => [['storageClass' => 'glacier please']];
        yield 'empty CA path' => [['caBundle' => '']];
        yield 'minimum above maximum' => [['minPartSize' => 10, 'maxPartSize' => 5]];
        yield 'no parts' => [['maxParts' => 0]];
        yield 'zero timeout' => [['requestTimeoutSeconds' => 0.0]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('badValues')]
    public function testBadValuesAreRejected(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Settings(...$values + ['bucket' => 'bucket', 'region' => 'us-east-1', 'accessKeyId' => 'access', 'secretAccessKey' => 'secret']);
    }

    public function testDebugOutputHidesTheCredentials(): void
    {
        $settings = new S3Settings('bucket', 'us-east-1', 'AKIDEXAMPLEONLY', 'secret-value-example', 'session-token-example');

        $output = print_r($settings, true);
        ob_start();
        var_dump($settings);
        $output .= (string) ob_get_clean();

        self::assertStringNotContainsString('AKIDEXAMPLEONLY', $output);
        self::assertStringNotContainsString('secret-value-example', $output);
        self::assertStringNotContainsString('session-token-example', $output);
        self::assertStringContainsString('bucket', $output);
    }

    public function testTheTargetIdentityNamesEndpointBucketAndPrefix(): void
    {
        self::assertSame(['endpoint' => 'aws:eu-west-1', 'bucket' => 'bucket', 'prefix' => 'p'], (new S3Settings('bucket', 'eu-west-1', 'a', 's', prefix: 'p'))->targetIdentity());
        self::assertSame(['endpoint' => 'https://s3.example.test', 'bucket' => 'bucket', 'prefix' => ''], $this->settings(endpoint: 'https://s3.example.test')->targetIdentity());
    }

    private function settings(?string $endpoint = null, string $prefix = ''): S3Settings
    {
        return new S3Settings('bucket', 'us-east-1', 'access', 'secret', endpoint: $endpoint, prefix: $prefix);
    }
}
