<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source does not state the total size. The transfer cannot plan parts; mark the file as unsupported instead of guessing.
 */
final class SourceSizeUnknown extends TransferException
{
}
