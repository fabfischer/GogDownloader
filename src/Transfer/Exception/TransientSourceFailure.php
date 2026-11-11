<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

use Throwable;

/**
 * A temporary source failure: timeout, connection reset, short read, 5xx or 429. Reading the same range again
 * later may work.
 */
final class TransientSourceFailure extends TransferException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
