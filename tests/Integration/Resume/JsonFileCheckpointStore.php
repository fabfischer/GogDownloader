<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Transfer\CheckpointStore;
use App\Transfer\TransferCheckpoint;
use RuntimeException;

/**
 * Keeps the last checkpoint in a JSON file, replaced atomically (write a temporary file, then rename), so a process
 * killed at any moment leaves either the previous or the new checkpoint behind. Stands in for the app's database in
 * tests where a worker process really dies.
 */
final readonly class JsonFileCheckpointStore implements CheckpointStore
{
    public function __construct(public string $path)
    {
    }

    public function save(TransferCheckpoint $checkpoint): void
    {
        $temporary = $this->path . '.tmp';
        if (file_put_contents($temporary, json_encode($checkpoint->toArray(), JSON_THROW_ON_ERROR)) === false || !rename($temporary, $this->path)) {
            throw new RuntimeException('Could not write the checkpoint file.');
        }
    }

    public function load(): ?TransferCheckpoint
    {
        if (!is_file($this->path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('The checkpoint file holds no checkpoint.');
        }

        /** @var array<string, mixed> $data */
        return TransferCheckpoint::fromArray($data);
    }
}
