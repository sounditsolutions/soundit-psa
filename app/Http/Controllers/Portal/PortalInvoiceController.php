<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysPayOnline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PortalInvoiceController extends Controller
{
    /** Statuses visible to portal clients. */
    private const PORTAL_STATUSES = [
        InvoiceStatus::Posted,
        InvoiceStatus::Synced,
        InvoiceStatus::Paid,
    ];

    public function index(Request $request): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        $invoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', self::PORTAL_STATUSES)
            // #1173: the row's "partially paid" note reads the last
            // QBO-sourced status change. Without this it is one query per
            // invoice on every page of the list.
            ->with('latestQboStatusChange')
            ->orderByDesc('invoice_date')
            ->paginate(25);

        return view('portal.invoices.index', compact('invoices'));
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        if (! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        $invoice->load(['lines', 'latestQboStatusChange']);

        return view('portal.invoices.show', compact('invoice'));
    }

    /**
     * Pay Online via a BenjiPays applied link (#2065): mint (or reuse the
     * cached) link for this invoice and send the client to it.
     *
     * Ownership is 404, not 403: this route is reached only by the button,
     * and an invoice id from another client's ledger should read as "no such
     * invoice" to a probe rather than confirm it exists. The BenjiPays path
     * is re-checked here (toggle, QBO id, status) so a form submitted after
     * the toggle was turned off gives up on the link like the button would.
     *
     * A mint failure is logged (reason and status only, never a vendor
     * string). Both exits to Stripe go through stripeFallback(), and both are
     * guarded: every page renders this POST only while
     * paysOnlineViaBenjiPays() is true, so EITHER exit is reached from the
     * BenjiPays surface, whose balance note drops the full-amount caveat. A
     * PARTIALLY PAID invoice must therefore not be silently bounced to the
     * full-total Stripe page from either — the overpayment #1173 exists to
     * prevent — including from a page rendered under the toggle and clicked
     * after it was turned off or the key cleared, which is exactly the stale
     * form this re-read exists for. Otherwise the client goes to the Stripe
     * page if the invoice has one, else back to the invoice with a generic
     * flash — never a vendor message.
     */
    public function payOnline(Request $request, Invoice $invoice, BenjiPaysPayOnline $payOnline): RedirectResponse
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId || ! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        if (! $invoice->paysOnlineViaBenjiPays()) {
            // A stale form. The predicate is false NOW, but the page this click
            // came from rendered the POST, so it was the BenjiPays surface —
            // balance shown, full-amount caveat withheld.
            return $this->stripeFallback($invoice);
        }

        try {
            $link = $payOnline->linkFor($invoice);
        } catch (BenjiPaysException $e) {
            // Status-only: reason and HTTP status, never a vendor string. Without
            // this an operator has no record that Pay Online failed for a client.
            Log::warning('[BenjiPays] Applied link mint failed for portal Pay Online', [
                'invoice_id' => $invoice->getKey(),
                'reason' => $e->reason,
                'http_status' => $e->httpStatus,
            ]);

            return $this->stripeFallback($invoice);
        }

        return redirect()->away($link->url);
    }

    /**
     * The single exit to Stripe for both give-up paths, guarded for both.
     *
     * Reaching this method at all means the client clicked a Pay Online POST,
     * and every portal surface that renders that POST does so only while
     * paysOnlineViaBenjiPays() is true (the invoice pages and the order
     * confirmations through App\Support\PortalPayLink, the dashboard's unpaid
     * list on the same predicate) — a toggle-off (or no-key) install
     * renders a direct Stripe link instead and never arrives here. So the page
     * behind every click was the BenjiPays surface, which drops the "will
     * charge the full $X" caveat because the vendor page is priced at the
     * balance. A partially paid invoice must therefore not be sent to the
     * full-total Stripe page without the amount named — the overpayment #1173
     * exists to prevent — whether the mint failed or the predicate re-read
     * turned false between render and click.
     *
     * Nothing is refused forever by guarding: the invoice page this returns to
     * is re-rendered under the CURRENT config, so a toggle that stayed off
     * shows the full-amount caveat and today's Stripe link, one click away.
     *
     * Guarded on the Stripe URL too: with no Stripe page there is no
     * full-amount route to withhold, and saying otherwise would tell the
     * client something false about their own invoice.
     */
    private function stripeFallback(Invoice $invoice): RedirectResponse
    {
        if ($invoice->qboPartialBalanceLog() !== null && $invoice->stripe_invoice_url) {
            return redirect()->route('portal.invoices.show', $invoice)
                ->with('error', 'Online payment for the remaining balance is temporarily unavailable. Paying online would charge the full $'
                    .number_format((float) $invoice->total, 2)
                    .', so we have not sent you there — please try again later, or contact us to settle just the balance.');
        }

        if ($invoice->stripe_invoice_url && $invoice->status->isClientPayable()) {
            return redirect()->away($invoice->stripe_invoice_url);
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('error', 'Online payment is temporarily unavailable. Please try again later or contact us.');
    }
}
