<?php

namespace App\Services\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\TacticalConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class TacticalReadOnlyToolset
{
    private const LEGACY_CLIENT_TOOL_NAMES = [
        'tactical_get_device',
        'tactical_get_device_checks',
        'tactical_get_device_network',
        'tactical_get_device_software',
        'tactical_get_device_services',
        'tactical_get_device_disks',
    ];

    private const CLIENT_TOOL_NAMES = [
        ...self::LEGACY_CLIENT_TOOL_NAMES,
        'tactical_list_devices',
        'tactical_get_device_patches',
        'tactical_get_device_tasks',
        'tactical_get_endpoint_insight',
        'tactical_list_recent_actions',
        'tactical_diagnose_device',
    ];

    private const GENERAL_TOOL_NAMES = [
        'tactical_list_scripts',
        'tactical_get_script',
        'tactical_list_clients_sites',
        'tactical_list_policies',
        'tactical_list_url_actions',
        'tactical_list_alert_templates',
        'tactical_get_core_settings',
        'tactical_health_check',
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_values(array_merge(self::clientDefinitions(), self::generalDefinitions()));
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return array_values(array_filter(
            self::allDefinitions(),
            fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), self::CLIENT_TOOL_NAMES, true),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public static function generalDefinitions(): array
    {
        return array_values(array_filter(
            self::allDefinitions(),
            fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), self::GENERAL_TOOL_NAMES, true),
        ));
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, array_merge(self::CLIENT_TOOL_NAMES, self::GENERAL_TOOL_NAMES), true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public function execute(string $toolName, array $input, ?int $clientId): array
    {
        if (! TacticalConfig::isConfigured()) {
            return ['error' => 'Tactical RMM is not configured'];
        }

        if (self::requiresClient($toolName) && $clientId === null) {
            return ['error' => 'client_id is required for '.$toolName.'.'];
        }

        return match ($toolName) {
            'tactical_list_devices' => $this->listDevices($input, (int) $clientId),
            'tactical_get_device' => $this->getDevice($input, $clientId),
            'tactical_get_device_checks' => $this->getDeviceChecks($input, $clientId),
            'tactical_get_device_network' => $this->getDeviceNetwork($input, $clientId),
            'tactical_get_device_software' => $this->getDeviceSoftware($input, $clientId),
            'tactical_get_device_services' => $this->getDeviceServices($input, $clientId),
            'tactical_get_device_disks' => $this->getDeviceDisks($input, $clientId),
            'tactical_get_device_patches' => $this->getDevicePatches($input, (int) $clientId),
            'tactical_get_device_tasks' => $this->getDeviceTasks($input, (int) $clientId),
            'tactical_get_endpoint_insight' => $this->getEndpointInsight($input, (int) $clientId),
            'tactical_list_scripts' => $this->listScripts($input),
            'tactical_get_script' => $this->getScript($input),
            'tactical_list_recent_actions' => $this->listRecentActions($input, (int) $clientId),
            'tactical_list_clients_sites' => $this->listClientsSites($input),
            'tactical_list_policies' => $this->listPolicies($input),
            'tactical_list_url_actions' => $this->listUrlActions($input),
            'tactical_list_alert_templates' => $this->listAlertTemplates($input),
            'tactical_get_core_settings' => $this->getCoreSettings(),
            'tactical_health_check' => $this->healthCheck(),
            'tactical_diagnose_device' => $this->diagnoseDevice($input, (int) $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private static function allDefinitions(): array
    {
        $legacy = array_values(array_filter(
            TriageToolDefinitions::tacticalTools(),
            fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), self::LEGACY_CLIENT_TOOL_NAMES, true),
        ));

        return array_merge($legacy, self::phaseOneDefinitions());
    }

    /** @return array<int, array<string, mixed>> */
    private static function phaseOneDefinitions(): array
    {
        return [
            [
                'name' => 'tactical_list_devices',
                'description' => 'List Tactical-linked devices for a PSA client from the local snapshot. No live Tactical call is made.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Optional hostname or asset search term.'],
                        'status' => ['type' => 'string', 'description' => 'Optional Tactical status filter such as online, offline, or overdue.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max devices to return (default 25, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_get_device_patches',
                'description' => 'Read Windows update/patch status for a Tactical-linked device resolved by hostname within the PSA client.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max patch rows to return (default 50, max 100).'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_device_tasks',
                'description' => 'Read task history/status for a Tactical-linked device resolved by hostname within the PSA client.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max task rows to return (default 25, max 50).'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_get_endpoint_insight',
                'description' => 'Read endpoint insight for a Tactical-linked device: snapshot health, bounded live status/checks, alerts, patches, and recent actions.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname.'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
            [
                'name' => 'tactical_list_scripts',
                'description' => 'List visible Tactical script metadata from the local script catalog. Script bodies are not returned.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Optional name/category search term.'],
                        'category' => ['type' => 'string', 'description' => 'Optional script category filter.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max scripts to return (default 25, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_get_script',
                'description' => 'Get visible Tactical script metadata by PSA catalog row ID or Tactical script ID. Script bodies are not returned.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'script_id' => ['type' => 'integer', 'description' => 'PSA tactical_scripts.id or Tactical script ID.'],
                    ],
                    'required' => ['script_id'],
                ],
            ],
            [
                'name' => 'tactical_list_recent_actions',
                'description' => 'List recent local Tactical action audit rows for a PSA client or one resolved device. Read-only audit history.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Optional device hostname to narrow the action history.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max audit rows to return (default 10, max 50).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_list_clients_sites',
                'description' => 'List local PSA client to Tactical site mappings. Does not create or modify Tactical clients.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max mappings to return (default 50, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_list_policies',
                'description' => 'Read Tactical automation policy names and IDs for operator selection. No policy changes are made.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max policies to return (default 50, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_list_url_actions',
                'description' => 'Read Tactical URL action metadata. Headers and bodies are redacted from the response.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max URL actions to return (default 50, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_list_alert_templates',
                'description' => 'Read Tactical alert template metadata. No alert settings are changed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max alert templates to return (default 50, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'tactical_get_core_settings',
                'description' => 'Read Tactical core settings with secret-like fields removed from the response.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'tactical_health_check',
                'description' => 'Return whether Tactical RMM is configured and reachable. Does not include secrets or device data.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'tactical_diagnose_device',
                'description' => 'Compose read-only Tactical diagnostics for one client-scoped device: endpoint insight, patches, tasks, and recent local actions. Does not run scripts or commands.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname.'],
                    ],
                    'required' => ['hostname'],
                ],
            ],
        ];
    }

    /**
     * @return array{agent_id: string, tactical_asset: TacticalAsset, asset: Asset}|null
     */
    private function resolveAgent(string $hostname, int $clientId): ?array
    {
        $tacticalAsset = TacticalAsset::with('asset')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->whereHas('asset', fn (Builder $query) => $query->where('client_id', $clientId))
            ->first();

        if (! $tacticalAsset || ! $tacticalAsset->asset) {
            return null;
        }

        return [
            'agent_id' => (string) $tacticalAsset->agent_id,
            'tactical_asset' => $tacticalAsset,
            'asset' => $tacticalAsset->asset,
        ];
    }

    /** @return array{error: string}|array{agent_id: string, tactical_asset: TacticalAsset, asset: Asset} */
    private function resolvedFromInput(array $input, int $clientId): array
    {
        $hostname = trim((string) ($input['hostname'] ?? ''));
        if ($hostname === '') {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveAgent($hostname, $clientId);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        return $resolved;
    }

    private function listDevices(array $input, int $clientId): array
    {
        $limit = $this->limit($input, default: 25, max: 100);
        $queryText = trim((string) ($input['query'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));

        $query = TacticalAsset::with('asset')
            ->whereHas('asset', fn (Builder $assetQuery) => $assetQuery->where('client_id', $clientId));

        if ($queryText !== '') {
            $like = '%'.strtolower($queryText).'%';
            $query->where(function (Builder $deviceQuery) use ($like) {
                $deviceQuery
                    ->whereRaw('LOWER(hostname) LIKE ?', [$like])
                    ->orWhereHas('asset', fn (Builder $assetQuery) => $assetQuery->whereRaw('LOWER(hostname) LIKE ?', [$like]));
            });
        }

        if ($status !== '') {
            $query->whereRaw('LOWER(status) = ?', [mb_strtolower($status)]);
        }

        $devices = $query
            ->orderByRaw('LOWER(COALESCE(hostname, ""))')
            ->limit($limit)
            ->get()
            ->map(fn (TacticalAsset $asset): array => $this->mapDeviceSnapshot($asset))
            ->values()
            ->all();

        return [
            'count' => count($devices),
            'devices' => $devices,
        ];
    }

    private function getDevicePatches(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $limit = $this->limit($input, default: 50, max: 100);

        try {
            $patches = app(TacticalClient::class)->getPatches($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical patches query failed', [
                'hostname' => $input['hostname'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $mapped = array_map(
            fn (array $patch): array => $this->redactSensitiveKeys($patch),
            array_slice($this->listPayload($patches), 0, $limit),
        );

        return [
            'device' => $this->mapDeviceSnapshot($resolved['tactical_asset']),
            'count' => count($mapped),
            'patches' => $mapped,
        ];
    }

    private function getDeviceTasks(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $limit = $this->limit($input, default: 25, max: 50);

        try {
            $tasks = app(TacticalClient::class)->getAgentTasks($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical tasks query failed', [
                'hostname' => $input['hostname'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $mapped = array_map(
            fn (array $task): array => $this->redactSensitiveKeys($task),
            array_slice($this->listPayload($tasks), 0, $limit),
        );

        return [
            'device' => $this->mapDeviceSnapshot($resolved['tactical_asset']),
            'count' => count($mapped),
            'tasks' => $mapped,
        ];
    }

    private function getEndpointInsight(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        return [
            'device' => $this->mapDeviceSnapshot($resolved['tactical_asset']),
            'insight' => $this->mapEndpointInsight(
                app(TacticalInsightService::class)->forAsset($resolved['asset'], live: true),
            ),
        ];
    }

    private function listScripts(array $input): array
    {
        $limit = $this->limit($input, default: 25, max: 100);
        $queryText = trim((string) ($input['query'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));

        $query = TacticalScript::query()->where('hidden', false);

        if ($queryText !== '') {
            $like = '%'.mb_strtolower($queryText).'%';
            $query->where(function (Builder $scriptQuery) use ($like) {
                $scriptQuery
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(category, "")) LIKE ?', [$like]);
            });
        }

        if ($category !== '') {
            $query->whereRaw('LOWER(COALESCE(category, "")) = ?', [mb_strtolower($category)]);
        }

        $scripts = $query
            ->orderByRaw('LOWER(name)')
            ->limit($limit)
            ->get()
            ->map(fn (TacticalScript $script): array => $this->mapScript($script))
            ->values()
            ->all();

        return [
            'count' => count($scripts),
            'scripts' => $scripts,
        ];
    }

    private function getScript(array $input): array
    {
        $scriptId = $this->positiveInt($input['script_id'] ?? null);
        if ($scriptId === null) {
            return ['error' => 'script_id is required'];
        }

        $script = TacticalScript::where('hidden', false)
            ->where(function (Builder $query) use ($scriptId) {
                $query
                    ->where('id', $scriptId)
                    ->orWhere('tactical_script_id', $scriptId);
            })
            ->first();

        if (! $script) {
            return ['error' => "Tactical script {$scriptId} was not found"];
        }

        return $this->mapScript($script);
    }

    private function listRecentActions(array $input, int $clientId): array
    {
        $limit = $this->limit($input, default: 10, max: 50);
        $hostname = trim((string) ($input['hostname'] ?? ''));

        $query = TacticalActionLog::query();

        if ($hostname !== '') {
            $resolved = $this->resolveAgent($hostname, $clientId);
            if (! $resolved) {
                return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
            }

            $query->where('asset_id', $resolved['asset']->id);
        } else {
            $assetIds = Asset::where('client_id', $clientId)->pluck('id');
            $query->whereIn('asset_id', $assetIds);
        }

        $actions = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (TacticalActionLog $log): array => $this->mapActionLog($log))
            ->values()
            ->all();

        return [
            'count' => count($actions),
            'actions' => $actions,
        ];
    }

    private function listClientsSites(array $input): array
    {
        $limit = $this->limit($input, default: 50, max: 100);

        $mappings = Client::whereNotNull('tactical_site_id')
            ->where('tactical_site_id', '!=', '')
            ->orderByRaw('LOWER(name)')
            ->limit($limit)
            ->get(['id', 'name', 'tactical_site_id'])
            ->map(fn (Client $client): array => [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'tactical_site_id' => $client->tactical_site_id,
            ])
            ->values()
            ->all();

        return [
            'count' => count($mappings),
            'mappings' => $mappings,
        ];
    }

    private function listPolicies(array $input): array
    {
        $limit = $this->limit($input, default: 50, max: 100);

        try {
            $policies = app(TacticalClient::class)->getPolicies();
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical policies query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $mapped = array_map(
            fn (array $policy): array => $this->redactSensitiveKeys($policy),
            array_slice($this->listPayload($policies), 0, $limit),
        );

        return [
            'count' => count($mapped),
            'policies' => $mapped,
        ];
    }

    private function listUrlActions(array $input): array
    {
        $limit = $this->limit($input, default: 50, max: 100);

        try {
            $urlActions = app(TacticalClient::class)->getUrlActions();
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical URL actions query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $mapped = array_map(
            fn (array $urlAction): array => $this->redactSensitiveKeys($urlAction),
            array_slice($this->listPayload($urlActions), 0, $limit),
        );

        return [
            'count' => count($mapped),
            'url_actions' => $mapped,
        ];
    }

    private function listAlertTemplates(array $input): array
    {
        $limit = $this->limit($input, default: 50, max: 100);

        try {
            $templates = app(TacticalClient::class)->getAlertTemplates();
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical alert templates query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $mapped = array_map(
            fn (array $template): array => $this->redactSensitiveKeys($template),
            array_slice($this->listPayload($templates), 0, $limit),
        );

        return [
            'count' => count($mapped),
            'alert_templates' => $mapped,
        ];
    }

    private function getCoreSettings(): array
    {
        try {
            $settings = app(TacticalClient::class)->getCoreSettings();
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical core settings query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return ['settings' => $this->redactSensitiveKeys($settings)];
    }

    private function healthCheck(): array
    {
        return [
            'configured' => TacticalConfig::isConfigured(),
            'healthy' => app(TacticalClient::class)->isHealthy(),
        ];
    }

    private function diagnoseDevice(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $patches = $this->getDevicePatches($input, $clientId);
        if (isset($patches['error'])) {
            $patches = ['error' => $patches['error'], 'count' => 0, 'patches' => []];
        }

        $tasks = $this->getDeviceTasks($input, $clientId);
        if (isset($tasks['error'])) {
            $tasks = ['error' => $tasks['error'], 'count' => 0, 'tasks' => []];
        }

        $recentActions = $this->listRecentActions($input, $clientId);
        if (isset($recentActions['error'])) {
            $recentActions = ['error' => $recentActions['error'], 'count' => 0, 'actions' => []];
        }

        return [
            'device' => $this->mapDeviceSnapshot($resolved['tactical_asset']),
            'insight' => $this->mapEndpointInsight(
                app(TacticalInsightService::class)->forAsset($resolved['asset'], live: true),
            ),
            'patches' => [
                'count' => $patches['count'] ?? 0,
                'patches' => $patches['patches'] ?? [],
            ],
            'tasks' => [
                'count' => $tasks['count'] ?? 0,
                'tasks' => $tasks['tasks'] ?? [],
            ],
            'recent_actions' => [
                'count' => $recentActions['count'] ?? 0,
                'actions' => $recentActions['actions'] ?? [],
            ],
        ];
    }

    private function limit(array $input, int $default, int $max): int
    {
        $limit = $this->positiveInt($input['limit'] ?? null) ?? $default;

        return min(max($limit, 1), $max);
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function listPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['results', 'data', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->listPayload($payload[$key]);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDeviceSnapshot(TacticalAsset $asset): array
    {
        $checksSummary = null;
        if ($asset->checks_total !== null) {
            $failing = (int) ($asset->checks_failing ?? 0);
            $checksSummary = "{$failing} failing / {$asset->checks_total} total";
        }

        return [
            'asset_id' => $asset->asset_id,
            'hostname' => $asset->hostname ?? $asset->asset?->hostname,
            'status' => $asset->status,
            'os' => $asset->os,
            'os_version' => $asset->os_version,
            'public_ip' => $asset->public_ip,
            'local_ips' => $asset->local_ips,
            'last_user' => $this->textSanitizer->sanitizeNullable(
                'Tactical last user',
                $asset->last_user,
                200,
                ['None', '-'],
            ),
            'cpu' => $asset->cpu,
            'make_model' => $asset->make_model,
            'disk_summary' => $asset->disk_summary,
            'ram_gb' => $asset->ram_gb !== null ? (float) $asset->ram_gb : null,
            'serial_number' => $asset->serial_number,
            'agent_version' => $asset->agent_version,
            'client_name' => $asset->client_name,
            'site_name' => $asset->site_name,
            'needs_reboot' => (bool) $asset->needs_reboot,
            'has_patches_pending' => (bool) $asset->has_patches_pending,
            'checks_failing' => $asset->checks_failing,
            'checks_total' => $asset->checks_total,
            'checks_summary' => $checksSummary,
            'last_seen_at' => $asset->last_seen_at?->toIso8601String(),
            'synced_at' => $asset->synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapScript(TacticalScript $script): array
    {
        return [
            'id' => $script->id,
            'tactical_script_id' => $script->tactical_script_id,
            'name' => $script->name,
            'description' => $this->textSanitizer->sanitizeNullable('Tactical script description', $script->description, 1000),
            'shell' => $script->shell,
            'category' => $script->category,
            'default_timeout' => $script->default_timeout,
            'supported_platforms' => $script->supported_platforms,
            'synced_at' => $script->synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEndpointInsight(EndpointInsight $insight): array
    {
        return [
            'linked' => $insight->linked,
            'hostname' => $insight->hostname,
            'status' => $insight->status,
            'status_state' => $insight->statusState->value,
            'last_seen_at' => $insight->lastSeen?->toIso8601String(),
            'uptime' => $insight->uptime,
            'cpu' => $insight->cpu,
            'ram_gb' => $insight->ramGb,
            'disk_summary' => $insight->diskSummary,
            'disk_volumes' => $insight->diskVolumes,
            'needs_reboot' => $insight->needsReboot,
            'low_disk' => $insight->lowDisk,
            'long_offline' => $insight->longOffline,
            'stale' => $insight->stale,
            'maintenance' => $insight->maintenance,
            'user_logged_in' => $insight->userLoggedIn,
            'checks_state' => $insight->checksState->value,
            'checks_failing' => $insight->checksFailing,
            'checks_total' => $insight->checksTotal,
            'open_alerts' => $insight->openAlerts,
            'open_alerts_list' => array_map(fn (array $alert): array => [
                'title' => $this->textSanitizer->sanitizeNullable('Tactical alert title', $alert['title'] ?? null, 300),
                'severity' => $alert['severity'] ?? null,
                'source' => $alert['source'] ?? null,
            ], $insight->openAlertList),
            'pending_patch_count' => $insight->pendingPatchCount,
            'has_pending_patches' => $insight->hasPendingPatches,
            'recent_actions' => $insight->recentActions,
            'failing_checks' => array_map(fn (FailingCheck $check): array => [
                'name' => $check->name,
                'status' => $check->status,
                'retcode' => $check->retcode,
                'stdout' => $this->textSanitizer->sanitize('Tactical failing check stdout', $check->stdout, 500),
            ], $insight->failingChecks),
            'fresh_as_of' => $insight->freshAsOf?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapActionLog(TacticalActionLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action_key,
            'actor' => $log->actor_label,
            'result_status' => $log->result_status,
            'retcode' => $log->retcode,
            'ticket_id' => $log->ticket_id,
            'target_label' => $this->textSanitizer->sanitizeNullable('Tactical action target', $log->target_label, 300),
            'message' => $this->textSanitizer->sanitizeNullable('Tactical action message', $log->message, 500),
            'output' => $this->textSanitizer->sanitizeNullable('Tactical action output', $log->output, 1000),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function redactSensitiveKeys(array $row): array
    {
        $safe = [];

        foreach ($row as $key => $value) {
            if ($this->isSensitiveResponseKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->redactSensitiveKeys($item) : $item, $value)
                    : $this->redactSensitiveKeys($value);

                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    private function isSensitiveResponseKey(string $key): bool
    {
        $normalized = mb_strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalized, [
            'api_key',
            'apikey',
            'authorization',
            'auth_header',
            'headers',
            'rest_headers',
            'rest_body',
            'body',
            'password',
            'passwd',
            'secret',
            'token',
            'private_key',
            'client_secret',
            'webhook_secret',
        ], true)) {
            return true;
        }

        foreach (['secret', 'password', 'passwd', 'token', 'authorization', 'api_key', 'apikey', 'rest_header', 'rest_body'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getDevice(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical device query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $checksSummary = null;
        $checks = $agent['checks'] ?? null;
        if (is_array($checks) && isset($checks['total'])) {
            $failing = (int) ($checks['failing'] ?? 0);
            $total = (int) $checks['total'];
            $checksSummary = "{$failing} failing / {$total} total";
        }

        return [
            'hostname' => $agent['hostname'] ?? $hostname,
            'status' => $agent['status'] ?? null,
            'os' => $agent['operating_system'] ?? null,
            'cpu' => $agent['cpu_model'] ?? null,
            'ram_gb' => TacticalFieldMap::ramGb($agent['total_ram'] ?? null),
            'make_model' => $agent['make_model'] ?? null,
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $agent['local_ips'] ?? null,
            'logged_in_user' => $this->textSanitizer->sanitizeNullable(
                'Tactical logged in user',
                $agent['logged_in_username'] ?? null,
                200,
                ['None'],
            ),
            'needs_reboot' => $agent['needs_reboot'] ?? false,
            'uptime' => TacticalFieldMap::uptimeFromBootTime($agent['boot_time'] ?? null),
            'checks_summary' => $checksSummary,
        ];
    }

    private function getDeviceChecks(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $checks = app(TacticalClient::class)->getAgentChecks($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical checks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($check) => [
            'name' => $check['name'] ?? $check['readable_desc'] ?? 'Unknown',
            'status' => $check['check_result']['status'] ?? $check['status'] ?? 'unknown',
            'retcode' => $check['check_result']['retcode'] ?? null,
            'stdout' => $this->textSanitizer->sanitize('Tactical check stdout', $check['check_result']['stdout'] ?? '', 500),
        ], array_slice($checks, 0, 50));
    }

    private function getDeviceNetwork(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical network query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return TacticalFieldMap::mapNetwork($agent);
    }

    private function getDeviceSoftware(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $payload = app(TacticalClient::class)->getSoftware($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical software query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // The inventory rows arrive wrapped as {id, agent, software: [...]}.
        $software = TacticalFieldMap::softwareRows($payload);

        usort($software, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_map(fn ($softwareRow) => [
            'name' => $this->textSanitizer->sanitizeNullable('Tactical software name', $softwareRow['name'] ?? null, 200) ?? 'Unknown',
            'version' => $softwareRow['version'] ?? null,
            'publisher' => $this->textSanitizer->sanitizeNullable('Tactical software publisher', $softwareRow['publisher'] ?? null, 200),
        ], array_slice($software, 0, 50));
    }

    private function getDeviceServices(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical services query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $services = collect($agent['services'] ?? []);
        $filter = isset($input['filter']) ? trim((string) $input['filter']) : '';

        if ($filter !== '') {
            $filterLower = mb_strtolower($filter);
            $services = $services->filter(function ($service) use ($filterLower) {
                if ($filterLower === 'running') {
                    return mb_strtolower($service['status'] ?? '') === 'running';
                }

                if ($filterLower === 'stopped') {
                    return mb_strtolower($service['status'] ?? '') === 'stopped';
                }

                return str_contains(mb_strtolower($service['display_name'] ?? $service['name'] ?? ''), $filterLower)
                    || str_contains(mb_strtolower($service['name'] ?? ''), $filterLower);
            });
        }

        return $services->take(50)->map(fn ($service) => [
            'name' => $this->textSanitizer->sanitizeNullable('Tactical service name', $service['name'] ?? null, 200),
            'display_name' => $this->textSanitizer->sanitizeNullable('Tactical service display name', $service['display_name'] ?? null, 200),
            'status' => $service['status'] ?? null,
            'start_type' => $service['start_type'] ?? null,
        ])->values()->toArray();
    }

    private function getDeviceDisks(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical disks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return [
            'volumes' => TacticalFieldMap::mapDiskVolumes(
                is_array($agent['disks'] ?? null) ? $agent['disks'] : [],
                includeFilesystemType: true,
            ),
            'physical_disks' => collect($agent['physical_disks'] ?? [])->take(10)->map(fn ($disk) => [
                'model' => $disk['caption'] ?? $disk['model'] ?? null,
                'size_gb' => isset($disk['size']) ? round($disk['size'] / 1073741824, 1) : null,
                'interface' => $disk['interface_type'] ?? null,
                'status' => $disk['status'] ?? null,
            ])->toArray(),
            'wmi_disk' => collect($agent['wmi_detail']['disk'] ?? [])->take(10)->map(fn ($disk) => [
                'caption' => $disk['Caption'] ?? null,
                'size_gb' => isset($disk['Size']) ? round($disk['Size'] / 1073741824, 1) : null,
                'free_gb' => isset($disk['FreeSpace']) ? round($disk['FreeSpace'] / 1073741824, 1) : null,
            ])->toArray(),
        ];
    }
}
