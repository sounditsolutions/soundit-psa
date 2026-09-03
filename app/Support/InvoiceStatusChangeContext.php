<?php

namespace App\Support;

use App\Enums\InvoiceStatusChangeSource;

/**
 * What a writer wants recorded about the invoice status move it is ABOUT to
 * make (#1173).
 *
 * InvoiceObserver is the seam that writes invoice_status_change_logs, and an
 * observer can see the row but not the intent behind it — it cannot know that
 * QuickBooks reported a 450.00 balance, only that the status went paid →
 * posted. A writer that has that knowledge attaches this to the model
 * immediately before the save; the observer consumes it and clears it, so it
 * can never leak onto a later, unrelated write of the same instance.
 *
 * Deliberately NOT required. Every other writer keeps working untouched and is
 * classified from execution context — see InvoiceStatusChangeLog::recordFor().
 */
class InvoiceStatusChangeContext
{
    public function __construct(
        public readonly InvoiceStatusChangeSource $source,
        public readonly ?string $reason = null,
        public readonly ?float $qboBalance = null,
    ) {}
}
