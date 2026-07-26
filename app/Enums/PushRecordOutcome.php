<?php

namespace App\Enums;

/**
 * Outcome of Invoice::recordPushResult() — the locked billing-backend push
 * result boundary (psa-bl36l R5/R6).
 *
 * - Recorded: the locked row was live — the full result (money, URL,
 *   provenance) was recorded, and a CREATE transitioned it to Synced.
 * - RowVoided: the locked row was Void — money/URL/error-clear were dropped
 *   (id + timestamp still recorded so the upstream invoice is flagged, not
 *   orphaned) and the caller must not perform any further client-visible
 *   side effect: the Stripe caller compensates by voiding the invoice it
 *   just created upstream, and never emails.
 * - DuplicateId: the locked row already carries a DIFFERENT
 *   stripe_invoice_id — a concurrent push won the record race, so NOTHING
 *   was written: the row's link and any per-cause divergence error belong to
 *   the winner's chain. The caller must void its own just-created upstream
 *   invoice (see StripeSyncService::compensateDuplicatePush()) and never
 *   email.
 */
enum PushRecordOutcome
{
    case Recorded;
    case RowVoided;
    case DuplicateId;
}
