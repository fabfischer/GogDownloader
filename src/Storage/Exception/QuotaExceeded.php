<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * The backend has no space left for this upload (for example Nextcloud 507). Retrying does not help until the
 * owner frees space or changes the target.
 */
final class QuotaExceeded extends StorageException
{
}
