<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Keeps every saved checkpoint in memory. For tests and for callers that don't need resume.
 */
final class InMemoryCheckpointStore implements CheckpointStore
{
    /** @var list<TransferCheckpoint> */
    public array $saved = [];

    public function save(TransferCheckpoint $checkpoint): void
    {
        $this->saved[] = $checkpoint;
    }

    public function last(): ?TransferCheckpoint
    {
        return $this->saved === [] ? null : $this->saved[count($this->saved) - 1];
    }
}
