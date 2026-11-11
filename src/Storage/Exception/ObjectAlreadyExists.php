<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * The target key already holds an object. Archive objects are immutable, so adapters never overwrite one.
 */
final class ObjectAlreadyExists extends StorageException
{
}
