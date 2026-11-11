<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * The credentials are wrong or lack a permission. Retrying does not help until the configuration changes.
 */
final class AccessDenied extends StorageException
{
}
