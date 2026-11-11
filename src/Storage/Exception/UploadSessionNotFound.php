<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * The backend no longer knows the upload session (aborted, expired after inactivity, or never existed).
 * Callers start a new session from byte zero.
 */
final class UploadSessionNotFound extends StorageException
{
}
