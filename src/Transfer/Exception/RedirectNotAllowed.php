<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source URL or a redirect points to a host or scheme outside the allowlist.
 */
final class RedirectNotAllowed extends TransferException
{
}
