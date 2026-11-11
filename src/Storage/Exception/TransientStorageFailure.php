<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * A temporary failure (timeout, connection reset, 5xx, rate limit). The same call may be retried with backoff.
 */
final class TransientStorageFailure extends StorageException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
