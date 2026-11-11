<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * A saved checkpoint cannot be continued: the upload session is gone or the target lost a part the checkpoint relies on. Start again from byte zero.
 */
final class ResumeImpossible extends TransferException
{
}
