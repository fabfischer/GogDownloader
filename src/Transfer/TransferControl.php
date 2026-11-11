<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * Asked before every part whether the transfer should stop (the app reads pause and cancel requests from its jobs
 * table).
 */
interface TransferControl
{
    public function stopRequested(): ?StopRequest;
}
