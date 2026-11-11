<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * No object exists at the requested key (openRead() of a missing object, for example).
 */
final class ObjectNotFound extends StorageException
{
}
