<?php

namespace App\Services\Servosity;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\ServosityConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Servosity backup read tool for the staff MCP surface (psa-z30dv).
 *
 * Answers what the PSA can HONESTLY answer about a client's Servosity posture:
 * which devices have backup enabled and whether provisioning completed
 * (synced), what account counts the last license sync recorded (synced, with
 * its own freshness), and what Servosity reports live right now (account
 * counts, issue counts, DR backup accounts).
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM: job-level run history. did-the-last-
 * backup-run / did-it-succeed is STRUCTURALLY UNAVAILABLE through this
 * integration — no production code path consumes a Servosity jobs endpoint,
 * and per CLAUDE.md's vendor-shape rules we do not guess one into existence.
 * The payload says so in job_run_history rather than letting an absence of
 * failures read as verified-good (the psa-z30dv acceptance line).
 *
 * VENDOR SHAPE — only fields production code already consumes are projected
 * (Servosity is closed-source; these shapes are proven by the running sync):
 *  - companies/summary-ng: results[].id + account_counts{Mailboxes,DRS,DRD,
 *    Std,Pro,NAS} (ServosityLicenseSyncService) — issue_counts is named by
 *    ServosityClient::getCompanies()'s docblock but never projected anywhere,
 *    so it is passed through OPAQUELY (leaf-sanitized, shape vendor-defined),
 *    and its absence from the row is reported as drift, not as zero issues.
 *  - dr-backups/?company=N: results[].{id, device_name, agent_session_id}
 *    (ServosityDeploymentService::provisionSingleAsset).
 *
 * DATA BOUNDARY: scope resolves from clients.servosity_company_id on the PSA
 * client row, never from tool input. Live reads are filtered to that company
 * id; synced reads are client_id-scoped. servosity_backup_password exists on
 * the asset row and MUST never appear in any payload.
 */
class ServosityReadOnlyToolset
{
    private const CLIENT_TOOL_NAMES = [
        'servosity_get_backup_status',
    ];

    /** Same threshold as the sibling backup/DNS read surfaces: >48h = a missed daily sync cycle. */
    private const STALE_AFTER_HOURS = 48;

    private const MAX_DEVICE_ROWS = 100;

    private const MAX_DR_BACKUP_ROWS = 100;

    private const DATA_SOURCE_NOTE = 'Per-device enabled/provisioned state and license counts are synced Servosity data from the PSA database — check data_as_of and each row\'s synced_at. The live section is a Servosity API query as of live_checked_at. Job-level run history is NOT available through this integration (see job_run_history).';

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::clientDefinitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'servosity_get_backup_status',
                'description' => "Get a PSA client's Servosity backup posture: which devices have backup enabled and whether provisioning completed (synced), backup account counts by product from the last license sync (with freshness), and a LIVE Servosity query of account counts, open issue counts, and DR backup accounts with their agent-link state. IMPORTANT: Servosity job-level run history (did the last backup run, did it succeed) is NOT available through this integration — the response says so explicitly. Never infer 'backups are healthy' from an absence of failures here; issue counts and provisioning state are the strongest signals available, and job-level state must be verified in the Servosity console.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a Servosity company.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
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
        if (! ServosityConfig::isAvailable()) {
            return ['error' => 'Servosity is not available in this deployment — it is either switched off or has no API token configured.'];
        }

