<?php

declare(strict_types=1);

namespace App\Transfer;

enum StopRequest: string
{
    /** Stop after the current part and keep the upload session for a later resume. */
    case Pause = 'pause';

    /** Stop after the current part and abort the upload session. */
    case Cancel = 'cancel';
}
