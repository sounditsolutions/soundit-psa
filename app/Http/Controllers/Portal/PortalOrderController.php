<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Sku;
use App\Services\NotificationService;
use App\Services\PortalOrderService;
use App\Support\PortalConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client-portal self-service product catalog ("Shop").
 *
 * Lets portal contacts browse operator-published SKUs and place a multi-item
 * order. Submitting materializes the order as a single Posted invoice and
 * hands off to the billing backend, mirroring {@see PortalPrepayController}
 * but generalized to arbitrary catalog products with no contract requirement.
 */
class PortalOrderController extends Controller
{
    public function __construct(
        private readonly PortalOrderService $orderService,
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(PortalConfig::shopEnabled(), 404);

        $skus = Sku::portalOrderable()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return view('portal.shop.index', [
            'groupedSkus' => $skus->groupBy(fn (Sku $sku) => $sku->category ?: 'Other'),
            'hasProducts' => $skus->isNotEmpty(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PortalConfig::shopEnabled(), 404);

        $clientId = $request->attributes->get('portal_client_id');
        $person = $request->attributes->get('portal_person');

        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'expected_prices' => ['array'],
            'expected_prices.*' => ['numeric'],
        ]);

        // Only active, published SKUs are orderable — resolve against the live catalog.
        $orderable = Sku::portalOrderable()->get()->keyBy('id');

        $items = [];
        foreach ($validated['quantities'] as $skuId => $qty) {
            $qty = (int) $qty;
            if ($qty < 1) {
                continue;
            }

            $sku = $orderable->get((int) $skuId);
            if (! $sku) {
                // A submitted SKU is no longer orderable — reject the whole order.
                return redirect()->route('portal.shop.index')
                    ->with('error', 'One or more selected products are no longer available. Please review the catalog and try again.');
            }

            // TOCTOU price guard — the catalog may have changed since the page loaded.
            $expected = $validated['expected_prices'][$skuId] ?? null;
            if ($expected !== null && (float) $expected !== (float) $sku->unit_price) {
                return redirect()->route('portal.shop.index')
                    ->with('error', 'Some prices have changed — please review the updated catalog and try again.');
            }

            $items[] = ['sku' => $sku, 'quantity' => $qty];
        }

        if ($items === []) {
            return redirect()->route('portal.shop.index')
                ->with('error', 'Please select at least one product to order.');
        }

        // Duplicate-order guard: same person, same client, within a 2-minute window.
        $recentDuplicate = Invoice::where('client_id', $clientId)
            ->whereNull('profile_id')
            ->where('notes', 'like', "Product order via client portal by {$person->full_name}")
            ->where('created_at', '>=', now()->subMinutes(2))
            ->first();

        if ($recentDuplicate) {
            return redirect()->route('portal.shop.confirmation', $recentDuplicate);
        }

        $client = Client::findOrFail($clientId);

        $invoice = $this->orderService->createOrderInvoice($client, $items, $person);

        // Push to billing backend after the response is sent.
        app()->terminating(function () use ($invoice) {
            $this->orderService->pushToBillingBackend($invoice->fresh());
        });

        // Notify staff.
        $this->notificationService->notifyProductOrder($client, $invoice, $person, count($items));

        return redirect()->route('portal.shop.confirmation', $invoice);
    }

    public function confirmation(Request $request, Invoice $invoice): View
    {
        abort_unless(PortalConfig::shopEnabled(), 404);

        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        // Guard: only show the confirmation for portal product-order invoices.
        if (! str_starts_with((string) $invoice->notes, 'Product order via client portal')) {
            abort(404);
        }

        $invoice->load('lines', 'client');

        // Determine payment link state (mirrors the prepay confirmation).
        $client = $invoice->client;
        $paymentUrl = null;
        $awaitingSync = false;

        if ($invoice->stripe_invoice_url) {
            $paymentUrl = $invoice->stripe_invoice_url;
        } elseif ($invoice->qbo_invoice_id && PortalConfig::billingUrl()) {
            $paymentUrl = PortalConfig::billingUrl().'/portal/pay/?invoiceNumber='
                .urlencode($invoice->invoice_number)
                .'&transactionAmount='.number_format($invoice->total, 2, '.', '');
        } elseif ($client?->stripe_customer_id || $client?->qbo_customer_id) {
            $awaitingSync = true;
        }

        return view('portal.shop.confirmation', [
            'invoice' => $invoice,
            'paymentUrl' => $paymentUrl,
            'awaitingSync' => $awaitingSync,
        ]);
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
        $paymentUrl = null;

        if ($invoice->stripe_invoice_url) {
            $paymentUrl = $invoice->stripe_invoice_url;
        } elseif ($invoice->qbo_invoice_id && PortalConfig::billingUrl()) {
            $paymentUrl = PortalConfig::billingUrl().'/portal/pay/?invoiceNumber='
                .urlencode($invoice->invoice_number)
                .'&transactionAmount='.number_format($invoice->total, 2, '.', '');
        }

        return response()->json([
            'payment_url' => $paymentUrl,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) ($invoice->tax ?? 0),
            'total' => (float) $invoice->total,
        ]);
    }
}
