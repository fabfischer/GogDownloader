<?php

declare(strict_types=1);

namespace App\Storage\Exception;

use RuntimeException;

/**
 * Base class for every failure an archive storage adapter reports. Subclasses say how a caller should react
 * (retry, block the target, restart the upload).
 */
abstract class StorageException extends RuntimeException
{
}
