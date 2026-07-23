<?php

namespace App\Services\Unifi;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\UnifiConfig;
use Illuminate\Support\Facades\Log;

/**
 * UniFi read-only network telemetry tools for the staff MCP surface (psa-1ynqc).
 *
 * Motivating incident: T-22724, a Comcast WAN fault whose root cause required a human
 * to open the UniFi console by hand because the agent had no way to see WAN state.
 *
 * SHAPE SOURCE: every field name below comes from the vendor's own OpenAPI spec —
 * https://developer.ui.com/site-manager/v1.0.0/openapi.json — never from docs prose or
 * inference. See UnifiClient's docblock for the four shape facts that matter and
 * tests/Fixtures/unifi/*.json for the vendor payloads the tests assert against.
 *
 * DATA-BOUNDARY RULE (mirrors HuntressReadOnlyToolset — a UI account can administer
 * consoles belonging to more than one client, and in principle more than one MSP):
 *  - Site METADATA is account-wide and annotated with its mapped PSA client (or null),
 *    so a human can discover what still needs mapping. Metadata ONLY — no telemetry.
 *  - TELEMETRY (health, devices, ISP metrics) is MAPPED-SITES-ONLY, resolved from
 *    clients.unifi_site_id / clients.unifi_host_id — never from tool input.
 *
 * TWO SCOPING HAZARDS THE UPSTREAM API CREATES, both handled here rather than papered
 * over — read these before changing any filter:
 *  1. GET /v1/isp-metrics/{type} accepts NO site filter. It returns one row per visible
 *     site, each tagged {hostId, siteId}. We filter to the caller's site ourselves;
 *     handing the response back unfiltered would leak every other client's WAN data.
 *  2. GET /v1/devices is grouped by HOST and carries NO siteId anywhere. A device is
 *     therefore only attributable to a client through its console. Where one console
 *     serves several mapped clients that attribution does not exist upstream, so
 *     unifi_list_devices REFUSES instead of guessing.
 *
 * READ-ONLY. The spec also exposes /v1/connector/consoles/{id}/*path — a generic
 * passthrough to a console's local Network API supporting POST/PUT/PATCH/DELETE. It is
 * deliberately absent here and from UnifiClient; exposing a runtime-chosen path to an
 * agent is a separate decision with its own review, not a detail of a telemetry PR.
 */
class UnifiReadOnlyToolset
{
    private const GENERAL_TOOL_NAMES = [
        'unifi_list_sites',
    ];

    private const CLIENT_TOOL_NAMES = [
        'unifi_get_site_health',
        'unifi_list_devices',
        'unifi_get_isp_metrics',
    ];

