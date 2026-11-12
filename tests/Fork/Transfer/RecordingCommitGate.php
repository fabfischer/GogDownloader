<?php

declare(strict_types=1);

namespace App\Tests\Fork\Transfer;

use App\Transfer\CommitGate;

/**
 * A commit gate with a fixed answer that records every question, for engine tests.
 */
final class RecordingCommitGate implements CommitGate
{
    /** @var list<array{string, string, int}> sha256, md5 and size of every question */
    public array $asked = [];

    public function __construct(private readonly bool $allow)
    {
    }

    public function allowCommit(string $sha256, string $md5, int $size): bool
    {
        $this->asked[] = [$sha256, $md5, $size];

        return $this->allow;
    }
}
