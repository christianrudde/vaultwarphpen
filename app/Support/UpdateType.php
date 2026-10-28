<?php

namespace App\Support;

/**
 * The kinds of api::UpdateType that cipher updates distinguish. Live
 * notifications are not ported; the type still decides the revision check
 * and the event that is logged.
 */
enum UpdateType
{
    case SyncCipherCreate;
    case SyncCipherUpdate;
    // Imports and bulk changes: no revision check, no event.
    case None;
}
