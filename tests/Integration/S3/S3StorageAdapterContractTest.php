<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\S3\S3StorageAdapter;
use App\Tests\Fork\Storage\ArchiveStorageAdapterContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The storage contract against the S3-compatible test server, with the defaults for a non-AWS endpoint: no part
 * checksums, no conditional create (the adapter looks at the key before completing).
 */
#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterContractTest extends ArchiveStorageAdapterContractTestCase
{
    public static function setUpBeforeClass(): void
    {
        S3TestServer::ensureBucket();
    }

    protected function createAdapter(): ArchiveStorageAdapter
    {
        return S3StorageAdapter::create(S3TestServer::settings('itest-contract-plain'));
    }
}
