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
        /**
         * True when a resume found the upload already completed by an earlier run that did not get to report it. The
         * result then comes from the checkpoint and the target; nothing was sent, and the commit gate was not asked
         * again.
         */
        public bool $recovered = false,
    ) {
    }
}
