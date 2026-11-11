<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\S3\S3ChecksumMode;
use App\Storage\S3\S3StorageAdapter;
use App\Tests\Fork\Storage\ArchiveStorageAdapterContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The storage contract against the S3-compatible test server with the AWS defaults: CRC32 part checksums declared,
 * sent and repeated at Complete, and conditional create (If-None-Match: *) on Complete.
 */
#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterChecksumContractTest extends ArchiveStorageAdapterContractTestCase
{
    public static function setUpBeforeClass(): void
    {
        S3TestServer::ensureBucket();
    }

    protected function createAdapter(): ArchiveStorageAdapter
    {
        return S3StorageAdapter::create(S3TestServer::settings('itest-contract-crc32', S3ChecksumMode::Crc32, conditionalCreate: true));
    }
}
