<?php

namespace App\Http\Controllers\Web;

use App\Enums\BillingPeriod;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Sku;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ContractService;
use App\Services\PrepayService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractService $contractService,
        private readonly PrepayService $prepayService,
    ) {}

    public function indexAll(Request $request)
    {
        $filters = $request->only(['client_id', 'status', 'type']);

        $query = Contract::with('client')
            ->withCount(['profiles', 'documents', 'people', 'assets', 'licenses'])
            ->orderBy('client_id')
            ->orderBy('name');

        $this->contractService->applyFilters($query, $filters);

        $contracts = $query->paginate(50)->withQueryString();

        $prepaySkus = Sku::where('is_active', true)
            ->whereNotNull('prepaid_time_minutes')
            ->where('prepaid_time_minutes', '>', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'unit_price', 'prepaid_time_minutes']);

        return view('contracts.index-all', [
            'contracts'  => $contracts,
            'filters'    => $filters,
            'clients'    => Client::active()->orderBy('name')->get(['id', 'name']),
            'statuses'   => ContractStatus::cases(),
            'types'      => ContractType::cases(),
            'prepaySkus' => $prepaySkus,
        ]);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:status,type,edit,delete,sla'],
        ]);

        // Resolve contract IDs — either from filter or explicit list
        if ($request->boolean('select_all_filter')) {
            $filters = [
                'client_id' => $request->input('filter_client_id'),
                'status' => $request->input('filter_status'),
                'type' => $request->input('filter_type'),
            ];

            $contractIds = $this->contractService->getFilteredContractIds($filters);

            if (empty($contractIds)) {
                return redirect()->route('contracts.index-all')
                    ->with('error', 'No contracts match the current filter.');
            }
        } else {
            $validated = $request->validate([
                'contract_ids' => ['required', 'array', 'min:1'],
                'contract_ids.*' => ['required', 'integer', 'exists:contracts,id'],
            ]);

            $contractIds = $validated['contract_ids'];
        }

        $action = $request->input('action');
        $userId = auth()->id();

        switch ($action) {
            case 'status':
                $request->validate(['status' => ['required', 'string']]);
                $status = ContractStatus::from($request->input('status'));
                $result = $this->contractService->bulkChangeStatus($contractIds, $status, $userId);
                $message = "{$result['affected']} contract(s) set to {$status->label()}.";
                break;

            case 'type':
                $request->validate(['type' => ['required', 'string']]);
                $type = ContractType::from($request->input('type'));
                $result = $this->contractService->bulkChangeType($contractIds, $type, $userId);
                $message = "{$result['affected']} contract(s) set to {$type->label()}.";
                break;

            case 'edit':
                $attributes = [];

                if ($request->filled('term_length_months')) {
                    $request->validate(['term_length_months' => ['integer', 'min:1', 'max:120']]);
                    $attributes['term_length_months'] = (int) $request->input('term_length_months');
                }

                if ($request->input('auto_renew') !== null && $request->input('auto_renew') !== '') {
                    $attributes['auto_renew'] = $request->input('auto_renew') === '1';
                }

                if ($request->filled('payment_terms_days')) {
                    $request->validate(['payment_terms_days' => ['integer', 'min:0', 'max:365']]);
                    $attributes['payment_terms_days'] = (int) $request->input('payment_terms_days');
                }

                if ($request->filled('portal_prepay_sku_id')) {
                    $request->validate(['portal_prepay_sku_id' => ['integer', 'exists:skus,id']]);
                    $attributes['portal_prepay_sku_id'] = (int) $request->input('portal_prepay_sku_id');
                }

                if (empty($attributes)) {
                    return redirect()->route('contracts.index-all')
                        ->with('error', 'No fields were changed.');
                }

                $result = $this->contractService->bulkEditAttributes($contractIds, $attributes, $userId);
                $message = "{$result['affected']} contract(s) updated.";
                break;

            case 'sla':
                $slaEnabled = $request->boolean('sla_enabled');
                $slaTerms = null;

                if ($slaEnabled) {
                    $slaTerms = ['response' => [], 'resolution' => []];
                    foreach (['p1', 'p2', 'p3', 'p4'] as $p) {
                        if ($request->filled("response_{$p}")) {
                            $slaTerms['response'][$p] = $request->input("response_{$p}");
                        }
                        if ($request->filled("resolution_{$p}")) {
                            $slaTerms['resolution'][$p] = $request->input("resolution_{$p}");
                        }
                    }
                    if (empty($slaTerms['response']) && empty($slaTerms['resolution'])) {
                        $slaTerms = null;
                    }
                }

                $affected = Contract::whereIn('id', $contractIds)->update(['sla_terms' => $slaTerms ? json_encode($slaTerms) : null]);
                $message = $slaEnabled
                    ? "{$affected} contract(s) SLA terms updated."
                    : "{$affected} contract(s) SLA terms cleared.";
                break;

            case 'delete':
                $result = $this->contractService->bulkDelete($contractIds, $userId);
                $message = "{$result['affected']} contract(s) deleted.";
                break;
        }

        return redirect()->route('contracts.index-all')
            ->with('success', $message);
    }

    public function index(Client $client)
    {
        $contracts = $client->contracts()
            ->withCount(['profiles', 'documents', 'people', 'assets', 'licenses'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('contracts.index', [
            'client' => $client,
            'contracts' => $contracts,
        ]);
    }

    public function create(Client $client)
    {
        return view('contracts.create', [
            'client' => $client,
            'types' => ContractType::cases(),
            'statuses' => ContractStatus::cases(),
            'billingPeriods' => BillingPeriod::cases(),
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'status' => ['required', 'string'],
            'billing_period' => ['required', 'string'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'term_length_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['client_id'] = $client->id;
        $validated['billing_source'] = 'psa';

        $contract = $this->contractService->createContract($validated);

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Contract created.');
    }

    public function show(Contract $contract)
    {
        $contract->load([
            'client',
            'documents.uploader',
            'profiles.lines.sku',
            'prepayTransactions' => fn ($q) => $q->with('user')->orderByDesc('date')->limit(20),
            'assets',
            'people',
            'licenses.licenseType',
            'assignmentRules',
        ]);

        $recentInvoices = $contract->invoices()
            ->with('client')
            ->orderByDesc('invoice_date')
            ->limit(10)
            ->get();

        // Unassigned entities for the assignment dropdowns
        $assignmentService = app(\App\Services\ContractAssignmentService::class);
        $unassignedAssets = $assignmentService->getUnassignedAssets($contract);
        $unassignedPeople = $assignmentService->getUnassignedPeople($contract);

        // Unassigned licenses — scoped to ANY contract for the client (not just this one)
        $unassignedLicenses = $assignmentService->getUnassignedLicenses($contract);

        \App\Support\RecentItems::track(auth()->id(), 'contract', $contract->id, \Illuminate\Support\Str::limit($contract->name, 30), route('contracts.show', $contract));

        return view('contracts.show', [
            'contract' => $contract,
            'recentInvoices' => $recentInvoices,
            'unassignedAssets' => $unassignedAssets,
            'unassignedPeople' => $unassignedPeople,
            'unassignedLicenses' => $unassignedLicenses,
            'ruleTypes' => \App\Enums\AssignmentRuleType::cases(),
            'types' => ContractType::cases(),
            'statuses' => ContractStatus::cases(),
            'billingPeriods' => BillingPeriod::cases(),
        ]);
    }

    public function tickets(Request $request, Contract $contract)
    {
        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'type' => $request->query('type'),
            'source' => $request->query('source'),
            'contract_id' => (string) $contract->id,
            'assignee_id' => $request->query('assignee_id', 'all'),
            'search' => $request->query('search'),
            'show_closed' => $request->boolean('show_closed'),
            'overdue' => $request->boolean('overdue'),
            'sort' => $request->query('sort', 'priority'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $ticketService = app(TicketService::class);
        $tickets = $ticketService->getTicketList($filters);
        $unassignedCount = Ticket::open()->where('contract_id', $contract->id)->whereNull('assignee_id')->count();

        // Load the same contract data as show()
        $contract->load([
            'client',
            'documents.uploader',
            'profiles.lines.sku',
            'prepayTransactions' => fn ($q) => $q->with('user')->orderByDesc('date')->limit(20),
            'assets',
            'people',
            'licenses.licenseType',
            'assignmentRules',
        ]);

        $recentInvoices = $contract->invoices()
            ->with('client')
            ->orderByDesc('invoice_date')
            ->limit(10)
            ->get();

        $assignmentService = app(\App\Services\ContractAssignmentService::class);
        $unassignedAssets = $assignmentService->getUnassignedAssets($contract);
        $unassignedPeople = $assignmentService->getUnassignedPeople($contract);
        $unassignedLicenses = $assignmentService->getUnassignedLicenses($contract);

        return view('contracts.show', [
            'contract' => $contract,
            'recentInvoices' => $recentInvoices,
            'unassignedAssets' => $unassignedAssets,
            'unassignedPeople' => $unassignedPeople,
            'unassignedLicenses' => $unassignedLicenses,
            'ruleTypes' => \App\Enums\AssignmentRuleType::cases(),
            'types' => ContractType::cases(),
            'statuses' => ContractStatus::cases(),
            'billingPeriods' => BillingPeriod::cases(),
            'activeTab' => 'tickets',
            'tickets' => $tickets,
            'ticketFilters' => $filters,
            'ticketUsers' => User::active()->orderBy('name')->get(['id', 'name']),
            'ticketClients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'ticketStatuses' => TicketStatus::cases(),
            'ticketPriorities' => TicketPriority::cases(),
            'ticketTypes' => TicketType::cases(),
            'ticketSources' => TicketSource::cases(),
            'unassignedCount' => $unassignedCount,
        ]);
    }

    public function invoices(Request $request, Contract $contract)
    {
        $query = Invoice::query()
            ->with(['client', 'contract', 'profile'])
            ->where('contract_id', $contract->id)
            ->orderByDesc('invoice_date');

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

        $invoiceFilters = $request->only(['client_id', 'contract_id', 'status', 'from_date', 'to_date']);
        $invoiceFilters['contract_id'] = (string) $contract->id;

        // Load the same contract data as show()
        $contract->load([
            'client',
            'documents.uploader',
            'profiles.lines.sku',
            'prepayTransactions' => fn ($q) => $q->with('user')->orderByDesc('date')->limit(20),
            'assets',
            'people',
            'licenses.licenseType',
            'assignmentRules',
        ]);

        $recentInvoices = $contract->invoices()
            ->with('client')
            ->orderByDesc('invoice_date')
            ->limit(10)
            ->get();

        $assignmentService = app(\App\Services\ContractAssignmentService::class);
        $unassignedAssets = $assignmentService->getUnassignedAssets($contract);
        $unassignedPeople = $assignmentService->getUnassignedPeople($contract);
        $unassignedLicenses = $assignmentService->getUnassignedLicenses($contract);

        return view('contracts.show', [
            'contract' => $contract,
            'recentInvoices' => $recentInvoices,
            'unassignedAssets' => $unassignedAssets,
            'unassignedPeople' => $unassignedPeople,
            'unassignedLicenses' => $unassignedLicenses,
            'ruleTypes' => \App\Enums\AssignmentRuleType::cases(),
            'types' => ContractType::cases(),
            'statuses' => ContractStatus::cases(),
            'billingPeriods' => BillingPeriod::cases(),
            'activeTab' => 'invoices',
            'invoices' => $invoices,
            'invoiceFilters' => $invoiceFilters,
            'invoiceClients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'invoiceStatuses' => InvoiceStatus::cases(),
        ]);
    }

    public function update(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'status' => ['required', 'string'],
            'billing_period' => ['required', 'string'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'term_length_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['billing_source'] = 'psa';

        $this->contractService->updateContract($contract, $validated);

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Contract updated.');
    }

    public function prepayAdjust(Request $request, Contract $contract)
    {
        if (! $contract->has_prepay) {
            return redirect()->route('contracts.show', $contract)
                ->with('error', 'Prepay is not enabled on this contract.');
        }

        $validated = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'value' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        if ($validated['type'] === 'credit') {
            $this->prepayService->addManualCredit(
                $contract, (float) $validated['value'], $validated['note'], auth()->user(),
            );
            $label = 'Credit';
        } else {
            $this->prepayService->addManualDebit(
                $contract, (float) $validated['value'], $validated['note'], auth()->user(),
            );
            $label = 'Debit';
        }

        $unit = $contract->prepay_as_amount ? 'dollars' : 'hours';

        return redirect()->route('contracts.show', $contract)
            ->with('success', "{$label} of {$validated['value']} {$unit} applied.");
    }

    public function updatePortalSku(Request $request, Contract $contract)
    {
        $skuId = $request->input('portal_prepay_sku_id');

        if ($skuId) {
            $sku = Sku::where('id', $skuId)
                ->where('is_active', true)
                ->whereNotNull('prepaid_time_minutes')
                ->where('prepaid_time_minutes', '>', 0)
                ->first();

            if (! $sku) {
                return redirect()->route('contracts.show', $contract)
                    ->with('error', 'Invalid SKU selected. Must be an active SKU with prepaid time configured.');
            }

            $contract->update(['portal_prepay_sku_id' => $sku->id]);

            return redirect()->route('contracts.show', $contract)
                ->with('success', "Portal purchase SKU set to {$sku->name}.");
        }

        // Clear the SKU
        $contract->update(['portal_prepay_sku_id' => null]);

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Portal purchase SKU removed.');
    }

    public function updateAlertSettings(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'prepay_alert_threshold' => 'nullable|numeric|min:0',
            'prepay_auto_topup_enabled' => 'boolean',
            'prepay_auto_topup_qty' => 'nullable|integer|min:1|max:99',
        ]);

        $contract->update([
            'prepay_alert_threshold' => $validated['prepay_alert_threshold'] ?: null,
            'prepay_auto_topup_enabled' => $validated['prepay_auto_topup_enabled'] ?? false,
            'prepay_auto_topup_qty' => $validated['prepay_auto_topup_qty'] ?? null,
            // Clear notification flag when settings change
            'prepay_alert_notified_at' => null,
        ]);

        return redirect()->route('contracts.show', $contract)->with('success', 'Alert settings updated.');
    }

    public function updateSlaTerms(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'sla_enabled' => 'boolean',
            'response_p1' => 'nullable|numeric|min:0.25',
            'response_p2' => 'nullable|numeric|min:0.25',
            'response_p3' => 'nullable|numeric|min:0.25',
            'response_p4' => 'nullable|numeric|min:0.25',
            'resolution_p1' => 'nullable|numeric|min:0.25',
            'resolution_p2' => 'nullable|numeric|min:0.25',
            'resolution_p3' => 'nullable|numeric|min:0.25',
            'resolution_p4' => 'nullable|numeric|min:0.25',
        ]);

        if (!$request->boolean('sla_enabled')) {
            $contract->update(['sla_terms' => null]);
            return redirect()->route('contracts.show', $contract)->with('success', 'SLA terms removed.');
        }

        $terms = [];

        $response = array_filter([
            'p1' => $validated['response_p1'] ?? null,
            'p2' => $validated['response_p2'] ?? null,
            'p3' => $validated['response_p3'] ?? null,
            'p4' => $validated['response_p4'] ?? null,
        ], fn ($v) => $v !== null);

        $resolution = array_filter([
            'p1' => $validated['resolution_p1'] ?? null,
            'p2' => $validated['resolution_p2'] ?? null,
            'p3' => $validated['resolution_p3'] ?? null,
            'p4' => $validated['resolution_p4'] ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($response)) {
            $terms['response'] = $response;
        }
        if (!empty($resolution)) {
            $terms['resolution'] = $resolution;
        }

        $contract->update(['sla_terms' => !empty($terms) ? $terms : null]);

        return redirect()->route('contracts.show', $contract)->with('success', 'SLA terms updated.');
    }

    public function initializePrepay(Request $request, Contract $contract)
    {
        if ($contract->has_prepay) {
            return redirect()->route('contracts.show', $contract)
                ->with('error', 'Prepay is already initialized on this contract.');
        }

        $validated = $request->validate([
            'prepay_as_amount' => ['boolean'],
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $asAmount = $request->boolean('prepay_as_amount');

        $contract->update([
            'prepay_as_amount' => $asAmount,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ]);

        $initialBalance = (float) ($validated['initial_balance'] ?? 0);
        if ($initialBalance > 0) {
            $this->prepayService->addManualCredit(
                $contract->fresh(),
                $initialBalance,
                $validated['note'] ?? 'Initial balance',
                auth()->user(),
            );
        }

        $unit = $asAmount ? 'dollar-based' : 'hours-based';

        return redirect()->route('contracts.show', $contract)
            ->with('success', "Prepay initialized ({$unit}).");
    }
}
