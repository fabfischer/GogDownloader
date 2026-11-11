<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * No part size fits the file into the target's part limits and the memory budget.
 */
final class TransferPlanImpossible extends TransferException
{
}
