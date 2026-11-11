<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\ObjectKey;

/**
 * One file to move from a source to a storage target, optionally continuing from a checkpoint.
 */
final readonly class TransferRequest
{
    public function __construct(
        public TransferSource $source,
        public ArchiveStorageAdapter $target,
        public ObjectKey $key,
        public CheckpointStore $checkpoints,
        public ?TransferCheckpoint $resumeFrom = null,
        public ?TransferControl $control = null,
        public ?CommitGate $commitGate = null,
        public ?string $contentType = null,
    ) {
    }
}
