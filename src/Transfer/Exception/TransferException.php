<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

use RuntimeException;

/**
 * Base class for transfer failures. Subclasses say whether a retry, a restart from byte zero or a stop is right.
 */
abstract class TransferException extends RuntimeException
{
}
