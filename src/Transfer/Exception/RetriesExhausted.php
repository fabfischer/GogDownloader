<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * A part kept failing with temporary errors until the retry budget ran out. The last checkpoint stays valid for a later resume.
 */
final class RetriesExhausted extends TransferException
{
}