        return match ($toolName) {
            'servosity_get_backup_status' => $this->getBackupStatus($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getBackupStatus(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        $result = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'servosity_company_id' => (int) $client->servosity_company_id,
            'data_source' => self::DATA_SOURCE_NOTE,
        ];

        // ── synced per-device provisioning posture ────────────────────────────
        $devices = $this->enabledDeviceQuery($client)
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->get(['id', 'hostname', 'name', 'asset_type', 'servosity_dr_backup_id']);

        $result['enabled_device_count'] = $devices->count();
        $result['provisioned_count'] = $devices->whereNotNull('servosity_dr_backup_id')->count();
        $result['pending_provisioning_count'] = $devices->whereNull('servosity_dr_backup_id')->count();
        $result['devices'] = $devices->take(self::MAX_DEVICE_ROWS)->map(fn (Asset $asset): array => [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'asset_name' => $asset->name,
            'asset_type' => $asset->asset_type,
            'dr_backup_id' => $asset->servosity_dr_backup_id,
            // Enabled with no DR account yet = the agent never registered — a
            // "configured but not running" finding, not a detail.
            'provisioning_state' => $asset->servosity_dr_backup_id !== null ? 'provisioned' : 'pending_agent_registration',
        ])->values()->all();
        $result['devices_truncated'] = $devices->count() > self::MAX_DEVICE_ROWS;

        if ($devices->isEmpty()) {
            $result['devices_note'] = "No PSA asset for {$client->name} has Servosity backup enabled. M365/mailbox and NAS protection do not require an enabled PSA asset, so check the account counts below before treating this client as not covered.";
        }

        // ── synced license (account-count) posture, with its own freshness ────
        $licenses = License::with('licenseType')
            ->where('client_id', $client->id)
            ->whereHas('licenseType', fn ($query) => $query->where('vendor', 'servosity'))
            ->get();

        $result['synced_account_counts'] = $licenses->map(fn (License $license): array => [
            'product' => $license->licenseType?->name,
            'vendor_sku_id' => $license->licenseType?->vendor_sku_id,
            'quantity' => $license->quantity,
            'status' => $license->status,
            'synced_at' => $license->synced_at?->toIso8601ZuluString(),
            'stale' => $this->isStale($license->synced_at),
        ])->values()->all();
        $result = array_merge($result, $this->syncedFreshness($licenses->pluck('synced_at')));

        // ── live Servosity state (each section degrades LOUDLY, never to []) ──
        $result['live'] = $this->liveCompanyState($client);
        $result['live_dr_backups'] = $this->liveDrBackups($client, $devices);

        // ── the structural honesty block (the acceptance line of psa-z30dv) ───
        $result['job_run_history'] = [
            'available' => false,
            'note' => 'Servosity job-level run history (whether the last backup ran and whether it succeeded) is not available through this PSA integration. '
                .'This answer covers configuration/provisioning posture and account/issue counts only. Do NOT infer "backups are healthy" from the absence of failures here — verify job-level state in the Servosity console.',
        ];

        return $result;
    }

    // ── live sections ──────────────────────────────────────────────────────────

    /**
     * Live account/issue counts for this company from companies/summary-ng —
     * the endpoint whose shape the running license sync proves.
     *
     * @return array<string, mixed>
     */
    private function liveCompanyState(Client $client): array
    {
        try {
            $companies = $this->client()->getCompanies();
        } catch (\Throwable $e) {
            Log::warning('[Servosity reads] getCompanies failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'unavailable',
                'error' => 'Servosity live query failed: '.mb_substr($e->getMessage(), 0, 200),
                'note' => 'Live account/issue counts are UNAVAILABLE — the synced counts above may be out of date. Verify in the Servosity console before relying on this answer.',
            ];
        }

        $companyId = (int) $client->servosity_company_id;
        $company = collect($companies)->first(
            fn ($row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $companyId,
        );

        if ($company === null) {
            return [
                'status' => 'company_not_found',
                'note' => "Servosity's live company list does not contain company {$companyId}. The mapping may be stale or the company removed — verify in the Servosity console. This is NOT an all-clear.",
            ];
        }

        $live = [
            'status' => 'ok',
            'live_checked_at' => now()->toIso8601ZuluString(),
            'account_counts' => $this->scalarMap($company['account_counts'] ?? null),
        ];

        // issue_counts is named by our client's docblock but no production code
        // projects its inner shape — pass it through opaquely and report drift
        // in-band rather than letting a missing key read as "no issues".
        if (array_key_exists('issue_counts', $company)) {
            $live['issue_counts'] = $this->sanitizeStructure('Servosity issue counts', $company['issue_counts']);
            $live['issue_counts_note'] = 'Shape is vendor-defined and passed through as reported. Non-zero values mean Servosity is flagging problems for this company; zero/empty means Servosity reports no open issues — which is a weaker claim than "all backups succeeded".';
        } else {
            $live['issue_counts'] = null;
            $live['issue_counts_note'] = 'The vendor response carried no issue_counts key for this company (shape drift?) — issue state is UNKNOWN, not zero.';
        }

        return $live;
    }

    /**
     * Live DR backup accounts for this company, matched back to PSA assets by
     * hostname. Proves which per-device backup accounts EXIST upstream and
     * whether each is linked to an agent.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $enabledDevices
     * @return array<string, mixed>
     */
    private function liveDrBackups(Client $client, \Illuminate\Support\Collection $enabledDevices): array
    {
        try {
            $response = $this->client()->get('dr-backups/', ['company' => (int) $client->servosity_company_id]);
        } catch (\Throwable $e) {
            Log::warning('[Servosity reads] dr-backups query failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'unavailable',
                'error' => 'Servosity DR backup query failed: '.mb_substr($e->getMessage(), 0, 200),
                'note' => 'Live DR backup accounts are UNAVAILABLE — per-device provisioning could not be verified upstream.',
            ];
        }

        $assetsByHostname = $enabledDevices->keyBy(
            fn (Asset $asset): string => mb_strtolower((string) $asset->hostname),
        );

        $rows = collect($response['results'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->take(self::MAX_DR_BACKUP_ROWS)
            ->map(function (array $row) use ($assetsByHostname): array {
                $deviceName = is_scalar($row['device_name'] ?? null) ? (string) $row['device_name'] : '';
                $matched = $deviceName !== '' ? $assetsByHostname->get(mb_strtolower($deviceName)) : null;

                return [
                    'dr_backup_id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                    // Vendor free text at this trust boundary — travels fenced.
                    'device_name' => $this->textSanitizer->sanitizeNullable('Servosity device name', $deviceName, 200),
                    'agent_linked' => ! empty($row['agent_session_id']),
                    'matched_asset_id' => $matched?->id,
                    'matched_hostname' => $matched?->hostname,
                ];
            })->values()->all();

        $live = [
            'status' => 'ok',
            'live_checked_at' => now()->toIso8601ZuluString(),
            'count' => count($rows),
            'dr_backups' => $rows,
        ];

        // DRF pagination: a non-null `next` means more pages exist upstream.
        if (! empty($response['next'])) {
            $live['truncated'] = true;
            $live['note'] = 'Servosity reports more DR backup accounts than shown (first page only).';
        }

        if ($rows === []) {
            $live['note'] = 'Servosity reports no DR backup accounts for this company. M365/mailbox and NAS protection are separate products and do not appear here — check account_counts.';
        }

        return $live;
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a tool call and prove it is Servosity-mapped.
     * The vendor-scope question ("which Servosity company is this?") is
     * answered ONLY by clients.servosity_company_id on the resolved row — tool
     * input picks which PSA client to ask about, never which company to read.
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

        if (empty($client->servosity_company_id)) {
            $error = "{$client->name} is not mapped to a Servosity company, so Servosity backup state cannot be read for this client. "
                .'Map the client in Settings > Servosity Company Mapping, or treat this client as not covered by Servosity.';

            // An unmapped client drops out of the sync loop; leftover flags stop
            // being refreshed forever. Refuse rather than serve rot.
            if ($this->enabledDeviceQuery($client)->exists()) {
                $error .= ' Note: this client still carries assets flagged Servosity-enabled from a previous mapping; they are ignored because they are no longer being refreshed.';
            }

            return ['error' => $error];
        }

        return $client;
    }

    /**
     * The one query seam the synced reads go through: this client's rows,
     * nothing else.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Asset>
     */
    private function enabledDeviceQuery(Client $client): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::where('client_id', $client->id)
            ->where('servosity_backup_enabled', true);
    }

    private function client(): ServosityClient
    {
        return app(ServosityClient::class);
    }

    // ── freshness (psa-47vxh idiom: oldest-known, any-unknown ⇒ stale) ─────────

    /**
     * @param  \Illuminate\Support\Collection<int, ?Carbon>  $syncStamps
     * @return array{data_as_of: ?string, data_stale: bool}
     */
    private function syncedFreshness(\Illuminate\Support\Collection $syncStamps): array
    {
        $oldest = null;
        $hasUnknown = $syncStamps->isEmpty();
        foreach ($syncStamps as $stamp) {
            if ($stamp === null) {
                $hasUnknown = true;

                continue;
            }
            if ($oldest === null || $stamp->lt($oldest)) {
                $oldest = $stamp;
            }
        }

        $freshness = [
            'data_as_of' => $oldest?->toIso8601ZuluString(),
            'data_stale' => $hasUnknown || $this->isStale($oldest),
        ];

        if ($freshness['data_stale']) {
            $freshness['staleness_note'] = 'The synced Servosity account counts are older than '.self::STALE_AFTER_HOURS
                .'h, missing, or partially unstamped — prefer the live section, and verify in the Servosity console if that is unavailable.';
        }

        return $freshness;
    }

    private function isStale(?Carbon $timestamp): bool
    {
        return $timestamp === null || $timestamp->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    /**
     * Bounded recursive leaf-sanitizer for vendor-shaped nested structures
     * (issue_counts). Mirrors HuntressReadOnlyToolset::sanitizeStructure —
     * string leaves are redacted and fenced; numbers/bools/null pass through;
     * depth and breadth are capped.
     */
    private function sanitizeStructure(string $label, mixed $value, int $maxDepth = 4, int $maxItems = 30): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitizeNullable($label, $value, 500);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            if ($maxDepth <= 0) {
                return '[truncated: max depth]';
            }

            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count++ >= $maxItems) {
                    $out['_truncated'] = true;
                    break;
                }
                $out[$k] = $this->sanitizeStructure($label, $v, $maxDepth - 1, $maxItems);
            }

            return $out;
        }

        return null;
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    private function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
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
}
