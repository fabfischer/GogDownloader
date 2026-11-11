<?php

declare(strict_types=1);

namespace App\Transfer;

enum TransferStatus: string
{
    /** The object is stored at its key and its size was checked. */
    case Completed = 'completed';

    /** All parts were uploaded, but the commit gate said no; the upload session was aborted. */
    case Deduplicated = 'deduplicated';

    /** Stopped at a part boundary on request; see TransferResult::$stopRequest and $checkpoint. */
    case Stopped = 'stopped';
}
