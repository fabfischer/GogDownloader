<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * A sequential source failed in the middle of the file. It cannot re-read earlier bytes, so the transfer has to start again from byte zero.
 */
final class NotResumableFromSource extends TransferException
{
}
