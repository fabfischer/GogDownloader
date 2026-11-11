<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Tests\Fork\Storage\ArchiveStorageAdapterContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The storage contract against the test Nextcloud with object storage and a distributed cache, where Nextcloud writes
 * chunks as parts of a multipart upload and completes it on the final MOVE.
 */
#[CoversClass(NextcloudStorageAdapter::class)]
final class NextcloudObjectStoreContractTest extends ArchiveStorageAdapterContractTestCase
{
    protected function createAdapter(): ArchiveStorageAdapter
    {
        return NextcloudStorageAdapter::create(NextcloudTestServer::settings(NextcloudTestServer::OBJECTSTORE, 'itest-contract'));
    }
}
