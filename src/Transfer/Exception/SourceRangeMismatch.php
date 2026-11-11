<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source sent a different range or length than requested. The bytes cannot be trusted for this part.
 */
final class SourceRangeMismatch extends TransferException
{
}
