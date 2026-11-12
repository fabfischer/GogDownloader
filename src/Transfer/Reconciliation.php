<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\RemoteObjectInfo;

/**
 * The result of comparing a checkpoint with what the target holds (see Reconciler).
 */
final readonly class Reconciliation
{
    /**
     * @param list<int> $extraParts
     */
    public function __construct(
        public ReconcileOutcome $outcome,
        public TransferCheckpoint $checkpoint,
        /**
         * Part numbers the target holds beyond the checkpoint, for example a part whose acknowledgement was lost when
         * the worker died. They are sent again, because their bytes are not in the checkpoint's hash state.
         */
        public array $extraParts = [],
        /** False when the target cannot list parts; the checkpoint is then trusted as it is. */
        public bool $listed = true,
        /** AlreadyCompleted: the object the target holds at the key. */
        public ?RemoteObjectInfo $object = null,
        /** PartMissing: the first checkpointed part the target does not hold as checkpointed. */
        public ?int $missingPart = null,
    ) {
    }

    /**
     * The part to send next when the transfer continues; one past the last part when only completing is left.
     */
    public function nextPartNumber(): int
    {
        return $this->checkpoint->nextPartNumber();
    }
}
