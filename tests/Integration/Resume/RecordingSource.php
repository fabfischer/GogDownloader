<?php

declare(strict_types=1);

namespace App\Tests\Integration\Resume;

use App\Transfer\SourceFingerprint;
use App\Transfer\TransferSource;

/**
 * Passes every call to a real source and records how often it was opened and which byte ranges were read.
 */
final class RecordingSource implements TransferSource
{
    public int $opens = 0;

    /** @var list<int> offsets of every readPart() call, in order */
    public array $readOffsets = [];

    public function __construct(private readonly TransferSource $inner)
    {
    }

    public function open(): SourceFingerprint
    {
        ++$this->opens;

        return $this->inner->open();
    }

    public function isResumable(): bool
    {
        return $this->inner->isResumable();
    }

    public function readPart(int $offset, int $length, SourceFingerprint $expected): iterable
    {
        $this->readOffsets[] = $offset;

        return $this->inner->readPart($offset, $length, $expected);
    }
}
