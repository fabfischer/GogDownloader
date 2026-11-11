<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source answered a range request with the whole file (200) or otherwise ignored the range. Use a sequential source instead; never read the full body.
 */
final class RangeNotSupported extends TransferException
{
}
