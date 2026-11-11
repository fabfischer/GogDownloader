<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Asked once all parts are uploaded and the hashes are known, before the object becomes visible. Returning false
 * aborts the upload session instead of completing it, for example when an object with the same SHA-256 and size
 * already exists on the target, so identical bytes are stored only once.
 */
interface CommitGate
{
    public function allowCommit(string $sha256, string $md5, int $size): bool;
}
