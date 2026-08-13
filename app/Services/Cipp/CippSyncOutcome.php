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
     * The tenant list was read but this pass matched nobody — the group filter excluded
     * everyone, or no user survived processing. A transient failure on the per-user group
     * endpoint produces that exactly as readily as an empty group, so the roster counts as
     * unverified and stale cleanup is SKIPPED rather than deactivating every synced person.
     */
    case RosterUnverified = 'roster_unverified';
}
