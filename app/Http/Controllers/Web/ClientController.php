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
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Sku;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Services\AssetService;
use App\Services\ClientIntegrationService;
use App\Services\ClientService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly ActivityStreamService $activityStream,
        private readonly ClientIntegrationService $integrationService,
    ) {}

    public function index(Request $request)
    {
        $clients = Client::active()
            ->search($request->query('search'))
            ->with('reseller:id,name')
            ->withCount('people')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'search' => $request->query('search'),
        ]);
    }

    public function create()
    {
        return view('clients.create', [
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'resellerCandidates' => Client::active()->orderBy('name')->get(['id', 'name']),
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
        ]);
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
            'licenseClients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'licenseTypes' => LicenseType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'licenseVendors' => LicenseType::distinct()->whereNotNull('vendor')->pluck('vendor')->sort()->values(),
            'licenseClientId' => (string) $client->id,
            'licenseTypeId' => $licenseTypeId,
            'licenseVendor' => $vendor,
            'licenseWasteOnly' => $wasteOnly,
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
            'assetClients' => Client::active()->orderBy('name')->get(['id', 'name']),
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
            'contractClients' => Client::active()->orderBy('name')->get(['id', 'name']),
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

        $resellerCandidates = Client::active()
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
        if ($client->portal_install_token) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'This client already has an install link. Use Rotate to replace it.');
        }

        if (empty($client->availableRmms())) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'Map this client to an RMM (Ninja, Level, or Tactical) before generating an install link.');
        }

        $available = $client->availableRmms();
        $client->update([
            'portal_install_token' => Str::random(32),
            'portal_primary_rmm' => count($available) === 1 ? $available[0] : $client->portal_primary_rmm,
        ]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link generated.');
    }

    public function rotateInstallLink(Client $client): RedirectResponse
    {
        if (! $client->portal_install_token) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'No install link to rotate.');
        }

        $client->update(['portal_install_token' => Str::random(32)]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link rotated. The previous URL is no longer valid.');
    }

    public function disableInstallLink(Client $client): RedirectResponse
    {
        $client->update([
            'portal_install_token' => null,
            'portal_primary_rmm' => null,
        ]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link disabled.');
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
}
