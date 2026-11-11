<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source refuses the file even with a fresh URL (404, 403, 410, other 4xx). Retrying will not help.
 */
final class SourceUnavailable extends TransferException
{
}
