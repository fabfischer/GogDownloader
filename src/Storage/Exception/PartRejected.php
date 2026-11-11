<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * A part does not fit the session or the backend limits (wrong number, offset, length or size).
 * Retrying the same part unchanged will fail again.
 */
final class PartRejected extends StorageException
{
}
