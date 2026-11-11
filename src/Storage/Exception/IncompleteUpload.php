<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * complete() was called with a part list that does not cover the whole object exactly once.
 */
final class IncompleteUpload extends StorageException
{
}
