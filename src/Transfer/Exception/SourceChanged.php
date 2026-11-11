<?php

declare(strict_types=1);

namespace App\Transfer\Exception;

/**
 * The source is no longer the file the transfer started with (size, ETag or Last-Modified differ). Abort the upload and start again from byte zero with fresh metadata.
 */
final class SourceChanged extends TransferException
{
}
