<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Persists checkpoints. The app stores them in its database; tests use InMemoryCheckpointStore. save() must be
 * durable when it returns: the engine only continues after it.
 */
interface CheckpointStore
{
    public function save(TransferCheckpoint $checkpoint): void;
}
