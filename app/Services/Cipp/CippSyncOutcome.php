<?php

namespace App\Services\Cipp;

/**
 * What one per-client CIPP person sync actually did.
 *
 * The scheduled pass may ignore this; an on-demand caller must not. "The sync ran and
 * found nothing to change" and "the sync read nothing at all" produce the same empty
 * SyncResult ('no changes') and are opposite answers to the only question the on-demand
 * tool exists to answer — is this person really absent from the tenant?
 */
enum CippSyncOutcome: string
{
    /** The tenant user list was read and its users were processed. The only completed sync. */
    case Synced = 'synced';

    /** Another sync for this client held the lock; nothing was read and nothing was written. */
    case SkippedLocked = 'skipped_locked';

    /** CIPP returned no user list for this tenant — nothing read, nothing written, roster unknown. */
    case NoUsersRead = 'no_users_read';

    /**
     * The tenant list was read but this pass did not account for every user in it — the
     * group filter excluded everyone, SOME group lookups or per-user syncs failed, or no
     * user survived processing. A transient upstream failure produces each of those exactly
     * as readily as a real change, and a user it hid is missing from the seen-list in the
     * same way a departed user is, so the roster counts as unverified and stale cleanup is
     * SKIPPED rather than deactivating every person this pass could not confirm. Partial by
     * definition: whatever the loop created/updated still stands and is reported. The
     * outcome does NOT say which cause fired — a caller must not assert one.
     */
    case RosterUnverified = 'roster_unverified';
}
