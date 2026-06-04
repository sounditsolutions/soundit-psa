<?php

namespace App\Http\Controllers\Web;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Sku;
use App\Services\InvoiceService;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use App\Support\StripeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function index(Request $request)
    {
        $query = Invoice::query()
            ->with(['client', 'contract', 'profile'])
            ->orderByDesc('invoice_date');

        if ($request->filled('client_id')) {
            $query->forClient($request->query('client_id'));
        }

        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->query('contract_id'));
        }

        if ($request->filled('status')) {
            if ($request->query('status') === 'outstanding') {
                $query->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced]);
            } else {
                $query->where('status', $request->query('status'));
            }
        }

        if ($request->filled('from_date')) {
            $query->where('invoice_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('invoice_date', '<=', $request->query('to_date'));
        }

        $invoices = $query->paginate(25)->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => InvoiceStatus::cases(),
            'filters' => $request->only(['client_id', 'contract_id', 'status', 'from_date', 'to_date']),
        ]);
    }

    public function create(Request $request)
    {
        $clients = Client::active()->orderBy('name')->get(['id', 'name']);
        $skus = Sku::active()->orderBy('name')->get();

        // Pre-load contracts grouped by client for JS cascading dropdown
        $contracts = Contract::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'client_id', 'name', 'payment_terms_days']);

        $contractsByClient = $contracts->groupBy('client_id')
            ->map(fn ($group) => $group->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'payment_terms_days' => $c->payment_terms_days,
            ])->values());

        return view('invoices.create', [
            'clients' => $clients,
            'skus' => $skus,
            'contractsByClient' => $contractsByClient,
            'preselectedClientId' => $request->query('client_id'),
            'preselectedContractId' => $request->query('contract_id'),
        ]);
    }

    public function store(InvoiceStoreRequest $request)
    {
        $invoice = $this->invoiceService->createInvoice(
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_number} created.");
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'contract', 'profile', 'lines']);

        $qboViewUrl = null;
        if ($invoice->qbo_invoice_id) {
            $qboEnv = Setting::getValue('qbo_environment', 'production');
            $qboBase = $qboEnv === 'sandbox'
                ? 'https://app.sandbox.qbo.intuit.com'
                : 'https://app.qbo.intuit.com';
            $qboViewUrl = $qboBase.'/app/invoice?txnId='.$invoice->qbo_invoice_id;
        }

        $stripeDashboardUrl = null;
        if ($invoice->stripe_invoice_id) {
            $stripeMode = StripeConfig::get('mode', 'test');
            $stripeBase = $stripeMode === 'live'
                ? 'https://dashboard.stripe.com/invoices/'
                : 'https://dashboard.stripe.com/test/invoices/';
            $stripeDashboardUrl = $stripeBase.$invoice->stripe_invoice_id;
        }

        return view('invoices.show', [
            'invoice' => $invoice,
            'qboViewUrl' => $qboViewUrl,
            'stripeDashboardUrl' => $stripeDashboardUrl,
        ]);
    }

    public function edit(Invoice $invoice)
    {
        if (! $invoice->is_editable) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'This invoice cannot be edited.');
        }

        $invoice->load(['client', 'contract', 'lines.sku']);

        return view('invoices.edit', [
            'invoice' => $invoice,
            'skus' => Sku::active()->orderBy('name')->get(),
        ]);
    }

    public function update(InvoiceUpdateRequest $request, Invoice $invoice)
    {
        if (! $invoice->is_editable) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'This invoice cannot be edited.');
        }

        $this->invoiceService->updateInvoice($invoice, $request->validated(), $request->user());

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice updated.');
    }

    public function pushToQbo(Invoice $invoice, QboSyncService $syncService)
    {
        try {
            $syncService->pushInvoiceToQbo($invoice);
        } catch (QboClientException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'QBO sync failed: '.$e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice pushed to QuickBooks.');
    }

    public function syncFromQbo(Invoice $invoice, QboSyncService $syncService)
    {
        try {
            $syncService->syncInvoiceStatusFromQbo($invoice);
        } catch (QboClientException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'QBO sync failed: '.$e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice refreshed from QuickBooks.');
    }

    public function pushToStripe(Request $request, Invoice $invoice)
    {
        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);
        $service = new StripeSyncService($client);
        $sendEmail = $request->boolean('send_email');

        try {
            $service->pushInvoiceToStripe($invoice, $sendEmail);
        } catch (StripeClientException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Stripe sync failed: '.$e->getMessage());
        }

        $msg = $sendEmail
            ? 'Invoice pushed to Stripe and emailed to client.'
            : 'Invoice pushed to Stripe.';

        return redirect()->route('invoices.show', $invoice)
            ->with('success', $msg);
    }

    public function syncFromStripe(Invoice $invoice)
    {
        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);
        $service = new StripeSyncService($client);

        try {
            $service->syncInvoiceStatusFromStripe($invoice);
        } catch (StripeClientException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Stripe sync failed: '.$e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice refreshed from Stripe.');
    }

    public function sendFromStripe(Invoice $invoice)
    {
        if (! $invoice->stripe_invoice_id) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Invoice has not been pushed to Stripe.');
        }

        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);

        try {
            $client->sendInvoice($invoice->stripe_invoice_id);
        } catch (StripeClientException $e) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Failed to send email: '.$e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice email sent to client via Stripe.');
    }

    public function importFromStripe()
    {
        if (! StripeConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Stripe is not configured.');
        }

        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);
        $service = new StripeSyncService($client);

        try {
            $result = $service->importInvoicesFromStripe();
        } catch (\Throwable $e) {
            return redirect()->route('invoices.index')
                ->with('error', 'Stripe invoice import failed: '.$e->getMessage());
        }

        $skipped = $result->deactivated;
        $msg = "Stripe import: {$result->summary()}";
        if ($skipped > 0) {
            $msg .= ", {$skipped} skipped (no client match)";
        }

        return redirect()->route('invoices.index')
            ->with('success', $msg);
    }

    public function bulkAction(Request $request, QboSyncService $qboSyncService)
    {
        $request->validate([
            'action' => ['required', 'string', 'in:push,post,void'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'exists:invoices,id'],
        ]);

        $action = $request->input('action');
        $invoices = Invoice::with('client')->whereIn('id', $request->input('invoice_ids'))->get();

        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        $stripeSyncService = StripeConfig::isConfigured()
            ? new StripeSyncService(new StripeClient(['secret_key' => StripeConfig::get('secret_key')]))
            : null;

        foreach ($invoices as $invoice) {
            try {
                switch ($action) {
                    case 'push':
                        if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::PendingSync])) {
                            $skipped++;

                            continue 2;
                        }
                        if ($invoice->client?->stripe_customer_id && $stripeSyncService && ! $invoice->stripe_invoice_id) {
                            $stripeSyncService->pushInvoiceToStripe($invoice);
                            $succeeded++;
                        } elseif ($invoice->client?->qbo_customer_id && ! $invoice->qbo_invoice_id) {
                            $qboSyncService->pushInvoiceToQbo($invoice);
                            $succeeded++;
                        } else {
                            $skipped++;
                        }
                        break;

                    case 'post':
                        if ($invoice->status !== InvoiceStatus::Draft) {
                            $skipped++;

                            continue 2;
                        }
                        $invoice->update(['status' => InvoiceStatus::Posted]);
                        $succeeded++;
                        break;

                    case 'void':
                        if ($invoice->status === InvoiceStatus::Void) {
                            $skipped++;

                            continue 2;
                        }
                        $invoice->update(['status' => InvoiceStatus::Void]);
                        if ($invoice->qbo_invoice_id) {
                            $qboSyncService->voidInvoiceInQbo($invoice);
                        }
                        $succeeded++;
                        break;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[Invoice] Bulk action failed', [
                    'action' => $action,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $labels = ['push' => 'pushed', 'post' => 'posted', 'void' => 'voided'];
        $label = $labels[$action];
        $parts = ["{$succeeded} invoice(s) {$label}"];
        if ($failed > 0) {
            $parts[] = "{$failed} failed";
        }
        if ($skipped > 0) {
            $parts[] = "{$skipped} skipped";
        }

        return redirect()->route('invoices.index')
            ->with($failed > 0 ? 'warning' : 'success', implode(', ', $parts).'.');
    }

    public function void(Invoice $invoice, QboSyncService $syncService)
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Invoice is already voided.');
        }

        $invoice->update(['status' => InvoiceStatus::Void]);

        // Push void to QBO if this invoice was synced there
        if ($invoice->qbo_invoice_id) {
            try {
                $syncService->voidInvoiceInQbo($invoice);
            } catch (QboClientException $e) {
                $docRef = $invoice->qbo_doc_number ?? $invoice->qbo_invoice_id;

                return redirect()->route('invoices.show', $invoice)
                    ->with('warning', "Invoice voided in Sound PSA. QBO void failed — you may need to void invoice #{$docRef} manually in QuickBooks.");
            }
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice voided.');
    }
}
