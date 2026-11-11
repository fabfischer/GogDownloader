<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The S3-compatible test server supports what the S3 adapter needs, with S3's rules: multipart with ListParts,
 * the 5 MiB minimum part size, abort, conditional create, and access errors for wrong credentials.
 */
#[CoversNothing]
final class S3TestServerTest extends IntegrationTestCase
{
    private const BUCKET = 'archive-test';

    private const MIB = 1048576;

    private S3Client $s3;

    protected function setUp(): void
    {
        $this->s3 = $this->client(self::env('ITEST_S3_SECRET_KEY'));
        if (!$this->s3->doesBucketExistV2(self::BUCKET)) {
            $this->s3->createBucket(['Bucket' => self::BUCKET]);
        }
    }

    public function testMultipartUploadWithPartListingAndCompletion(): void
    {
        $key = 'itest/' . self::runId() . '.bin';
        $id = $this->s3->createMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key])['UploadId'];
        $parts = [];
        foreach ([[1, 5 * self::MIB], [2, 1024]] as [$number, $length]) {
            $etag = $this->s3->uploadPart(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'PartNumber' => $number, 'Body' => $this->body($length)])['ETag'];
            $parts[] = ['PartNumber' => $number, 'ETag' => $etag];
        }

        $listed = $this->s3->listParts(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id])['Parts'];
        $this->s3->completeMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'MultipartUpload' => ['Parts' => $parts]]);

        self::assertSame([5 * self::MIB, 1024], array_map(static fn (array $part): int => (int) $part['Size'], $listed));
        self::assertSame(5 * self::MIB + 1024, (int) $this->s3->headObject(['Bucket' => self::BUCKET, 'Key' => $key])['ContentLength']);
    }

    public function testPartsBelowFiveMiBAreRejectedUnlessLast(): void
    {
        $key = 'itest/' . self::runId() . '.bin';
        $id = $this->s3->createMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key])['UploadId'];
        $parts = [];
        foreach ([1, 2] as $number) {
            $parts[] = ['PartNumber' => $number, 'ETag' => $this->s3->uploadPart(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'PartNumber' => $number, 'Body' => $this->body(1024)])['ETag']];
        }

        $this->assertS3Error('EntityTooSmall', fn () => $this->s3->completeMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'MultipartUpload' => ['Parts' => $parts]]));
        $this->s3->abortMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id]);
    }

    public function testAnAbortedUploadIsGone(): void
    {
        $key = 'itest/' . self::runId() . '.bin';
        $id = $this->s3->createMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key])['UploadId'];
        $this->s3->abortMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id]);

        $this->assertS3Error('NoSuchUpload', fn () => $this->s3->listParts(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id]));
    }

    public function testConditionalCreateRefusesAnExistingKey(): void
    {
        $key = 'itest/' . self::runId() . '.bin';
        $this->s3->putObject(['Bucket' => self::BUCKET, 'Key' => $key, 'Body' => 'original']);
        $id = $this->s3->createMultipartUpload(['Bucket' => self::BUCKET, 'Key' => $key])['UploadId'];
        $etag = $this->s3->uploadPart(['Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'PartNumber' => 1, 'Body' => $this->body(10)])['ETag'];

        $this->assertS3Error('PreconditionFailed', fn () => $this->s3->completeMultipartUpload([
            'Bucket' => self::BUCKET, 'Key' => $key, 'UploadId' => $id, 'IfNoneMatch' => '*',
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $etag]]],
        ]));
        self::assertSame(8, (int) $this->s3->headObject(['Bucket' => self::BUCKET, 'Key' => $key])['ContentLength']);
    }

    public function testWrongCredentialsAreDenied(): void
    {
        $denied = $this->client('wrong-secret');

        try {
            $denied->listObjectsV2(['Bucket' => self::BUCKET]);
            self::fail('Expected access to be denied');
        } catch (S3Exception $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    private function client(string $secret): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => self::env('ITEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => self::env('ITEST_S3_ACCESS_KEY'), 'secret' => $secret],
            'request_checksum_calculation' => 'when_required',
        ]);
    }

    /**
     * @return resource
     */
    private function body(int $length): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, str_repeat('p', $length));
        rewind($stream);

        return $stream;
    }

    private function assertS3Error(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected S3 error ' . $code);
        } catch (S3Exception $e) {
            self::assertSame($code, $e->getAwsErrorCode());
        }
    }
}