    /** Bounds the page walk when locating a client's site by id. */
    private const MAX_SITE_LOOKUP_PAGES = 20;

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_merge(self::generalDefinitions(), self::clientDefinitions());
    }

    /** @return array<int, array<string, mixed>> */
    public static function generalDefinitions(): array
    {
        return [
            [
                'name' => 'unifi_list_sites',
                'description' => 'List UniFi sites across every console the UniFi account administers, each annotated with its mapped PSA client (or null when unmapped). Use this to resolve a PSA client to its UniFi site and to discover sites that still need mapping. Returns site metadata only — no health, ISP or device data; use unifi_get_site_health for a mapped client.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max sites to return (default 50, max 100).'],
                        'page_token' => ['type' => 'string', 'description' => 'Opaque cursor from a previous response next_page_token.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'unifi_get_site_health',
                'description' => "Get current network health for a PSA client's mapped UniFi site: ISP name, WAN uptime percentage, any open internet issues, gateway model, and device counts including how many are offline or awaiting a firmware update. Start here when a client reports an internet or site-wide network problem.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a UniFi site.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'unifi_list_devices',
                'description' => "List UniFi devices (gateways, switches, access points) on a PSA client's console with their up/down status, model, IP, firmware status and uptime. Use this to find which access point or switch is offline.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a UniFi site and console.'],
                        'status' => ['type' => 'string', 'description' => "Optional filter on device status, e.g. 'online' or 'offline'."],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'unifi_get_isp_metrics',
                'description' => "Get WAN/ISP telemetry over time for a PSA client's mapped UniFi site: average and peak latency, packet loss, downtime, and throughput per sample period, plus the ISP name and ASN. Use this to evidence or rule out an ISP fault — it answers 'was the internet actually down, and when'.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a UniFi site.'],
                        'type' => ['type' => 'string', 'description' => "Sample interval: '5m' (retained at least 24h) or '1h' (retained at least 30 days). Defaults to 5m."],
                        'duration' => ['type' => 'string', 'description' => "Window ending now: '24h' for 5m samples, or '7d'/'30d' for 1h samples. Mutually exclusive with begin_timestamp/end_timestamp. Defaults to 24h for 5m and 7d for 1h."],
                        'begin_timestamp' => ['type' => 'string', 'description' => 'RFC3339 start, e.g. 2026-07-23T13:35:00Z. Use with end_timestamp instead of duration.'],
                        'end_timestamp' => ['type' => 'string', 'description' => 'RFC3339 end. Use with begin_timestamp instead of duration.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::GENERAL_TOOL_NAMES, true)
            || in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        // OFF=OFF: the master switch withdraws the capability, not just the syncs.
        if (! UnifiConfig::isAvailable()) {
            return ['error' => 'UniFi is not available in this deployment — it is either switched off or has no API key configured.'];
        }

        return match ($toolName) {
            'unifi_list_sites' => $this->listSites($input),
            'unifi_get_site_health' => $this->getSiteHealth($input, $clientId),
            'unifi_list_devices' => $this->listDevices($input, $clientId),
            'unifi_get_isp_metrics' => $this->getIspMetrics($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── mapping helper (account-wide METADATA only) ────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listSites(array $input): array
    {
        $params = ['pageSize' => (string) $this->limit($input, default: 50, max: 100)];

        $token = trim((string) ($input['page_token'] ?? ''));
        if ($token !== '') {
            $params['nextToken'] = $token;
        }

        try {
            $response = $this->client()->listSites($params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $mapped = $this->mappedClientsBySiteId();

        $sites = [];
        foreach ($this->rows($response) as $row) {
            $siteId = $this->scalarOrNull($row['siteId'] ?? null);
            if ($siteId === null) {
                continue;
            }
            $client = $mapped->get($siteId);

            // METADATA ONLY. `statistics` is deliberately not projected here: an
            // unmapped row belongs to a site we have not associated with a client, and
            // its health data must not ride along on the mapping helper.
            $sites[] = [
                'site_id' => $siteId,
                'host_id' => $this->scalarOrNull($row['hostId'] ?? null),
                'name' => $this->textSanitizer->sanitizeNullable('UniFi site name', $row['meta']['name'] ?? null, 200),
                'description' => $this->textSanitizer->sanitizeNullable('UniFi site description', $row['meta']['desc'] ?? null, 300),
                'timezone' => $this->scalarOrNull($row['meta']['timezone'] ?? null),
                'permission' => $this->scalarOrNull($row['permission'] ?? null),
                'is_owner' => (bool) ($row['isOwner'] ?? false),
                'psa_client_id' => $client?->id,
                'psa_client_name' => $client?->name,
            ];
        }

        return [
            'count' => count($sites),
            'sites' => $sites,
            'next_page_token' => $this->nextPageToken($response),
        ];
    }

    // ── telemetry (mapped-sites-only) ──────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getSiteHealth(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        try {
            $row = $this->findSiteRow($client->unifi_site_id);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        if ($row === null) {
            return ['error' => "UniFi site {$client->unifi_site_id} (mapped to {$client->name}) was not found on this UniFi account."];
        }

        $stats = is_array($row['statistics'] ?? null) ? $row['statistics'] : [];

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'site_id' => $this->scalarOrNull($row['siteId'] ?? null),
            'host_id' => $this->scalarOrNull($row['hostId'] ?? null),
            'site_name' => $this->textSanitizer->sanitizeNullable('UniFi site name', $row['meta']['name'] ?? null, 200),
            'isp_name' => $this->textSanitizer->sanitizeNullable('UniFi ISP name', $stats['ispInfo']['name'] ?? null, 200),
            'isp_organization' => $this->textSanitizer->sanitizeNullable('UniFi ISP organization', $stats['ispInfo']['organization'] ?? null, 200),
            'wan_uptime_percent' => $this->numberOrNull($stats['percentages']['wanUptime'] ?? null),
            // Element shape is unverified — the vendor example carries an empty array —
            // so this is passed through the bounded leaf-sanitizer, never field-projected.
            'internet_issues' => $this->sanitizeStructure('UniFi internet issue', $stats['internetIssues'] ?? []),
            'gateway_model' => $this->scalarOrNull($stats['gateway']['shortname'] ?? null),
            'counts' => $this->scalarMap($stats['counts'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listDevices(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        $hostId = $client->unifi_host_id;
        if ($hostId === null || $hostId === '') {
            return ['error' => "{$client->name} is mapped to a UniFi site but not to a console (unifi_host_id), and device state is reported per console. Set the console mapping to use this tool."];
        }

        // Hazard 2: /v1/devices has no siteId. If this console serves more than one
        // mapped client, upstream gives us nothing to split its devices by — answering
        // would show this client another client's hardware. Refuse instead.
        $sharing = Client::where('unifi_host_id', $hostId)
            ->whereNotNull('unifi_site_id')
            ->pluck('name')
            ->all();

        if (count($sharing) > 1) {
            return ['error' => 'That UniFi console is mapped to more than one PSA client ('.implode(', ', $sharing).') and UniFi does not report a site for each device, so devices cannot be attributed to a single client. Map each client to its own console, or read device state in the UniFi UI.'];
        }

        try {
            $response = $this->client()->listDevices();
            $groups = $this->rows($response);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // Filter to this console client-side. The upstream `hostIds[]` query parameter
        // is deliberately not used: scoping we perform ourselves is scoping we can test.
        $groups = array_values(array_filter(
            $groups,
            fn ($group) => ($group['hostId'] ?? null) === $hostId,
        ));

        $devices = $this->client()->flattenDevices($groups);

        $statusFilter = strtolower(trim((string) ($input['status'] ?? '')));

        $rows = [];
        $offline = 0;
        foreach ($devices as $device) {
            $status = $this->scalarOrNull($device['status'] ?? null);

            if ($statusFilter !== '' && strtolower((string) $status) !== $statusFilter) {
                continue;
            }

            if (is_string($status) && strtolower($status) !== 'online') {
                $offline++;
            }

            $rows[] = [
                'id' => $this->scalarOrNull($device['id'] ?? null),
                'mac' => $this->scalarOrNull($device['mac'] ?? null),
                'name' => $this->textSanitizer->sanitizeNullable('UniFi device name', $device['name'] ?? null, 200),
                'model' => $this->scalarOrNull($device['model'] ?? null),
                'status' => $status,
                'ip' => $this->scalarOrNull($device['ip'] ?? null),
                'product_line' => $this->scalarOrNull($device['productLine'] ?? null),
                'firmware_version' => $this->scalarOrNull($device['version'] ?? null),
                'firmware_status' => $this->scalarOrNull($device['firmwareStatus'] ?? null),
                'is_console' => (bool) ($device['isConsole'] ?? false),
                'is_managed' => (bool) ($device['isManaged'] ?? false),
                'startup_time' => $this->scalarOrNull($device['startupTime'] ?? null),
                'note' => $this->textSanitizer->sanitizeNullable('UniFi device note', $device['note'] ?? null, 500),
            ];
        }

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'host_id' => $hostId,
            'count' => count($rows),
            'offline_count' => $offline,
            'devices' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getIspMetrics(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        $type = trim((string) ($input['type'] ?? '5m')) ?: '5m';
        if (! in_array($type, UnifiClient::METRIC_TYPES, true)) {
            return ['error' => "Unsupported interval type '{$type}'. UniFi reports ISP metrics as '5m' (retained at least 24h) or '1h' (retained at least 30 days)."];
        }

        $params = [];
        $begin = trim((string) ($input['begin_timestamp'] ?? ''));
        $end = trim((string) ($input['end_timestamp'] ?? ''));

        if ($begin !== '' && $end !== '') {
            // The vendor documents duration and begin/end as mutually exclusive.
            $params['beginTimestamp'] = $begin;
            $params['endTimestamp'] = $end;
        } else {
            $duration = trim((string) ($input['duration'] ?? ''));
            $params['duration'] = $duration !== '' ? $duration : ($type === '5m' ? '24h' : '7d');
        }

        try {
            $response = $this->client()->getIspMetrics($type, $params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // Hazard 1: the endpoint has no site filter and returns every visible site.
        // Scope to the caller's site here — this filter is the data boundary.
        $siteId = $client->unifi_site_id;
        $periods = [];
        foreach ($this->rows($response) as $row) {
            if (($row['siteId'] ?? null) !== $siteId) {
                continue;
            }

            foreach ((array) ($row['periods'] ?? []) as $period) {
                if (! is_array($period)) {
                    continue;
                }
                $wan = is_array($period['data']['wan'] ?? null) ? $period['data']['wan'] : [];

                $periods[] = [
                    'metric_time' => $this->scalarOrNull($period['metricTime'] ?? null),
                    'avg_latency_ms' => $this->numberOrNull($wan['avgLatency'] ?? null),
                    'max_latency_ms' => $this->numberOrNull($wan['maxLatency'] ?? null),
                    'packet_loss_percent' => $this->numberOrNull($wan['packetLoss'] ?? null),
                    'downtime_seconds' => $this->numberOrNull($wan['downtime'] ?? null),
                    'uptime_seconds' => $this->numberOrNull($wan['uptime'] ?? null),
                    // SNAKE_CASE upstream, among camelCase siblings. Read exactly as the
                    // vendor emits them — see UnifiClient shape fact 3.
                    'download_kbps' => $this->numberOrNull($wan['download_kbps'] ?? null),
                    'upload_kbps' => $this->numberOrNull($wan['upload_kbps'] ?? null),
                    'isp_name' => $this->textSanitizer->sanitizeNullable('UniFi ISP name', $wan['ispName'] ?? null, 200),
                    'isp_asn' => $this->scalarOrNull($wan['ispAsn'] ?? null),
                ];
            }
        }

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'site_id' => $siteId,
            'interval' => $type,
            'count' => count($periods),
            'periods' => $periods,
        ];
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a client-scoped tool and prove it is UniFi-mapped.
     * Returns the Client, or an error payload array to hand straight back.
     *
     * @param  array<string, mixed>  $input
     * @return Client|array<string, mixed>
     */
    private function resolveMappedClient(array $input, ?int $clientId): Client|array
    {
        $id = $clientId ?? $this->positiveInt($input['client_id'] ?? null);
        if ($id === null) {
            return ['error' => 'client_id is required'];
        }

        $client = Client::find($id);
        if ($client === null) {
            return ['error' => "PSA client {$id} was not found."];
        }

        if (empty($client->unifi_site_id)) {
            return ['error' => "{$client->name} is not mapped to a UniFi site. Map the client first (Settings > UniFi Site Mapping); unifi_list_sites shows the sites available to map."];
        }

        return $client;
    }

    /**
     * Locate one site row by id, walking the cursor a bounded number of pages.
     *
     * @return array<string, mixed>|null
     */
    private function findSiteRow(string $siteId): ?array
    {
        $params = ['pageSize' => '100'];

        for ($page = 0; $page < self::MAX_SITE_LOOKUP_PAGES; $page++) {
            $response = $this->client()->listSites($params);

            foreach ($this->rows($response) as $row) {
                if (($row['siteId'] ?? null) === $siteId) {
                    return $row;
                }
            }

            $next = $this->nextPageToken($response);
            if ($next === null) {
                return null;
            }
            $params['nextToken'] = $next;
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<string, Client> PSA clients keyed by unifi_site_id. */
    private function mappedClientsBySiteId(): \Illuminate\Support\Collection
    {
        return Client::whereNotNull('unifi_site_id')
            ->get(['id', 'name', 'unifi_site_id', 'unifi_host_id'])
            ->keyBy('unifi_site_id');
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    private function client(): UnifiClient
    {
        return app(UnifiClient::class);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $response): array
    {
        $rows = $response['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $response */
    private function nextPageToken(array $response): ?string
    {
        $token = $response['nextToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @param array<string, mixed> $input */
    private function limit(array $input, int $default, int $max): int
    {
        $limit = $this->positiveInt($input['limit'] ?? null) ?? $default;

        return max(1, min($limit, $max));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }

    /**
     * Bounded recursive leaf-sanitizer for untrusted nested structures whose element
     * shape we have not verified against a real payload (internetIssues). String leaves
     * are redacted and fenced; scalars pass through; depth and breadth are capped.
     */
    private function sanitizeStructure(string $label, mixed $value, int $maxDepth = 4, int $maxItems = 30): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitizeNullable($label, $value, 500);
        }

        if (! is_array($value) || $maxDepth <= 0) {
            return is_array($value) ? '[truncated]' : $this->scalarOrNull($value);
        }

        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= $maxItems) {
                $out['_truncated'] = true;
                break;
            }
            $out[$key] = $this->sanitizeStructure($label, $item, $maxDepth - 1, $maxItems);
        }

        return $out;
    }

    private function apiError(\Throwable $e): array
    {
        Log::warning('[UniFi reads] query failed', ['error' => $e->getMessage()]);

        return ['error' => 'UniFi query failed: '.mb_substr($e->getMessage(), 0, 200)];
    }
}
