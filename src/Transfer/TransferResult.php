<?php

declare(strict_types=1);

namespace App\Transfer;

use App\Storage\StoredObject;

final readonly class TransferResult
{
    public function __construct(
        public TransferStatus $status,
        public SourceFingerprint $fingerprint,
        /** Hex SHA-256 and MD5 of the whole content; null when stopped early. */
        public ?string $sha256 = null,
        public ?string $md5 = null,
        public ?StoredObject $stored = null,
        public ?StopRequest $stopRequest = null,
        /** Last checkpoint; for a paused transfer, the state to resume from. */
        public ?TransferCheckpoint $checkpoint = null,
    ) {
    }
}
