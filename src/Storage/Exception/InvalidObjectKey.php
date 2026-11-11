<?php

declare(strict_types=1);

namespace App\Storage\Exception;

use InvalidArgumentException;

/**
 * A key or key segment that is not safe to send to a storage backend (traversal, control characters, too long, ...).
 */
final class InvalidObjectKey extends InvalidArgumentException
{
}
