<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\ArchiveStorageAdapter;
use App\Storage\Exception\StorageException;
use App\Storage\Exception\UploadSessionNotFound;
use App\Storage\UploadedPart;
use App\Transfer\Exception\ResumeImpossible;

/**
 * Compares a checkpoint with what the target holds, after a restart and before anything is sent: S3 ListParts,
 * Nextcloud PROPFIND of the upload folder, and a stat of the key when the session is gone.
 *
 * Reconciling only reads. It never sends, aborts, completes or deletes anything, and it never touches the source, so a
 * caller can also use it to report the state of an interrupted transfer. The transfer engine acts on the result when
 * it resumes.
 */
final readonly class Reconciler
{
    /**
     * @throws ResumeImpossible if the checkpoint belongs to another kind of storage backend
     * @throws StorageException if the target cannot be asked (temporary failures included)
     */
    public function reconcile(TransferCheckpoint $checkpoint, ArchiveStorageAdapter $target): Reconciliation
    {
        $session = $checkpoint->session;
        if ($session->backend !== $target->capabilities()->backend) {
            throw new ResumeImpossible('The checkpoint belongs to another storage backend.');
        }
        if (!$target->capabilities()->canListParts) {
            return new Reconciliation(ReconcileOutcome::Resumable, $checkpoint, listed: false);
        }

        try {
            $listed = $target->listCompletedParts($session);
        } catch (UploadSessionNotFound) {
            return $this->sessionGone($checkpoint, $target);
        }

        /** @var array<int, UploadedPart> $remote */
        $remote = [];
        foreach ($listed as $part) {
            $remote[$part->number] = $part;
        }
        foreach ($checkpoint->parts as $part) {
            $held = $remote[$part->number] ?? null;
            if ($held === null || $held->remoteId !== $part->remoteId || $held->length !== $part->length) {
                return new Reconciliation(ReconcileOutcome::PartMissing, $checkpoint, missingPart: $part->number);
            }
            unset($remote[$part->number]);
        }

        $extra = array_keys($remote);
        sort($extra);

        return new Reconciliation(ReconcileOutcome::Resumable, $checkpoint, $extra);
    }

    private function sessionGone(TransferCheckpoint $checkpoint, ArchiveStorageAdapter $target): Reconciliation
    {
        // Completing removes the session. Only a checkpoint with every part can have been completed, and only an
        // object of the full size at the (fresh, revision-addressed) key shows that it was.
        if ($checkpoint->hasAllParts()) {
            $object = $target->stat($checkpoint->session->key);
            if ($object !== null && $object->size === $checkpoint->session->totalSize) {
                return new Reconciliation(ReconcileOutcome::AlreadyCompleted, $checkpoint, object: $object);
            }
        }

        return new Reconciliation(ReconcileOutcome::SessionGone, $checkpoint);
    }
}
