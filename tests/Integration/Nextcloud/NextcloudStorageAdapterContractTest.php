<?php

declare(strict_types=1);

namespace App\Tests\Integration\Nextcloud;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Nextcloud\NextcloudStorageAdapter;
use App\Tests\Fork\Storage\ArchiveStorageAdapterContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The storage contract against the test Nextcloud with local storage, where chunks are assembled on the final MOVE.
 */
#[CoversClass(NextcloudStorageAdapter::class)]
final class NextcloudStorageAdapterContractTest extends ArchiveStorageAdapterContractTestCase
{
    protected function createAdapter(): ArchiveStorageAdapter
    {
        return NextcloudStorageAdapter::create(NextcloudTestServer::settings(NextcloudTestServer::PLAIN, 'itest-contract'));
    }
}
