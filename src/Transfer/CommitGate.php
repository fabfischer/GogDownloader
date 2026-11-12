<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Asked once all parts are uploaded and the hashes are known, before the object becomes visible. Returning false
 * aborts the upload session instead of completing it, for example when an object with the same SHA-256 and size
 * already exists on the target, so identical bytes are stored only once.
 *
 * The gate is a shortcut, not a lock: two transfers of the same content can both pass it before either is recorded, so
 * the caller's own record of the object still has to handle a duplicate. It is not asked again when a resume finds that
 * an earlier run already completed the upload (TransferResult::$recovered); that run asked it before completing.
 */
interface CommitGate
{
    public function allowCommit(string $sha256, string $md5, int $size): bool;
}
