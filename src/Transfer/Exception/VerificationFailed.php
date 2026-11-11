<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * After completing, the stored object does not match what was sent (missing or wrong size).
 */
final class VerificationFailed extends TransferException
{
}
