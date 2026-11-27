<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * The one rule for "how does a portal client pay this invoice online".
 *
 * The prepaid and shop order confirmations (page and payment-status poll) and
 * the portal invoice show and index pages read it. The dashboard's Unpaid
 * Invoices list does NOT: it still carries its own BenjiPays/Stripe rule, with
 * no QuickBooks billing-portal arm. Before this class each confirmation and
 * invoice page carried its own copy, and they had drifted: the confirmations
 * offered the QuickBooks billing-portal link while the invoice pages did not,
 * so a QuickBooks-billed client who left the confirmation page found nothing
 * to click on the invoice itself.
 *
 * The order, first match wins, all gated on a client-payable status
 * (psa-bl36l R2A: a Void or Paid invoice offers no pay affordance at all):
 *
 *   1. BenjiPays applied link (#2065), when Invoice::paysOnlineViaBenjiPays().
 *      This is a POST to portal.invoices.pay-online, never a bare link: the
 *      mint is a vendor write. The mint sends no amount; the vendor sets it.
 *   2. The Stripe hosted invoice page, when the invoice has one.
 *   3. The QuickBooks billing-portal pay page, when the invoice has a QBO id
 *      and portal_billing_url is set, and the invoice is NOT partially paid.
 *
 * The Stripe page is the invoice's hosted_invoice_url. Nothing records a
 * QuickBooks part-payment on the Stripe invoice, so it collects the full
 * total; on the invoice pages the balance note warns about that beside it
 * (#1173). The billing-portal link is built with transactionAmount = the
 * invoice total, and no wording beside it has been approved for a partial
 * balance, so on a partially paid invoice it is withheld rather than offered
 * at the full total. That withholding is new with this class: before it, the
 * prepaid and shop confirmations (PortalPrepayController,
 * PortalOrderController) offered the billing-portal link at the full total
 * with no partial check.
 *
 * forOrderConfirmation() is the order confirmations' read. There the button
 * reads "Pay Now — $<total>" with no caveat beside it, so on a partially paid
 * invoice it offers nothing from ANY source and the page shows the balance
 * note instead: the Stripe page would charge the full total, and the amount
 * the BenjiPays page collects is not documented by the vendor.
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
     * The pay link for an order confirmation (page and payment-status poll),
     * or null. As for(), but null on a partially paid invoice from every
     * source: the confirmation's "Pay Now — $<total>" would name, and may
     * charge, the full total (#1173).
     */
    public static function forOrderConfirmation(Invoice $invoice): ?self
    {
        if ($invoice->qboPartialBalanceLog() !== null) {
            return null;
        }

        return self::for($invoice);
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
