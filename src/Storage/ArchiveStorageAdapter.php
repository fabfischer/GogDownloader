<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exception\AccessDenied;
use App\Storage\Exception\IncompleteUpload;
use App\Storage\Exception\ObjectAlreadyExists;
use App\Storage\Exception\ObjectNotFound;
use App\Storage\Exception\PartRejected;
use App\Storage\Exception\QuotaExceeded;
use App\Storage\Exception\StorageException;
use App\Storage\Exception\TransientStorageFailure;
use App\Storage\Exception\UploadSessionNotFound;

/**
 * One storage target for archived files (S3-compatible bucket, Nextcloud folder, ...).
 *
 * Rules every implementation follows:
 * - Bytes become visible at the final key only in complete(); before that they live in the backend's upload session.
 * - Objects are immutable: complete() never overwrites an existing object (ObjectAlreadyExists).
 * - Calls are safe to retry: writing the same part again replaces it; aborting an unknown session is not an error.
 * - Keys are ObjectKey values relative to the target's configured prefix; the adapter adds the prefix.
 * - Failures are StorageException subclasses that tell the caller whether to retry, restart or stop.
 * - No secrets (credentials, signed URLs) in exception messages or session values.
 */
interface ArchiveStorageAdapter
{
    public function capabilities(): StorageCapabilities;

    /**
     * Opens an upload session for one object.
     *
     * @throws ObjectAlreadyExists                                if the key already holds an object
     * @throws AccessDenied|QuotaExceeded|TransientStorageFailure
     */
    public function begin(UploadRequest $request): UploadSession;

    /**
     * Parts the backend holds for the session, ordered by number. Used to reconcile after a restart. Backends that
     * cannot list parts (see capabilities) throw a StorageException; the caller then trusts its own checkpoint.
     *
     * @throws UploadSessionNotFound|TransientStorageFailure|StorageException
     *
     * @return list<UploadedPart>
     */
    public function listCompletedParts(UploadSession $session): array;

    /**
     * Sends one part. Part numbers start at 1; offset and length must match the session's part layout.
     *
     * @throws PartRejected|UploadSessionNotFound|QuotaExceeded|TransientStorageFailure
     */
    public function writePart(UploadSession $session, int $number, PartBody $body): UploadedPart;

    /**
     * Makes the object visible at its key from exactly the given parts.
     *
     * Safe to call again when the answer to an earlier call got lost: if the session no longer exists and the key holds
     * an object of the session's total size, that earlier call succeeded, and the stored object is returned. Keys are
     * fresh revision keys, so nothing else writes there. Without such an object, a gone session is
     * UploadSessionNotFound.
     *
     * @param list<UploadedPart> $parts every part from 1 to the session's part count, once
     *
     * @throws IncompleteUpload|ObjectAlreadyExists|UploadSessionNotFound|TransientStorageFailure
     */
    public function complete(UploadSession $session, array $parts): StoredObject;

    /**
     * Drops the session and its parts. Aborting a session the backend no longer knows is not an error.
     *
     * @throws TransientStorageFailure
     */
    public function abort(UploadSession $session): void;

    /**
     * @throws AccessDenied|TransientStorageFailure
     */
    public function stat(ObjectKey $key): ?RemoteObjectInfo;

    /**
     * Reads an object in chunks of at most $chunkSize bytes, for audits and verification. Never buffers the whole
     * object.
     *
     * @throws ObjectNotFound|StorageException if the object does not exist or cannot be read
     *
     * @return iterable<string>
     */
    public function openRead(ObjectKey $key, int $chunkSize = 1048576): iterable;

    /**
     * Deletes one object. Only for objects the archive owns, and only on an explicit, owner-approved action.
     *
     * @throws AccessDenied|TransientStorageFailure
     */
    public function delete(ObjectKey $key): void;
}
