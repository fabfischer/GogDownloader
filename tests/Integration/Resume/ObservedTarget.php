<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\ObjectKey;
use App\Storage\PartBody;
use App\Storage\RemoteObjectInfo;
use App\Storage\StorageCapabilities;
use App\Storage\StoredObject;
use App\Storage\UploadedPart;
use App\Storage\UploadRequest;
use App\Storage\UploadSession;
use Closure;

/**
 * Passes every call to a real adapter, records which parts were written and how often the upload was completed, and
 * can run a callback right after the target acknowledged a part or completed the upload: the moments a worker can die
 * after the target did its work but before the engine saved it.
 */
final class ObservedTarget implements ArchiveStorageAdapter
{
    /** @var list<int> part numbers the target acknowledged, in order */
    public array $writtenParts = [];

    public int $begins = 0;

    public int $completes = 0;

    /**
     * @param (Closure(int): void)|null $afterWrite    gets the part number
     * @param (Closure(): void)|null    $afterComplete
     */
    public function __construct(
        private readonly ArchiveStorageAdapter $inner,
        private readonly ?Closure $afterWrite = null,
        private readonly ?Closure $afterComplete = null,
    ) {
    }

    public function capabilities(): StorageCapabilities
    {
        return $this->inner->capabilities();
    }

    public function begin(UploadRequest $request): UploadSession
    {
        ++$this->begins;

        return $this->inner->begin($request);
    }

    public function listCompletedParts(UploadSession $session): array
    {
        return $this->inner->listCompletedParts($session);
    }

    public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart
    {
        $part = $this->inner->writePart($session, $number, $body);
        $this->writtenParts[] = $number;
        if ($this->afterWrite !== null) {
            ($this->afterWrite)($number);
        }

        return $part;
    }

    public function complete(UploadSession $session, array $parts): StoredObject
    {
        $stored = $this->inner->complete($session, $parts);
        ++$this->completes;
        if ($this->afterComplete !== null) {
            ($this->afterComplete)();
        }

        return $stored;
    }

    public function abort(UploadSession $session): void
    {
        $this->inner->abort($session);
    }

    public function stat(ObjectKey $key): ?RemoteObjectInfo
    {
        return $this->inner->stat($key);
    }

    public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable
    {
        return $this->inner->openRead($key, $chunkSize);
    }

    public function delete(ObjectKey $key): void
    {
        $this->inner->delete($key);
    }
}
