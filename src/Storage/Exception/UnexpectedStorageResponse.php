<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * The backend answered in a way the adapter cannot classify: an unknown error code, or a success response without a
 * value the adapter needs. Not retried automatically; the message names the operation and the backend's error code so
 * the owner can check the target.
 */
final class UnexpectedStorageResponse extends StorageException
{
}
