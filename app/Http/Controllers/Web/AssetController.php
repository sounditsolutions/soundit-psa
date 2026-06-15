<?php

namespace App\Http\Controllers\Web;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetStoreRequest;
use App\Http\Requests\AssetUpdateRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssetService;
use App\Services\ControlD\ControlDAnalyticsClient;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDDeviceSyncService;
use App\Services\Level\LevelClient;
use App\Services\Level\LevelClientException;
use App\Services\Level\LevelSyncService;
use App\Services\Ninja\NinjaClient;
use App\Services\Ninja\NinjaClientException;
use App\Services\Ninja\NinjaSyncService;
use App\Services\Servosity\ServosityDeploymentService;
use App\Services\TicketService;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusClientException;
use App\Support\ControlDConfig;
use App\Support\ZorusConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssetController extends Controller
{
    public function __construct(
        private readonly AssetService $assetService,
    ) {}

    public function indexAll(Request $request)
    {
        $filters = [
            'search' => $request->query('search'),
            'client_id' => $request->query('client_id'),
            'asset_type' => $request->query('asset_type'),
            'status' => $request->query('status'),
            'rmm' => $request->query('rmm'),
            'user_assignment' => $request->query('user_assignment'),
            'show_inactive' => $request->boolean('show_inactive'),
            'show_deleted' => $request->boolean('show_deleted'),
            'sort' => $request->query('sort', 'hostname'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $assets = $this->assetService->getAssetList($filters);
        $clients = Client::active()->orderBy('name')->get(['id', 'name']);
        $assetTypes = Asset::active()->whereNotNull('asset_type')
            ->where('asset_type', '!=', '')
            ->distinct()->pluck('asset_type')->sort()->values();

        return view('assets.index-all', [
            'assets' => $assets,
            'filters' => $filters,
            'clients' => $clients,
            'assetTypes' => $assetTypes,
        ]);
    }

    public function index(Client $client)
    {
        $assets = $client->assets()
            ->active()
            ->orderBy('hostname')
            ->orderBy('name')
            ->paginate(50);

        return view('assets.index', [
            'client' => $client,
            'assets' => $assets,
        ]);
    }

    public function create(Request $request)
    {
        $clients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('assets.create', [
            'clients' => $clients,
            'selectedClientId' => $request->query('client_id'),
        ]);
    }

    public function store(AssetStoreRequest $request)
    {
        $asset = $this->assetService->createAsset($request->validated());

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset created successfully.');
    }

    public function show(int $asset, NinjaSyncService $ninjaSync)
    {
        $asset = Asset::withTrashed()->findOrFail($asset);
        $asset->load(['client', 'contracts', 'activeAlerts', 'users', 'tacticalAsset']);

        // On-access full enrichment for Ninja-linked assets
        $backupJobs = null;
        if ($asset->ninja_id) {
            try {
                $ninjaSync->syncDeviceDetail($asset);
            } catch (\Throwable) {
                // Non-fatal — page renders with existing data
            }
            $backupJobs = $ninjaSync->getBackupJobData($asset);
        }

        // Fetch Control D devices for unlinked assets (for manual linking dropdown)
        $controldDevices = null;
        if (! $asset->controld_device_id
            && $asset->client
            && $asset->client->controld_org_id
            && ControlDConfig::isConfigured()
        ) {
            try {
                $cdClient = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);
                $allDevices = $cdClient->getDevices($asset->client->controld_org_id);

                // Filter out devices already linked to any asset
                $linkedIds = Asset::whereNotNull('controld_device_id')->pluck('controld_device_id')->all();
                $controldDevices = array_values(array_filter($allDevices, fn ($d) => ! in_array($d['PK'] ?? '', $linkedIds)));
            } catch (ControlDClientException $e) {
                Log::debug("[AssetController] Could not fetch Control D devices: {$e->getMessage()}");
            }
        }

        // Fetch Zorus endpoints for unlinked assets (for manual linking dropdown)
        $zorusEndpoints = null;
        if (! $asset->zorus_endpoint_id
            && $asset->client
            && $asset->client->zorus_customer_id
            && ZorusConfig::isConfigured()
        ) {
            try {
                $zorusClient = new ZorusClient(['api_key' => ZorusConfig::get('api_key')]);

                // Fetch all endpoints (customer filter is unreliable)
                $allEndpoints = [];
                $page = 1;
                do {
                    $batch = $zorusClient->searchEndpoints([], $page, 500);
                    $allEndpoints = array_merge($allEndpoints, $batch);
                    $page++;
                } while (count($batch) === 500);

                // Filter to this client's customer + exclude already-linked endpoints
                $linkedIds = Asset::whereNotNull('zorus_endpoint_id')->pluck('zorus_endpoint_id')->all();
                $zorusEndpoints = array_values(array_filter($allEndpoints, function ($ep) use ($asset, $linkedIds) {
                    return ($ep['customerUuid'] ?? '') === $asset->client->zorus_customer_id
                        && ! in_array($ep['uuid'] ?? '', $linkedIds);
                }));
            } catch (ZorusClientException $e) {
                Log::debug("[AssetController] Could not fetch Zorus endpoints: {$e->getMessage()}");
            }
        }

        // Comet backup jobs
        $cometJobData = null;
        if ($asset->comet_device_id) {
            try {
                $cometJobService = new \App\Services\Comet\CometJobService(new \App\Services\Comet\CometClient);
                $cometJobData = $cometJobService->getRecentJobs($asset);
            } catch (\Exception $e) {
                // Silently fail — job data is optional
            }
        }

        \App\Support\RecentItems::track(auth()->id(), 'asset', $asset->id, $asset->hostname ?: $asset->name, route('assets.show', $asset));

        $lastUserPerson = $asset->resolveLastUserPerson();

        $clientPeople = $asset->client_id
            ? Person::where('client_id', $asset->client_id)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name'])
            : collect();

        return view('assets.show', [
            'asset' => $asset,
            'backupJobs' => $backupJobs,
            'cometJobData' => $cometJobData,
            'controldDevices' => $controldDevices,
            'zorusEndpoints' => $zorusEndpoints,
            'lastUserPerson' => $lastUserPerson,
            'clientPeople' => $clientPeople,
        ]);
    }

    public function tickets(Request $request, Asset $asset)
    {
        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'type' => $request->query('type'),
            'source' => $request->query('source'),
            'asset_id' => (string) $asset->id,
            'assignee_id' => $request->query('assignee_id', 'all'),
            'search' => $request->query('search'),
            'show_closed' => $request->boolean('show_closed'),
            'overdue' => $request->boolean('overdue'),
            'sort' => $request->query('sort', 'priority'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $ticketService = app(TicketService::class);
        $tickets = $ticketService->getTicketList($filters);
        $unassignedCount = Ticket::open()->whereHas('assets', fn ($q) => $q->where('assets.id', $asset->id))->whereNull('assignee_id')->count();

        // Count closed/resolved tickets even when not showing them so the view can
        // surface a "Show closed (N)" affordance and avoid a misleading empty state.
        $closedTicketCount = Ticket::closed()->whereHas('assets', fn ($q) => $q->where('assets.id', $asset->id))->count();

        // Load same data as show()
        $asset->load(['client', 'contracts', 'activeAlerts', 'users', 'tacticalAsset']);

        $lastUserPerson = $asset->resolveLastUserPerson();

        $clientPeople = $asset->client_id
            ? Person::where('client_id', $asset->client_id)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name'])
            : collect();

        return view('assets.show', [
            'asset' => $asset,
            'backupJobs' => null,
            'controldDevices' => collect(),
            'zorusEndpoints' => collect(),
            'lastUserPerson' => $lastUserPerson,
            'clientPeople' => $clientPeople,
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
            'closedTicketCount' => $closedTicketCount,
        ]);
    }

    public function edit(Asset $asset)
    {
        $clients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('assets.edit', [
            'asset' => $asset,
            'clients' => $clients,
        ]);
    }

    public function update(AssetUpdateRequest $request, Asset $asset)
    {
        $this->assetService->updateAsset($asset, $request->validated());

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset updated successfully.');
    }

    public function restore(int $id)
    {
        $asset = Asset::withTrashed()->findOrFail($id);
        $asset->restore();
        $asset->is_active = true;
        $asset->save();

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset restored successfully.');
    }

    public function destroy(Asset $asset)
    {
        try {
            $this->assetService->deleteAsset($asset);
        } catch (\RuntimeException $e) {
            return redirect()->route('assets.show', $asset)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('assets.index')
            ->with('success', 'Asset deleted successfully.');
    }

    public function refresh(Asset $asset, NinjaSyncService $ninjaSync, LevelSyncService $levelSync)
    {
        if (! $asset->ninja_id && ! $asset->level_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'This asset is not linked to any RMM.');
        }

        $sources = [];

        if ($asset->ninja_id) {
            try {
                $ninjaSync->syncDeviceDetail($asset);
                $sources[] = 'NinjaRMM';
            } catch (NinjaClientException $e) {
                return redirect()->route('assets.show', $asset)
                    ->with('error', 'Could not refresh from NinjaRMM: '.$e->getMessage());
            }
        }

        if ($asset->level_id) {
            try {
                $levelSync->syncDeviceDetail($asset);
                $sources[] = 'Level';
            } catch (LevelClientException $e) {
                return redirect()->route('assets.show', $asset)
                    ->with('error', 'Could not refresh from Level: '.$e->getMessage());
            }
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Device data refreshed from '.implode(' and ', $sources).'.');
    }

    public function linkControlD(Request $request, Asset $asset)
    {
        $request->validate([
            'controld_device_id' => 'required|string|max:20',
        ]);

        $deviceId = $request->input('controld_device_id');

        // Verify the asset's client has a Control D org mapping
        if (! $asset->client || ! $asset->client->controld_org_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'This client is not mapped to a Control D organization.');
        }

        // Verify the device exists in the client's sub-org
        try {
            $cdClient = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);
            $devices = $cdClient->getDevices($asset->client->controld_org_id);
        } catch (ControlDClientException $e) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'Could not verify device with Control D API.');
        }

        $device = collect($devices)->firstWhere('PK', $deviceId);

        if (! $device) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'Device not found in this client\'s Control D organization.');
        }

        // Link and sync the device data
        $syncService = new ControlDDeviceSyncService($cdClient);
        $syncService->updateAssetFromDevice($asset, $device);

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Linked to Control D device: '.($device['name'] ?? $deviceId));
    }

    public function unlinkControlD(Asset $asset)
    {
        if (! $asset->controld_device_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'This asset is not linked to Control D.');
        }

        $asset->update([
            'controld_device_id' => null,
            'controld_profile_name' => null,
            'controld_status' => null,
            'controld_agent_status' => null,
            'controld_agent_version' => null,
            'controld_last_seen_at' => null,
            'controld_synced_at' => null,
        ]);

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Control D link removed.');
    }

    public function controldActivity(Request $request, Asset $asset)
    {
        if (! $asset->controld_device_id) {
            return response()->json(['error' => 'Asset not linked to Control D'], 422);
        }

        if (! $asset->client || ! $asset->client->controld_org_id) {
            return response()->json(['error' => 'Client has no Control D organization'], 422);
        }

        if (! ControlDConfig::isAnalyticsConfigured()) {
            return response()->json(['error' => 'Control D analytics not configured'], 422);
        }

        $hours = min(max((int) $request->query('hours', 1), 1), 168);
        $endTime = now();
        $startTime = $endTime->copy()->subHours($hours);

        try {
            $client = new ControlDAnalyticsClient(
                ControlDConfig::get('api_key'),
                ControlDConfig::get('stats_endpoint'),
            );

            $queries = $client->getActivityLog(
                $asset->client->controld_org_id,
                $startTime->toIso8601ZuluString(),
                $endTime->toIso8601ZuluString(),
                $asset->controld_device_id,
            );
        } catch (\Throwable $e) {
            Log::warning("[AssetController] Control D activity query failed: {$e->getMessage()}");

            return response()->json(['error' => 'Failed to fetch DNS activity'], 500);
        }

        $results = array_map(fn ($q) => [
            'domain' => $q['question'] ?? null,
            'action' => match ($q['action'] ?? null) {
                1 => 'allowed',
                0 => 'blocked',
                -1 => 'nxdomain',
                default => 'unknown',
            },
            'trigger' => $q['trigger'] ?? null,
            'timestamp' => $q['timestamp'] ?? null,
            'type' => $q['rrType'] ?? null,
        ], array_slice($queries, 0, 100));

        return response()->json($results);
    }

    public function linkZorus(Request $request, Asset $asset)
    {
        $request->validate([
            'zorus_endpoint_id' => 'required|string|max:36',
        ]);

        $endpointId = $request->input('zorus_endpoint_id');

        // Verify the asset's client has a Zorus customer mapping
        if (! $asset->client || ! $asset->client->zorus_customer_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'This client is not mapped to a Zorus customer.');
        }

        // Verify the endpoint belongs to this client's Zorus customer (server-side ownership validation)
        try {
            $zorusClient = new ZorusClient(['api_key' => ZorusConfig::get('api_key')]);

            // Fetch all endpoints (customer filter is unreliable)
            $allEndpoints = [];
            $page = 1;
            do {
                $batch = $zorusClient->searchEndpoints([], $page, 500);
                $allEndpoints = array_merge($allEndpoints, $batch);
                $page++;
            } while (count($batch) === 500);
        } catch (ZorusClientException $e) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'Could not verify endpoint with Zorus API.');
        }

        $endpoint = collect($allEndpoints)->firstWhere('uuid', $endpointId);

        if (! $endpoint) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'Endpoint not found in Zorus.');
        }

        // Verify endpoint belongs to the client's customer
        if (($endpoint['customerUuid'] ?? '') !== $asset->client->zorus_customer_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'Endpoint does not belong to this client\'s Zorus customer.');
        }

        // Link and sync the endpoint data
        $syncService = new \App\Services\Zorus\ZorusDeviceSyncService($zorusClient);
        $syncService->updateAssetFromEndpoint($asset, $endpoint);

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Linked to Zorus endpoint: '.($endpoint['name'] ?? $endpointId));
    }

    public function unlinkZorus(Asset $asset)
    {
        if (! $asset->zorus_endpoint_id) {
            return redirect()->route('assets.show', $asset)
                ->with('error', 'This asset is not linked to Zorus.');
        }

        $asset->update([
            'zorus_endpoint_id' => null,
            'zorus_group_name' => null,
            'zorus_filtering_enabled' => null,
            'zorus_cybersight_enabled' => null,
            'zorus_agent_version' => null,
            'zorus_agent_state' => null,
            'zorus_last_seen_at' => null,
            'zorus_synced_at' => null,
        ]);

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Zorus link removed.');
    }

    public function toggleCometBackup(Request $request, Asset $asset)
    {
        $enabling = ! $asset->comet_backup_enabled;
        $asset->update(['comet_backup_enabled' => $enabling]);

        // Redirect back to whichever page the toggle was on (client or asset detail)
        $redirectTo = url()->previous(route('clients.show', $asset->client_id));

        // Push device-level flag to Tactical agent custom field
        $tacticalAsset = $asset->tacticalAsset;

        // If no Tactical link, try to find and link the agent by hostname
        if (! $tacticalAsset && \App\Support\TacticalConfig::isConfigured() && $asset->hostname) {
            try {
                $tacticalClient = new \App\Services\Tactical\TacticalClient;
                $agents = $tacticalClient->getAgents();
                foreach ($agents as $agent) {
                    if (strcasecmp($agent['hostname'] ?? '', $asset->hostname) === 0) {
                        // Found matching agent — create TacticalAsset and link
                        $ta = \App\Models\TacticalAsset::updateOrCreate(
                            ['agent_id' => $agent['agent_id']],
                            [
                                'hostname' => $agent['hostname'],
                                'asset_id' => $asset->id,
                            ]
                        );
                        $asset->update(['tactical_asset_id' => $ta->id]);
                        $tacticalAsset = $ta;
                        Log::info("[Comet] Auto-linked Tactical agent for {$asset->hostname}");
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("[Comet] Failed to auto-link Tactical agent for {$asset->hostname}: ".$e->getMessage());
            }
        }

        if ($tacticalAsset && \App\Support\TacticalConfig::isConfigured()) {
            try {
                $tacticalClient = new \App\Services\Tactical\TacticalClient;
                $tacticalClient->setAgentCustomField(
                    $tacticalAsset->agent_id,
                    \App\Support\CometConfig::TACTICAL_TOKEN_FIELD_ID,
                    $enabling ? 'yes' : ''
                );
            } catch (\Exception $e) {
                Log::warning("[Comet] Failed to update Tactical flag for {$asset->hostname}: ".$e->getMessage());
            }
        }

        $status = $asset->comet_backup_enabled ? 'enabled' : 'disabled';

        return redirect($redirectTo)->with('success', "Comet backup {$status} for {$asset->hostname}.");
    }

    public function toggleServosityBackup(Request $request, Asset $asset)
    {
        $redirectTo = url()->previous(route('clients.show', $asset->client_id));

        // Disabling — simple path
        if ($asset->servosity_backup_enabled) {
            try {
                $service = new ServosityDeploymentService;
                $service->disableBackup($asset);
            } catch (\Exception $e) {
                Log::warning("[Servosity] Failed to disable backup for {$asset->hostname}: ".$e->getMessage());
                $asset->update(['servosity_backup_enabled' => false]);
            }

            return redirect($redirectTo)->with('success', "Servosity backup disabled for {$asset->hostname}.");
        }

        // Enabling — requires client mapping and Tactical agent
        if (! $asset->client?->servosity_company_id) {
            return redirect($redirectTo)->with('error', 'Client does not have a Servosity company mapping.');
        }

        // Auto-link Tactical agent by hostname if needed
        $tacticalAsset = $asset->tacticalAsset;
        if (! $tacticalAsset && \App\Support\TacticalConfig::isConfigured() && $asset->hostname) {
            try {
                $tacticalClient = new \App\Services\Tactical\TacticalClient;
                $agents = $tacticalClient->getAgents();
                foreach ($agents as $agent) {
                    if (strcasecmp($agent['hostname'] ?? '', $asset->hostname) === 0) {
                        $ta = \App\Models\TacticalAsset::updateOrCreate(
                            ['agent_id' => $agent['agent_id']],
                            ['hostname' => $agent['hostname'], 'asset_id' => $asset->id]
                        );
                        $asset->update(['tactical_asset_id' => $ta->id]);
                        $asset->refresh();
                        Log::info("[Servosity] Auto-linked Tactical agent for {$asset->hostname}");
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('[Servosity] Failed to auto-link Tactical agent: '.$e->getMessage());
            }
        }

        if (! $asset->tacticalAsset) {
            return redirect($redirectTo)->with('error', "No Tactical RMM agent found for {$asset->hostname}. Link the device first.");
        }

        try {
            $service = new ServosityDeploymentService;
            $service->enableBackup($asset);

            // Trigger Tactical deploy script immediately (fire-and-forget)
            try {
                $tactical = new \App\Services\Tactical\TacticalClient;
                $tactical->runScriptAsync(
                    $asset->tacticalAsset->agent_id,
                    218,  // Deploy: Servosity Backup
                    [
                        '-ServosityOneUrl', '{{agent.ServosityOneUrl}}',
                        '-ServosityScreenConnectUrl', '{{agent.ServosityScreenConnectUrl}}',
                        '-ServosityCredUser', '{{agent.ServosityCredUser}}',
                        '-ServosityCredPass', '{{agent.ServosityCredPass}}',
                    ],
                    600,
                );
            } catch (\Exception $e) {
                Log::warning('[Servosity] Failed to trigger immediate deploy: '.$e->getMessage());
            }

            // Dispatch retry job: checks every 30s for 30 min until provisioned
            \App\Jobs\ServosityProvisionAsset::dispatch($asset->id)
                ->delay(now()->addSeconds(60));  // First check 60s after deploy starts

            return redirect($redirectTo)->with('success', "Servosity backup enabled for {$asset->hostname}. Installing now — DR account will be provisioned automatically.");
        } catch (\Exception $e) {
            Log::error("[Servosity] Failed to enable backup for {$asset->hostname}: ".$e->getMessage());

            return redirect($redirectTo)->with('error', 'Failed to enable Servosity backup: '.$e->getMessage());
        }
    }

    public function addUser(Request $request, Asset $asset)
    {
        $request->validate([
            'person_id' => ['required', 'exists:people,id'],
        ]);

        $personId = $request->input('person_id');
        $person = Person::where('id', $personId)->where('client_id', $asset->client_id)->firstOrFail();

        if ($asset->users()->where('person_id', $personId)->exists()) {
            return redirect()->route('assets.show', $asset)
                ->with('warning', "{$person->full_name} is already linked to this device.");
        }

        $asset->users()->attach($personId, [
            'is_primary' => false,
            'assignment_source' => 'manual',
            'last_seen_at' => null,
        ]);

        return redirect()->route('assets.show', $asset)
            ->with('success', "{$person->full_name} linked to this device.");
    }

    public function removeUser(Asset $asset, Person $person)
    {
        $asset->users()->detach($person->id);

        return redirect()->route('assets.show', $asset)
            ->with('success', "{$person->full_name} removed from this device.");
    }

    public function setPrimaryUser(Asset $asset, Person $person)
    {
        DB::table('asset_person')
            ->where('asset_id', $asset->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);

        DB::table('asset_person')
            ->where('asset_id', $asset->id)
            ->where('person_id', $person->id)
            ->update(['is_primary' => true]);

        return redirect()->route('assets.show', $asset)
            ->with('success', "{$person->full_name} set as primary user.");
    }

    public function runTacticalScript(Request $request, Asset $asset)
    {
        $request->validate([
            'script_id' => ['required', 'exists:tactical_scripts,id'],
            'args' => ['nullable', 'string', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:10', 'max:600'],
        ]);

        $asset->load('tacticalAsset');

        if (! $asset->tacticalAsset || $asset->tacticalAsset->status !== 'online') {
            return response()->json(['error' => 'Device is not online or has no Tactical agent.'], 422);
        }

        $script = \App\Models\TacticalScript::findOrFail($request->input('script_id'));
        $args = $request->input('args') ? array_map('trim', explode(' ', $request->input('args'))) : null;
        $timeout = (int) $request->input('timeout');

        try {
            $client = new \App\Services\Tactical\TacticalClient;
            $result = $client->runScript(
                $asset->tacticalAsset->agent_id,
                $script->tactical_script_id,
                $args,
                $timeout,
            );

            Log::info('[Tactical] Script executed', [
                'asset_id' => $asset->id,
                'script' => $script->name,
                'agent_id' => $asset->tacticalAsset->agent_id,
            ]);

            return response()->json([
                'success' => true,
                'script_name' => $script->name,
                'stdout' => $result['stdout'] ?? $result['output'] ?? '',
                'stderr' => $result['stderr'] ?? '',
                'retcode' => $result['retcode'] ?? $result['return_code'] ?? null,
                'execution_time' => $result['execution_time'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Script execution failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function quickLook(Asset $asset)
    {
        $cacheKey = "asset_quick_look:{$asset->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        // Live RMM fetch with short timeout (updates DB as side effect)
        $this->fetchLiveRmmData($asset);

        // Refresh model from DB after live update
        $asset->refresh();

        $contract = $asset->contracts()->first();
        $statusLabel = $asset->statusBadge;
        $statusColor = match ($statusLabel) {
            'Online' => '#198754',
            'Offline' => '#dc3545',
            default => '#6c757d',
        };

        // Compute uptime from last_boot_at
        $uptime = null;
        if ($asset->last_boot_at) {
            $diff = $asset->last_boot_at->diff(now());
            $parts = [];
            if ($diff->days > 0) {
                $parts[] = $diff->days.'d';
            }
            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }
            if (empty($parts)) {
                $parts[] = $diff->i.'m';
            }
            $uptime = implode(' ', $parts);
        }

        // Determine RMM source and URL
        $rmm = null;
        $rmmUrl = null;
        if ($asset->ninja_id) {
            $rmm = 'ninja';
            $rmmUrl = $asset->ninja_url;
        } elseif ($asset->level_id) {
            $rmm = 'level';
            $rmmUrl = $asset->level_url;
        }

        $result = [
            'name' => $asset->name,
            'hostname' => $asset->hostname,
            'asset_type' => $asset->asset_type,
            'os' => $asset->os,
            'serial' => $asset->serial_number,
            'status' => $statusLabel,
            'status_color' => $statusColor,
            'last_seen' => $asset->last_seen_at?->diffForHumans(),
            'uptime' => $uptime,
            'needs_reboot' => $asset->needs_reboot,
            'contract' => $contract
                ? ($contract->contract_number.' - '.$contract->name)
                : null,
            'warranty_start' => $asset->warranty_start?->format('Y-m-d'),
            'warranty_end' => $asset->warranty_end?->format('Y-m-d'),
            'rmm' => $rmm,
            'rmm_url' => $rmmUrl,
        ];

        Cache::put($cacheKey, $result, 60);

        return response()->json($result);
    }

    /**
     * Fetch live status from RMM with short timeout. Updates DB as side effect.
     */
    private function fetchLiveRmmData(Asset $asset): void
    {
        if ($asset->ninja_id) {
            try {
                $device = app(NinjaClient::class)->getDevice($asset->ninja_id, timeout: 5);
                $lastContact = isset($device['lastContact'])
                    ? Carbon::createFromTimestamp($device['lastContact'])
                    : null;

                $asset->update([
                    'last_seen_at' => $lastContact ?? $asset->last_seen_at,
                    'rmm_online' => $lastContact && $lastContact->diffInMinutes(now()) <= 10,
                ]);
            } catch (\Throwable $e) {
                Log::debug("[AssetController] Quick-look Ninja fetch failed: {$e->getMessage()}");
            }
        } elseif ($asset->level_id) {
            try {
                $device = app(LevelClient::class)->getDevice($asset->level_id);

                $asset->update([
                    'rmm_online' => ! empty($device['online']),
                    'last_seen_at' => ! empty($device['online']) ? now() : $asset->last_seen_at,
                    'last_boot_at' => ! empty($device['last_reboot_time'])
                        ? Carbon::parse($device['last_reboot_time'])
                        : $asset->last_boot_at,
                ]);
            } catch (\Throwable $e) {
                Log::debug("[AssetController] Quick-look Level fetch failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * AJAX endpoint: fetch live device sub-resource data from RMM.
     */
    public function deviceData(Asset $asset, string $section)
    {
        $allowedSections = ['network', 'storage', 'software', 'patches'];
        if (! in_array($section, $allowedSections)) {
            return response()->json(['error' => 'Invalid section'], 422);
        }

        if ($asset->ninja_id) {
            return response()->json($this->fetchNinjaDeviceData($asset, $section));
        }

        if ($asset->level_id) {
            return response()->json($this->getLevelFallbackData($asset, $section));
        }

        return response()->json(['error' => 'Asset not linked to any RMM'], 422);
    }

    private function fetchNinjaDeviceData(Asset $asset, string $section): array
    {
        $ninjaId = $asset->ninja_id;
        $ninja = app(NinjaClient::class);

        try {
            return match ($section) {
                'network' => ['interfaces' => collect($ninja->get("/v2/device/{$ninjaId}/network-interfaces"))
                    ->unique('interfaceIndex')->values()->all()],
                'storage' => [
                    'disks' => $ninja->get("/v2/device/{$ninjaId}/disks"),
                    'volumes' => $ninja->get("/v2/device/{$ninjaId}/volumes"),
                ],
                'software' => ['software' => $ninja->get("/v2/device/{$ninjaId}/software")],
                'patches' => ['patches' => $ninja->get("/v2/device/{$ninjaId}/os-patches")],
            };
        } catch (\Throwable $e) {
            Log::debug("[AssetController] Device data fetch failed: {$e->getMessage()}");

            return ['error' => 'Could not fetch data from RMM. Try again in a moment.'];
        }
    }

    private function getLevelFallbackData(Asset $asset, string $section): array
    {
        return match ($section) {
            'network' => ['level_fallback' => true, 'ip_address' => $asset->ip_address],
            'storage' => ['level_fallback' => true, 'disk_summary' => $asset->disk_summary],
            'software' => ['level_fallback' => true, 'message' => 'Software inventory not available for Level devices.'],
            'patches' => ['level_fallback' => true, 'message' => 'Patch data not available for Level devices.'],
        };
    }
}
