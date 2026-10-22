<?php

namespace App\Http\Controllers\Web;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientCredentialsUpdateRequest;
use App\Http\Requests\ClientSiteNotesUpdateRequest;
use App\Http\Requests\ClientStoreRequest;
use App\Http\Requests\ClientUpdateRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Sku;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Services\AssetService;
use App\Services\ClientBillingSummary;
use App\Services\ClientIntegrationService;
use App\Services\ClientService;
use App\Services\ContractResolver;
use App\Services\PrepayService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly ActivityStreamService $activityStream,
        private readonly ClientIntegrationService $integrationService,
    ) {}

    public function index(Request $request)
    {
        // Stage facet: all (default — is_active, both stages) / active (operational
        // customers) / prospect (not-yet-customers). scopeActive() includes prospects.
        $stage = in_array($request->query('stage'), ['active', 'prospect'], true)
            ? $request->query('stage')
            : 'all';

        $query = match ($stage) {
            'active' => Client::operational(),
            'prospect' => Client::active()->where('stage', \App\Enums\ClientStage::Prospect),
            default => Client::active(),
        };

        $clients = $query
            ->search($request->query('search'))
            ->with('reseller:id,name')
            ->withCount('people')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'search' => $request->query('search'),
            'stage' => $stage,
        ]);
    }

    public function create()
    {
        return view('clients.create', [
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'resellerCandidates' => Client::operational()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ClientStoreRequest $request)
    {
        $client = $this->clientService->createClient($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Client created successfully.');
    }

    public function show(Client $client)
    {
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);

        $client->loadCount('assets');

        $assets = $client->assets()
            ->active()
            ->orderBy('hostname')
            ->orderBy('name')
            ->limit(25)
            ->get();

        \App\Support\RecentItems::track(auth()->id(), 'client', $client->id, $client->name, route('clients.show', $client));

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => $assets,
            'integrations' => $integrations,
            'mergeCandidates' => $this->mergeCandidatesFor($client),
        ]);
    }

    /**
     * Other clients that could be merged INTO this one (this client survives).
     * Includes inactive clients — a deactivated duplicate is a prime merge target.
     */
    private function mergeCandidatesFor(Client $client)
    {
        return Client::query()
            ->where('id', '!=', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'stage']);
    }

    public function tickets(Request $request, Client $client)
    {
        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'type' => $request->query('type'),
            'source' => $request->query('source'),
            'client_id' => (string) $client->id,
            'assignee_id' => $request->query('assignee_id', 'all'),
            'search' => $request->query('search'),
            'show_closed' => $request->boolean('show_closed'),
            'overdue' => $request->boolean('overdue'),
            'sort' => $request->query('sort', 'priority'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $ticketService = app(TicketService::class);
        $tickets = $ticketService->getTicketList($filters);
        $unassignedCount = Ticket::open()->where('client_id', $client->id)->whereNull('assignee_id')->count();

        // Load the same client detail data as show()
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $integrations,
            'activeTab' => 'tickets',
            'tickets' => $tickets,
            'ticketFilters' => $filters,
            'ticketUsers' => User::active()->orderBy('name')->get(['id', 'name']),
            // active(), not operational(): prospect client records can have intake tickets.
            'ticketClients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'ticketStatuses' => TicketStatus::cases(),
            'ticketPriorities' => TicketPriority::cases(),
            'ticketTypes' => TicketType::cases(),
            'ticketSources' => TicketSource::cases(),
            'unassignedCount' => $unassignedCount,
        ]);
    }

    public function people(Request $request, Client $client)
    {
        $query = Person::active()
            ->with('client')
            ->where('client_id', $client->id)
            ->search($request->query('search'));

        if ($request->filled('person_type')) {
            $query->where('person_type', $request->query('person_type'));
        }

        $people = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(50)
            ->withQueryString();

        // Load the same client detail data as show()
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $integrations,
            'activeTab' => 'people',
            'people' => $people,
            'peopleClients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'peopleSearch' => $request->query('search'),
            'peopleClientId' => $request->query('client_id'),
            'peoplePersonType' => $request->query('person_type'),
        ]);
    }

    public function licenses(Request $request, Client $client)
    {
        $licenseTypeId = $request->query('license_type_id');
        $vendor = $request->query('vendor');
        $wasteOnly = $request->boolean('waste_only');

        $licenses = License::query()
            ->with(['licenseType', 'client'])
            ->where('client_id', $client->id)
            ->when($licenseTypeId, fn ($q, $v) => $q->where('license_type_id', $v))
            ->when($vendor, fn ($q) => $q->whereHas('licenseType', fn ($q2) => $q2->where('vendor', $vendor)))
            ->when($wasteOnly, fn ($q) => $q->whereNotNull('assigned_quantity')->whereColumn('assigned_quantity', '<', 'quantity'))
            ->orderBy('license_type_id')
            ->paginate(50)
            ->withQueryString();

        // Load the same client detail data as show()
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $integrations,
            'activeTab' => 'licenses',
            'licenseList' => $licenses,
            'licenseClients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'licenseTypes' => LicenseType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'licenseVendors' => LicenseType::distinct()->whereNotNull('vendor')->pluck('vendor')->sort()->values(),
            'licenseClientId' => (string) $client->id,
            'licenseTypeId' => $licenseTypeId,
            'licenseVendor' => $vendor,
            'licenseWasteOnly' => $wasteOnly,
        ]);
    }

    /**
     * Read-only Billing tab (card iNjqGETC): invoices, outstanding and
     * overdue, contracts and recurring, prepay and the BenjiPays read. The
     * data is built only on this route, never on the other tabs.
     */
    public function billing(Client $client, ClientBillingSummary $summary)
    {
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $this->integrationService->buildIntegrationsData($client),
            'activeTab' => 'billing',
            'billing' => $summary->build($client),
        ]);
    }

    public function assetList(Request $request, Client $client)
    {
        $filters = [
            'search' => $request->query('search'),
            'client_id' => (string) $client->id,
            'asset_type' => $request->query('asset_type'),
            'status' => $request->query('status'),
            'rmm' => $request->query('rmm'),
            'user_assignment' => $request->query('user_assignment'),
            'show_inactive' => $request->boolean('show_inactive'),
            'show_deleted' => $request->boolean('show_deleted'),
            'sort' => $request->query('sort', 'hostname'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $assetService = app(AssetService::class);
        $assetList = $assetService->getAssetList($filters);
        $assetTypes = Asset::active()->where('client_id', $client->id)
            ->whereNotNull('asset_type')
            ->where('asset_type', '!=', '')
            ->distinct()->pluck('asset_type')->sort()->values();

        // Load the same client detail data as show()
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $integrations,
            'activeTab' => 'assets',
            'assetList' => $assetList,
            'assetFilters' => $filters,
            'assetClients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'assetTypes' => $assetTypes,
        ]);
    }

    public function contracts(Request $request, Client $client)
    {
        $filters = [
            'client_id' => (string) $client->id,
            'status' => $request->query('status'),
            'type' => $request->query('type'),
        ];

        $contracts = Contract::with('client')
            ->where('client_id', $client->id)
            ->withCount(['profiles', 'documents', 'people', 'assets', 'licenses'])
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'], fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $prepaySkus = Sku::where('is_active', true)
            ->whereNotNull('prepaid_time_minutes')
            ->where('prepaid_time_minutes', '>', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'unit_price', 'prepaid_time_minutes']);

        // Load the same client detail data as show()
        $client->load([
            'people' => fn ($q) => $q->active()
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'siteNotesUpdatedBy',
            'credentialsUpdatedBy',
            'primaryTech',
            'reseller',
            'resellerChildren',
        ]);
        $client->loadCount('assets');

        $integrations = $this->integrationService->buildIntegrationsData($client);

        return view('clients.show', [
            'client' => $client,
            'assets' => collect(),
            'integrations' => $integrations,
            'activeTab' => 'contracts',
            'contractList' => $contracts,
            'contractFilters' => $filters,
            'contractClients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'contractStatuses' => ContractStatus::cases(),
            'contractTypes' => ContractType::cases(),
            'prepaySkus' => $prepaySkus,
        ]);
    }

    public function activity(Request $request, Client $client)
    {
        $request->validate([
            'before' => 'nullable|date',
            'types' => 'nullable|string',
        ]);

        $types = $request->filled('types')
            ? array_filter(explode(',', $request->input('types')))
            : [];

        $before = $request->filled('before')
            ? Carbon::parse($request->input('before'))
            : null;

        $stream = $this->activityStream->getClientStream($client->id, $before, 30, $types);

        return view('dashboard._activity-stream', [
            'stream' => $stream,
            'showClient' => false,
        ]);
    }

    public function edit(Client $client)
    {
        $client->load(['siteNotesUpdatedBy', 'credentialsUpdatedBy']);

        $resellerCandidates = Client::operational()
            ->where('id', '!=', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Include the current reseller even if inactive, so the dropdown doesn't silently clear it
        if ($client->reseller_id && ! $resellerCandidates->contains('id', $client->reseller_id)) {
            $reseller = Client::find($client->reseller_id, ['id', 'name']);
            if ($reseller) {
                $resellerCandidates->push($reseller)->sortBy('name')->values();
            }
        }

        return view('clients.edit', [
            'client' => $client,
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'resellerCandidates' => $resellerCandidates,
        ]);
    }

    public function update(ClientUpdateRequest $request, Client $client)
    {
        $this->clientService->updateClient($client, $request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Client updated successfully.');
    }

    public function destroy(Client $client)
    {
        try {
            $this->clientService->deleteClient($client);
        } catch (\RuntimeException $e) {
            return redirect()->route('clients.show', $client)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('clients.index')
            ->with('success', 'Client deleted successfully.');
    }

    public function merge(Request $request, Client $client)
    {
        // Laravel's bare `exists` rule ignores the SoftDeletes scope, so scope the
        // candidate to non-deleted rows. The service enforces the self-merge guard
        // as the inner layer (defense in depth).
        $validated = $request->validate([
            'duplicate_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(fn ($q) => $q->whereNull('deleted_at')),
            ],
        ]);

        // findOrFail re-applies the SoftDeletes scope → clean 404 on a tampered id
        $duplicate = Client::findOrFail($validated['duplicate_id']);
        $duplicateName = $duplicate->name;

        try {
            $summary = $this->clientService->mergeClients($client, $duplicate, auth()->id());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->route('clients.show', $client)->with('error', $e->getMessage());
        }

        $parts = [];
        foreach ([
            ['tickets', 'ticket', 'tickets'],
            ['assets', 'device', 'devices'],
            ['people', 'contact', 'contacts'],
            ['calls', 'call', 'calls'],
            ['emails', 'email', 'emails'],
            ['contracts', 'contract', 'contracts'],
            ['invoices', 'invoice', 'invoices'],
            ['licenses', 'license', 'licenses'],
            ['reseller_children', 'reseller child', 'reseller children'],
        ] as [$key, $one, $many]) {
            if (! empty($summary[$key])) {
                $parts[] = $summary[$key].' '.($summary[$key] === 1 ? $one : $many);
            }
        }
        $movedText = $parts ? ' Moved '.implode(', ', $parts).'.' : '';

        return redirect()->route('clients.show', $client)
            ->with('success', "Merged {$duplicateName} into {$client->name}.{$movedText}");
    }

    public function updateSiteNotes(ClientSiteNotesUpdateRequest $request, Client $client)
    {
        try {
            $this->clientService->updateSiteNotes(
                $client,
                $request->validated('site_notes'),
                $request->validated('site_notes_updated_at'),
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('clients.show', $client)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('clients.show', $client)
            ->with('success', 'Site notes updated.');
    }

    public function updateCredentials(ClientCredentialsUpdateRequest $request, Client $client)
    {
        try {
            $this->clientService->updateCredentials(
                $client,
                $request->validated('credentials'),
                $request->validated('credentials_updated_at'),
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('clients.show', $client)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('clients.show', $client)
            ->with('success', 'Credentials updated.');
    }

    public function contacts(Client $client): JsonResponse
    {
        $contacts = $client->people()
            ->with('emailAddresses')
            ->active()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email']);

        return response()->json($contacts->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->full_name,
            'email' => $p->email,
            'all_emails' => $p->allEmailAddresses(),
        ]));
    }

    /**
     * The new-ticket form's Contract picker (card I3EvQKUV PR 3, mockup 4): this client's
     * ACTIVE contracts only (ContractStatus::Active, not soft-deleted), with is_default
     * marking the client default ContractResolver would take. A stale default marks none.
     */
    public function activeContracts(Client $client): JsonResponse
    {
        $resolution = app(\App\Services\ContractResolver::class)->forClient($client->id);
        $defaultId = $resolution->isResolved() && $resolution->rule === \App\Services\ContractResolver::RULE_CLIENT_DEFAULT
            ? $resolution->contract->id : null;

        $contracts = app(\App\Services\ContractResolver::class)->activeForClient($client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'prepay_balance', 'prepay_as_amount', 'sla_terms']);

        return response()->json($contracts->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type?->value,
            'type_label' => $c->type?->label(),
            'prepay_balance' => $c->prepay_balance,
            'prepay_unit' => $c->prepay_balance === null ? null : ($c->prepay_as_amount ? 'dollars' : 'hours'),
            'is_default' => $c->id === $defaultId,
            'resolution_sla_hours' => (object) ($c->sla_terms['resolution'] ?? []),
        ])->values());
    }

    public function assets(Client $client): JsonResponse
    {
        $assets = $client->assets()
            ->active()
            ->orderBy('hostname')
            ->orderBy('name')
            ->get(['id', 'name', 'hostname']);

        return response()->json($assets->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->hostname ?: $a->name,
        ]));
    }

    public function generateInstallLink(Client $client): RedirectResponse
    {
        return redirect()->route('clients.show', $client)
            ->with(app(\App\Services\Portal\PortalInstallService::class)->generateInstallLink($client));
    }

    public function rotateInstallLink(Client $client): RedirectResponse
    {
        return redirect()->route('clients.show', $client)
            ->with(app(\App\Services\Portal\PortalInstallService::class)->rotateInstallLink($client));
    }

    public function disableInstallLink(Client $client): RedirectResponse
    {
        return redirect()->route('clients.show', $client)
            ->with(app(\App\Services\Portal\PortalInstallService::class)->disableInstallLink($client));
    }

    public function updatePortalPrimaryRmm(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'portal_primary_rmm' => ['required', 'in:ninja,level,tactical'],
        ]);

        if (! in_array($validated['portal_primary_rmm'], $client->availableRmms(), true)) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'That RMM is not mapped to this client.');
        }

        $client->update(['portal_primary_rmm' => $validated['portal_primary_rmm']]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Primary RMM updated.');
    }

    /**
     * Set or clear the client's default contract (card I3EvQKUV §1): any
     * ACTIVE contract of this client (ruling Q1). The change is recorded on
     * the history of each contract it touches. Choosing a default re-runs the
     * debits held for want of one (r1 diff:1).
     */
    public function updateDefaultContract(Request $request, Client $client, ContractResolver $resolver, PrepayService $prepay): RedirectResponse
    {
        $validated = $request->validate([
            'default_contract_id' => ['nullable', 'integer'],
        ]);
        $newId = isset($validated['default_contract_id']) ? (int) $validated['default_contract_id'] : null;

        if ($newId !== null && ! $resolver->canBeDefault($client->id, $newId)) {
            $owned = Contract::whereKey($newId)->where('client_id', $client->id)->exists();

            return redirect()->route('clients.show', $client)
                ->withErrors(['default_contract_id' => $owned
                    ? 'Only an active contract can be the default.'
                    : 'That contract belongs to another client.'])
                ->with('error', $owned
                    ? 'Only an active contract can be the default.'
                    : 'That contract belongs to another client.');
        }

        $oldId = $client->default_contract_id === null ? null : (int) $client->default_contract_id;
        if ($oldId !== $newId) {
            DB::transaction(function () use ($client, $oldId, $newId) {
                $client->forceFill(['default_contract_id' => $newId])->save();
                $changes = ['client_id' => $client->id, 'from_contract_id' => $oldId, 'to_contract_id' => $newId];
                foreach (array_filter([$oldId, $newId]) as $contractId) {
                    ContractActivity::create([
                        'contract_id' => $contractId,
                        'user_id' => auth()->id(),
                        'action' => $contractId === $newId ? 'client_default_set' : 'client_default_cleared',
                        'changes' => $changes,
                        'created_at' => now(),
                    ]);
                }
            });
        }

        // Choosing a default re-runs the client's held debits: entries that now resolve go through the
        // debit path again, which debits only to an hours prepay contract, and entries that still do not
        // resolve (such as a ticket awaiting verification) stay held.
        // Re-submitting the same default retries any still held.
        // A release failure must not turn a committed default into a 500 (#4920): it is caught,
        // logged without the exception message (#4991) and reported; the default above stays saved.
        $released = 0;
        if ($newId !== null) {
            try {
                $released = $prepay->releaseHeldDebits($client->id);
            } catch (\Throwable $e) {
                Log::warning('[Prepay] Held debits release failed', [
                    'client_id' => $client->id,
                    'default_contract_id' => $newId,
                    'exception_class' => $e::class,
                ]);

                return redirect()->route('clients.show', $client)
                    ->with('error', 'Default contract saved, but releasing held debits failed; some may already be debited. Check the ledger before retrying.');
            }
        }
        if ($oldId === $newId && $released === 0) {
            return redirect()->route('clients.show', $client);
        }

        $message = $oldId === $newId ? '' : ($newId === null ? 'Default contract cleared.' : 'Default contract updated.');
        if ($released > 0) {
            $message = trim($message.' '.$released.' held time '.($released === 1 ? 'entry' : 'entries').' debited.');
        }

        return redirect()->route('clients.show', $client)
            ->with('success', $message);
    }
}
