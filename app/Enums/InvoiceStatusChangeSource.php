<?php

namespace App\Enums;

/**
 * Who moved an invoice's status, recorded on every invoice_status_change_logs
 * row (#1173).
 *
 * Deliberately COARSE. The seam that writes the log is InvoiceObserver, which
 * sees every writer whether or not it opted in, so most writers arrive with no
 * declared context and are classified from execution context alone: an
 * authenticated request is Staff, anything else is System. A bucket that is
 * broad but always true beats a precise one that guesses — the whole point of
 * the log is that nothing on the invoice row recorded what set the status.
 *
 * QboPull is the one writer that declares itself, because #1173 exists to
 * answer "did QuickBooks say this, or did an import?" and that question needs
 * the QBO answer distinguishable from every other system write.
 */
enum InvoiceStatusChangeSource: string
{
    case Staff = 'staff';
    case System = 'system';
    case QboPull = 'qbo_pull';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::System => 'System',
            self::QboPull => 'QuickBooks pull',
        };
    }
}
