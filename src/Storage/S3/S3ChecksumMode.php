<?php

declare(strict_types=1);

namespace App\Storage\S3;

/**
 * Whether multipart uploads carry per-part CRC32 checksums.
 *
 * S3-compatible servers differ here, and mixing the two styles makes CompleteMultipartUpload fail with InvalidPart,
 * so an upload uses one mode from start to end: the mode is fixed when the upload is created and stored with the
 * session.
 */
enum S3ChecksumMode: string
{
    /** No checksum headers; the request signature still covers each part's SHA-256. */
    case Off = 'off';

    /** CreateMultipartUpload declares CRC32, every part sends its CRC32, and CompleteMultipartUpload repeats them. */
    case Crc32 = 'crc32';
}
