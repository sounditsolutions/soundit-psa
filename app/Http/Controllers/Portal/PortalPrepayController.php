<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Services\NotificationService;
use App\Services\PrepayOrderService;
use App\Support\PortalConfig;
use App\Support\PortalPayLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalPrepayController extends Controller
{
    public function __construct(
        private readonly PrepayOrderService $orderService,
        private readonly NotificationService $notificationService,
    ) {}

    public function selectContract(Request $request): View|RedirectResponse
    {
        $clientId = $request->attributes->get('portal_client_id');

        $contracts = Contract::where('client_id', $clientId)
            ->active()
            ->whereNotNull('portal_prepay_sku_id')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->with('portalPrepaySku')
            ->orderBy('name')
            ->get();

        if ($contracts->isEmpty()) {
            return view('portal.prepaid.select', [
                'contracts' => $contracts,
                'fallbackUrl' => PortalConfig::orderUrlForClient($clientId),
            ]);
        }

        if ($contracts->count() === 1) {
            return redirect()->route('portal.prepaid.form', $contracts->first());
        }

        return view('portal.prepaid.select', [
            'contracts' => $contracts,
            'fallbackUrl' => null,
        ]);
    }

    public function showPurchaseForm(Request $request, Contract $contract): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($contract->client_id !== $clientId) {
            abort(403);
        }

        if (! $contract->is_portal_purchasable) {
            abort(404);
        }

        $contract->load('portalPrepaySku');
        $sku = $contract->portalPrepaySku;
        $now = now();

        return view('portal.prepaid.form', [
            'contract' => $contract,
            'sku' => $sku,
            'unitPrice' => $sku->portalPrepayUnitPrice($now),
            'promoInForce' => $sku->portalPromoInForce($now),
            'promoNote' => $sku->portalPromoClientNote($now),
            'hoursPerUnit' => round($sku->prepaid_time_minutes / 60, 1),
        ]);
    }

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $clientId = $request->attributes->get('portal_client_id');
        $person = $request->attributes->get('portal_person');

        if ($contract->client_id !== $clientId) {
            abort(403);
        }

        if (! $contract->is_portal_purchasable) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'expected_unit_price' => ['required', 'numeric'],
        ]);

        $contract->load('portalPrepaySku');
        $sku = $contract->portalPrepaySku;

        // The server decides the price, from the clock, once: the guard and the
        // charge use the same reading. The form's figure is only compared.
        $unitPrice = $sku->portalPrepayUnitPrice(now());

        // TOCTOU price guard: a form opened before a promo starts or ends is
        // refused, never charged the other price.
        if (round((float) $validated['expected_unit_price'], 2) !== round($unitPrice, 2)) {
            return redirect()->route('portal.prepaid.form', $contract)
                ->with('error', 'The price has changed — please review the updated price and try again.');
        }

        // SKU still active check
        if (! $sku->is_active) {
            return redirect()->route('portal.prepaid.select')
                ->with('error', 'This product is no longer available. Please contact us for assistance.');
        }

        // Duplicate-order guard: check for recent invoice on same contract by same person
        $recentDuplicate = Invoice::where('contract_id', $contract->id)
            ->where('client_id', $clientId)
            ->whereNull('profile_id')
            ->where('notes', 'like', "%portal%{$person->full_name}%")
            ->where('created_at', '>=', now()->subMinutes(2))
            ->first();

        if ($recentDuplicate) {
            return redirect()->route('portal.prepaid.confirmation', $recentDuplicate);
        }

        $invoice = $this->orderService->createPrepayInvoice(
            $contract, $sku, (int) $validated['quantity'], $person, $unitPrice,
        );

        // Push to billing backend after response completes
        app()->terminating(function () use ($invoice) {
            $this->orderService->pushToBillingBackend($invoice->fresh());
        });

        // Notify staff
        $hours = round(($sku->prepaid_time_minutes * (int) $validated['quantity']) / 60, 1);
        $this->notificationService->notifyPrepayPurchase($contract, $invoice, $person, $hours);

        return redirect()->route('portal.prepaid.confirmation', $invoice);
    }

    public function confirmation(Request $request, Invoice $invoice): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        // Guard: only show confirmation for portal-created invoices
        if ($invoice->profile_id !== null) {
            abort(404);
        }

        $invoice->load('lines', 'contract', 'client');

        $totalPrepaidMinutes = (int) $invoice->lines->sum('prepaid_time_minutes');
        $totalHours = round($totalPrepaidMinutes / 60, 1);

        // Payment link state comes from the one shared rule (PortalPayLink),
        // gated on payability there, so a Void invoice offers no pay link and
        // no "payment coming" (psa-bl36l R2A).
        return view('portal.prepaid.confirmation', [
            'invoice' => $invoice,
            'totalHours' => $totalHours,
            'payLink' => PortalPayLink::forOrderConfirmation($invoice),
            'awaitingSync' => PortalPayLink::awaitingSync($invoice),
        ]);
    }

    public function updateAlertSettings(Request $request, Contract $contract): RedirectResponse
    {
        // Verify this contract belongs to the portal user's client
        $portalClientId = $request->attributes->get('portal_client_id');
        if ($contract->client_id !== $portalClientId) {
            abort(403);
        }

        // Only company-wide access users can configure alerts
        $portalPerson = $request->attributes->get('portal_person');
        if (! $portalPerson?->company_wide_access) {
            abort(403);
        }

        $validated = $request->validate([
            'prepay_alert_threshold' => 'nullable|numeric|min:0',
            'prepay_auto_topup_enabled' => 'boolean',
            'prepay_auto_topup_qty' => 'nullable|integer|min:1|max:99',
        ]);

        $contract->update([
            'prepay_alert_threshold' => $validated['prepay_alert_threshold'] ?: null,
            'prepay_auto_topup_enabled' => $validated['prepay_auto_topup_enabled'] ?? false,
            'prepay_auto_topup_qty' => $validated['prepay_auto_topup_qty'] ?? null,
            'prepay_alert_notified_at' => null,
        ]);

        return redirect()->route('portal.contracts.show', $contract)->with('success', 'Alert settings saved.');
    }

    /**
     * Poll endpoint: returns the payment URL once the billing backend sync completes.
     */
    public function paymentStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        $invoice = $invoice->fresh();
        // The shared rule gates the whole provider decision on payability
        // (psa-bl36l R2A).
        $payLink = PortalPayLink::forOrderConfirmation($invoice);

        return response()->json([
            'payment_url' => $payLink?->url,
            // The BenjiPays source is a POST (a vendor mint), never a bare link.
            'payment_method' => $payLink?->isPost() ? 'post' : 'get',
            // Payability signal (psa-bl36l R4/A): lets the confirmation poll stop
            // and render a truthful terminal state when a Posted order is voided
            // mid-poll, instead of forever promising a payment link.
            'client_payable' => $invoice->status->isClientPayable(),
            'status_label' => $invoice->status->portalLabel(),
            'status_badge' => $invoice->status->portalBadgeClass(),
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) ($invoice->tax ?? 0),
            'total' => (float) $invoice->total,
        ]);
    }
}
