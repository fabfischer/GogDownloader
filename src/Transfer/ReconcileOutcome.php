<?php

declare(strict_types=1);

namespace App\Transfer;

/**
 * What the target says about a checkpointed upload, and what the caller does next.
 */
enum ReconcileOutcome: string
{
    /** The session is open and holds every checkpointed part as checkpointed: continue with the next part. */
    case Resumable = 'resumable';

    /**
     * The session is gone, every part was checkpointed, and the key holds an object of the full size: an earlier run
     * completed the upload but did not get to report it. Record the object; nothing has to be sent again.
     */
    case AlreadyCompleted = 'already_completed';

    /**
     * The session no longer exists at the target (expired, aborted, or lost by the server) and the key holds no
     * finished object: start again from byte zero with a fresh key.
     */
    case SessionGone = 'session_gone';

    /** The session is open, but a checkpointed part is missing or differs: abort it and start again from byte zero. */
    case PartMissing = 'part_missing';
}
