<?php

namespace App\Services;

use App\Models\Client;
use App\Models\License;
use App\Models\Setting;
use App\Services\Cipp\CippClient;
use App\Services\ControlD\ControlDClient;
use App\Services\Huntress\HuntressClient;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use App\Services\Servosity\ServosityClient;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeSyncService;
use App\Services\Zorus\ZorusClient;
use App\Support\CippConfig;
use App\Support\ControlDConfig;
use App\Support\HuntressConfig;
use App\Support\LevelConfig;
use App\Support\MeshConfig;
use App\Support\ServosityConfig;
use App\Support\StripeConfig;
use App\Support\CometConfig;
use App\Support\ZorusConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientIntegrationService
{
    /** Valid vendor keys — used for route constraints and validation. */
    public const VENDORS = [
        'ninja', 'mesh', 'cipp', 'huntress', 'level',
        'controld', 'zorus', 'servosity', 'stripe', 'qbo', 'comet',
    ];

    /**
     * Build integration status data for a client. DB-only — never calls vendor APIs.
     *
     * @return array<string, array{label: string, icon: string, mapped: bool, entity_id: mixed, entity_display: string, license_count: int, last_synced: ?string}>
     */
    public function buildIntegrationsData(Client $client): array
    {
        $registry = $this->registry();

        // Single query: license counts + last sync per vendor
        $licenseRows = DB::table('licenses')
            ->join('license_types', 'licenses.license_type_id', '=', 'license_types.id')
            ->where('licenses.client_id', $client->id)
            ->where('licenses.status', 'active')
            ->groupBy('license_types.vendor')
            ->selectRaw('license_types.vendor, SUM(licenses.quantity) as total_qty, MAX(licenses.synced_at) as last_synced')
            ->get();

        $licenseData = [];
        foreach ($licenseRows as $row) {
            $licenseData[$row->vendor] = [
                'total_qty' => (int) $row->total_qty,
                'last_synced' => $row->last_synced,
            ];
        }

        $integrations = [];

        foreach ($registry as $vendor => $config) {
            try {
                if (! ($config['configCheck'])()){
                    continue;
                }

                $column = $config['column'];
                $entityId = $client->{$column};
                $mapped = ! empty($entityId);

                // Resolve entity display name
                $entityDisplay = '';
                if ($mapped) {
                    if ($vendor === 'qbo' && $client->qbo_display_name) {
                        $entityDisplay = $client->qbo_display_name;
                    } else {
                        $entityDisplay = (string) $entityId;
                    }
                }

                // Resolve license count — check the vendor's license vendor string
                $licenseVendor = $config['licenseVendor'];
                $licenseCount = 0;
                $lastSynced = null;

                if ($licenseVendor) {
                    $data = $licenseData[$licenseVendor] ?? null;
                    if ($data) {
                        $licenseCount = $data['total_qty'];
                        $lastSynced = $data['last_synced'];
                    }
                }

                $integrations[$vendor] = [
                    'label' => $config['label'],
                    'icon' => $config['icon'],
                    'mapped' => $mapped,
                    'entity_id' => $entityId,
                    'entity_display' => $entityDisplay,
                    'license_count' => $licenseCount,
                    'last_synced' => $lastSynced,
                ];
            } catch (\Throwable $e) {
                Log::warning('[ClientIntegration] Failed to build data for vendor', [
                    'vendor' => $vendor,
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $integrations;
    }

    /**
     * Fetch available entities from a vendor API. Cached for 60 seconds.
     *
     * @return array<int, array{id: mixed, name: string}>
     */
    public function fetchEntities(Client $client, string $vendor): array
    {
        $config = $this->getVendorConfig($vendor);

        return Cache::remember("integration_entities_{$vendor}", 60, function () use ($client, $vendor, $config) {
            $entities = ($config['fetchEntities'])();

            // Normalize to [{id, name}]
            $normalized = array_map(fn ($e) => [
                'id' => ($config['entityId'])($e),
                'name' => ($config['entityName'])($e),
            ], $entities);

            // Filter out entities already mapped to other clients
            $column = $config['column'];
            $mappedIds = Client::whereNotNull($column)
                ->where('id', '!=', $client->id)
                ->pluck($column)
                ->all();

            $normalized = array_filter($normalized, function ($e) use ($mappedIds, $config) {
                $castId = $this->castEntityId($e['id'], $config['cast']);

                return ! in_array($castId, $mappedIds);
            });

            // Sort by name
            usort($normalized, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return array_values($normalized);
        });
    }

    /**
     * Link a client to a vendor entity.
     */
    public function linkEntity(Client $client, string $vendor, mixed $entityId, ?string $displayName = null): void
    {
        $config = $this->getVendorConfig($vendor);
        $column = $config['column'];
        $castId = $this->castEntityId($entityId, $config['cast']);

        DB::transaction(function () use ($client, $vendor, $config, $column, $castId, $displayName) {
            // Atomic duplicate check
            $taken = Client::where($column, $castId)
                ->where('id', '!=', $client->id)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                abort(409, "This {$config['label']} entity is already mapped to another client.");
            }

            $updates = [$column => $castId];

            if ($vendor === 'qbo' && $displayName) {
                $updates['qbo_display_name'] = $displayName;
            }

            $client->update($updates);
        });

        // Clear entity cache so the linked entity is excluded next time
        Cache::forget("integration_entities_{$vendor}");

        Log::info('[ClientIntegration] Linked', [
            'user' => auth()->id(),
            'client' => $client->id,
            'vendor' => $vendor,
            'entity_id' => $castId,
        ]);
    }

    /**
     * Unlink a client from a vendor and deactivate associated licenses.
     */
    public function unlinkEntity(Client $client, string $vendor): void
    {
        $config = $this->getVendorConfig($vendor);
        $column = $config['column'];

        DB::transaction(function () use ($client, $vendor, $config, $column) {
            $updates = [$column => null];

            if ($vendor === 'qbo') {
                $updates['qbo_display_name'] = null;
            }

            $client->update($updates);

            // Deactivate licenses if this vendor syncs them
            if ($config['licenseVendor']) {
                License::deactivateForClients(collect([$client->id]), $config['licenseVendor']);
            }
        });

        // Clear entity cache so the unlinked entity appears again
        Cache::forget("integration_entities_{$vendor}");

        Log::info('[ClientIntegration] Unlinked', [
            'user' => auth()->id(),
            'client' => $client->id,
            'vendor' => $vendor,
        ]);
    }

    /**
     * Get a vendor's config or abort 404.
     */
    private function getVendorConfig(string $vendor): array
    {
        if (! in_array($vendor, self::VENDORS)) {
            abort(404, "Unknown integration vendor: {$vendor}");
        }

        $registry = $this->registry();

        if (! isset($registry[$vendor])) {
            abort(404, "Integration vendor not registered: {$vendor}");
        }

        return $registry[$vendor];
    }

    /**
     * Cast an entity ID to the correct type for the vendor column.
     */
    private function castEntityId(mixed $id, string $cast): int|string
    {
        return $cast === 'int' ? (int) $id : (string) $id;
    }

    /**
     * Integration registry — config for all 10 vendor integrations.
     *
     * @return array<string, array{label: string, icon: string, column: string, cast: string, licenseVendor: ?string, configCheck: callable, fetchEntities: callable, entityId: callable, entityName: callable}>
     */
    private function registry(): array
    {
        return [
            'ninja' => [
                'label' => 'NinjaRMM',
                'icon' => 'bi-hdd-network',
                'column' => 'ninja_org_id',
                'cast' => 'int',
                'licenseVendor' => 'ninjaone',
                'configCheck' => fn () => (bool) Setting::getValue('ninja_client_id'),
                'fetchEntities' => fn () => app(NinjaClient::class)->getOrganizations(),
                'entityId' => fn ($e) => $e['id'] ?? $e['nodeId'] ?? null,
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'mesh' => [
                'label' => 'Mesh (Email Security)',
                'icon' => 'bi-envelope-check',
                'column' => 'mesh_customer_id',
                'cast' => 'string',
                'licenseVendor' => 'mesh',
                'configCheck' => fn () => MeshConfig::isConfigured(),
                'fetchEntities' => fn () => app(MeshClient::class)->getCustomers(200),
                'entityId' => fn ($e) => $e['uuid'] ?? '',
                'entityName' => fn ($e) => $e['company_name'] ?? '',
            ],
            'cipp' => [
                'label' => 'CIPP (M365)',
                'icon' => 'bi-microsoft',
                'column' => 'cipp_tenant_domain',
                'cast' => 'string',
                'licenseVendor' => 'cipp_m365',
                'configCheck' => fn () => CippConfig::isConfigured(),
                'fetchEntities' => fn () => app(CippClient::class)->listTenants(),
                'entityId' => fn ($e) => $e['defaultDomainName'] ?? '',
                'entityName' => fn ($e) => $e['displayName'] ?? ($e['defaultDomainName'] ?? ''),
            ],
            'huntress' => [
                'label' => 'Huntress',
                'icon' => 'bi-shield-check',
                'column' => 'huntress_organization_id',
                'cast' => 'int',
                'licenseVendor' => 'huntress',
                'configCheck' => fn () => HuntressConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new HuntressClient([
                        'api_key' => HuntressConfig::get('api_key'),
                        'api_secret' => HuntressConfig::get('api_secret'),
                    ]);

                    return $client->getOrganizations(['id', 'name']);
                },
                'entityId' => fn ($e) => $e['id'] ?? null,
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'level' => [
                'label' => 'Level RMM',
                'icon' => 'bi-pc-display',
                'column' => 'level_group_id',
                'cast' => 'string',
                'licenseVendor' => null,
                'configCheck' => fn () => LevelConfig::isConfigured(),
                'fetchEntities' => fn () => app(LevelClient::class)->getGroups(),
                'entityId' => fn ($e) => $e['id'] ?? '',
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'controld' => [
                'label' => 'Control D',
                'icon' => 'bi-globe',
                'column' => 'controld_org_id',
                'cast' => 'string',
                'licenseVendor' => 'controld',
                'configCheck' => fn () => ControlDConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new ControlDClient([
                        'api_key' => ControlDConfig::get('api_key'),
                    ]);

                    return $client->getSubOrganizations();
                },
                'entityId' => fn ($e) => $e['PK'] ?? '',
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'zorus' => [
                'label' => 'Zorus',
                'icon' => 'bi-shield-lock',
                'column' => 'zorus_customer_id',
                'cast' => 'string',
                'licenseVendor' => 'zorus',
                'configCheck' => fn () => ZorusConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new ZorusClient([
                        'api_key' => ZorusConfig::get('api_key'),
                    ]);

                    $customers = [];
                    $page = 1;
                    do {
                        $batch = $client->searchCustomers([], $page, 100);
                        $customers = array_merge($customers, $batch);
                        $page++;
                    } while (count($batch) === 100);

                    return $customers;
                },
                'entityId' => fn ($e) => $e['uuid'] ?? '',
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'servosity' => [
                'label' => 'Servosity',
                'icon' => 'bi-cloud-arrow-up',
                'column' => 'servosity_company_id',
                'cast' => 'int',
                'licenseVendor' => 'servosity',
                'configCheck' => fn () => ServosityConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new ServosityClient([
                        'api_token' => ServosityConfig::get('api_token'),
                        'base_url' => ServosityConfig::get('base_url'),
                    ]);

                    return $client->getCompanies();
                },
                'entityId' => fn ($e) => $e['id'] ?? null,
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'stripe' => [
                'label' => 'Stripe',
                'icon' => 'bi-credit-card',
                'column' => 'stripe_customer_id',
                'cast' => 'string',
                'licenseVendor' => null,
                'configCheck' => fn () => StripeConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);
                    $service = new StripeSyncService($client);

                    return $service->fetchStripeCustomers();
                },
                'entityId' => fn ($e) => $e['id'] ?? '',
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
            'qbo' => [
                'label' => 'QuickBooks Online',
                'icon' => 'bi-receipt',
                'column' => 'qbo_customer_id',
                'cast' => 'string',
                'licenseVendor' => null,
                'configCheck' => fn () => app(QboClient::class)->isConnected(),
                'fetchEntities' => function () {
                    $service = app(QboSyncService::class);

                    return $service->fetchQboCustomers();
                },
                'entityId' => fn ($e) => $e['Id'] ?? '',
                'entityName' => fn ($e) => $e['DisplayName'] ?? '',
            ],
            'comet' => [
                'label' => 'Comet Backup',
                'icon' => 'bi-cloud-arrow-up',
                'column' => 'comet_group_id',
                'cast' => 'string',
                'licenseVendor' => 'comet',
                'configCheck' => fn () => CometConfig::isConfigured(),
                'fetchEntities' => function () {
                    $client = new \App\Services\Comet\CometClient();
                    $groups = $client->getUserGroups();

                    return array_map(fn ($id, $group) => [
                        'id' => $id,
                        'name' => $group->Name ?? $id,
                    ], array_keys($groups), array_values($groups));
                },
                'entityId' => fn ($e) => $e['id'] ?? '',
                'entityName' => fn ($e) => $e['name'] ?? '',
            ],
        ];
    }
}
