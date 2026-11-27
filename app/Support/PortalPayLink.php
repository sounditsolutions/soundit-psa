<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * The one rule for "how does a portal client pay this invoice online".
 *
 * Every portal pay surface reads it: the prepaid and shop order confirmations
 * (page and payment-status poll) and the portal invoice show and index pages.
 * Before this class each of them carried its own copy, and they had drifted:
 * the confirmations offered the QuickBooks billing-portal link while the
 * invoice pages did not, so a QuickBooks-billed client who left the
 * confirmation page found nothing to click on the invoice itself.
 *
 * The order, first match wins, all gated on a client-payable status
 * (psa-bl36l R2A: a Void or Paid invoice offers no pay affordance at all):
 *
 *   1. BenjiPays applied link (#2065), when Invoice::paysOnlineViaBenjiPays().
 *      This is a POST to portal.invoices.pay-online, never a bare link: the
 *      mint is a vendor write. The vendor prices that page at the remaining
 *      balance, so it is the one source that is NOT a full-amount charge.
 *   2. The Stripe hosted invoice page, when the invoice has one.
 *   3. The QuickBooks billing-portal pay page, when the invoice has a QBO id
 *      and portal_billing_url is set, and the invoice is NOT partially paid.
 *
 * The Stripe page is addressed at the invoice total, and the balance note
 * already warns about that beside it on a partially paid invoice (#1173). The
 * billing-portal link is built with transactionAmount = the invoice total, and
 * no wording beside it has been approved for a partial balance, so on a
 * partially paid invoice it is withheld rather than offered at the full total:
 * the client sees the balance note and contacts us, as before this class.
 */
final class PortalPayLink
{
    public const BENJIPAYS = 'benjipays';

    public const STRIPE = 'stripe';

    public const QBO_BILLING = 'qbo_billing';

    private function __construct(
        public readonly string $source,
        public readonly string $url,
    ) {}

    /**
     * The pay link for $invoice, or null when it offers none.
     */
    public static function for(Invoice $invoice): ?self
    {
        if (! $invoice->status->isClientPayable()) {
            return null;
        }

        if ($invoice->paysOnlineViaBenjiPays()) {
            return new self(self::BENJIPAYS, route('portal.invoices.pay-online', $invoice));
        }

        if ($invoice->stripe_invoice_url) {
            return new self(self::STRIPE, $invoice->stripe_invoice_url);
        }

        $billingUrl = PortalConfig::billingUrl();

        if ($invoice->qbo_invoice_id && $billingUrl && $invoice->qboPartialBalanceLog() === null) {
            return new self(self::QBO_BILLING, $billingUrl.'/portal/pay/?invoiceNumber='
                .urlencode((string) $invoice->invoice_number)
                .'&transactionAmount='.number_format((float) $invoice->total, 2, '.', ''));
        }

        return null;
    }

    /**
     * Whether a confirmation page with no link yet should wait for one: the
     * invoice is payable, it has not already reached a backend that would
     * have produced a link (a Stripe page, or a QBO id with a billing URL),
     * and its client has a billing backend the order is being pushed to.
     * A QBO invoice whose link is withheld as partially paid is therefore not
     * "on its way".
     */
    public static function awaitingSync(Invoice $invoice): bool
    {
        if (self::for($invoice) !== null || ! $invoice->status->isClientPayable()) {
            return false;
        }

        if ($invoice->stripe_invoice_url || ($invoice->qbo_invoice_id && PortalConfig::billingUrl())) {
            return false;
        }

        $client = $invoice->client;

        return (bool) ($client?->stripe_customer_id || $client?->qbo_customer_id);
    }

    /** True when following the link must be a POST (the BenjiPays mint). */
    public function isPost(): bool
    {
        return $this->source === self::BENJIPAYS;
    }
}
