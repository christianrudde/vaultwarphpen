<?php

namespace App\Support;

/**
 * api::UpdateType: the kinds of live notification, numbered like Bitwarden's
 * PushType. Cipher updates also use the type to decide the revision check
 * and the event that is logged.
 */
enum UpdateType: int
{
    case SyncCipherUpdate = 0;
    case SyncCipherCreate = 1;
    case SyncLoginDelete = 2;
    case SyncFolderDelete = 3;
    case SyncCiphers = 4;

    case SyncVault = 5;
    case SyncOrgKeys = 6;
    case SyncFolderCreate = 7;
    case SyncFolderUpdate = 8;
    // SyncCipherDelete = 9 redirects to SyncLoginDelete upstream.
    case SyncSettings = 10;

    case LogOut = 11;

    case SyncSendCreate = 12;
    case SyncSendUpdate = 13;
    case SyncSendDelete = 14;

    case AuthRequest = 15;
    case AuthRequestResponse = 16;

    // Imports and bulk changes: no revision check, no event.
    case None = 100;
}
