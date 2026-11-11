<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Transfer\Exception\TransferException;

/**
 * Where the bytes of one file come from.
 */
interface TransferSource
{
    /**
     * Checks the source and returns its fingerprint (real size and validators). Called once per transfer run.
     *
     * @throws TransferException
     */
    public function open(): SourceFingerprint;

    /**
     * Whether any byte range can be read again later. A sequential source can only move forward, so a failure in
     * the middle of the file means starting again from byte zero.
     */
    public function isResumable(): bool;

    /**
     * Yields exactly $length bytes starting at $offset, in chunks, and checks that the source still matches
     * $expected. Never yields more than requested; throws if fewer bytes arrive.
     *
     * @throws TransferException
     *
     * @return iterable<string>
     */
    public function readPart(int $offset, int $length, SourceFingerprint $expected): iterable;
}
