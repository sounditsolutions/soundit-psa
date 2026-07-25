<?php

namespace App\Services\Servosity;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\ServosityConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Servosity backup read tool for the staff MCP surface (psa-z30dv).
 *
 * Answers what the PSA can HONESTLY answer about a client's Servosity posture:
 * which devices have backup enabled and whether provisioning completed, what
 * account counts the last license sync recorded (with their own freshness),
 * and what Servosity reports live right now (account counts, issue counts, DR
 * backup accounts — reconciled back to each PSA device via upstream_check).
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM: job-level run history. The vendor's
 * official API DOES document job endpoints — GET backup-jobs/{backup_id}/,
 * backup-job-status/{backup_id}/{backup_date}/, dr-backups/{id}/latest-success/
 * and dr-backups/{id}/failures/ (official OpenAPI at
 * https://api.servosity.com/docs/?format=openapi, retrieved 2026-07-25) — but
 * their RESPONSE shapes cannot be trusted from the spec alone: the two
 * backup-job endpoints declare no response schema at all, and the custom-action
 * schemas contradict their own summaries (latest-success declares the DRBackup
 * ViewSet default while its summary says it returns a date). Per CLAUDE.md's
 * vendor-shape rules we do not project fields from a guessed shape; those reads
 * wait on a captured authenticated payload (follow-up bead on psa-z30dv). Until
 * then the payload's job_run_history block says so, rather than letting an
 * absence of failures read as verified-good (the psa-z30dv acceptance line).
 *
 * VENDOR SHAPE — from the official OpenAPI spec (the producer for a
 * closed-source vendor), cross-checked against the running production
 * consumers:
 *  - companies/summary-ng/ (definitions.CompanySummaryNg): account_counts and
 *    issue_counts are both REQUIRED objects of INTEGER values
 *    (additionalProperties: {type: integer}); the response envelope requires
 *    count + results (paths["/companies/summary-ng/"].get.responses.200).
 *    ServosityLicenseSyncService consumes results[].id + account_counts.
 *  - dr-backups/?company=N (paths["/dr-backups/"].get.responses.200): envelope
 *    requires count + results; rows are definitions.DRBackup — id, device_name
 *    (required), agent_session_id, state (vendor-defined string), product_type
 *    (enum DR_DESKTOP / DR_SERVER / DR_LINUX). ServosityDeploymentService
 *    consumes id / device_name / agent_session_id in production.
 * A response missing a REQUIRED container is SCHEMA DRIFT and is reported as an
 * explicit unknown/unavailable state — never as a clean zero/empty. That
 * includes ServosityClient's invalid-JSON-becomes-[] collapse, which arrives
 * here as a missing envelope and screams instead of reading as "no rows".
 *
 * DATA BOUNDARY: scope resolves from clients.servosity_company_id on the PSA
 * client row, never from tool input. Live reads are filtered to that company
 * id; synced reads are client_id-scoped. servosity_backup_password exists on
 * the asset row and MUST never appear in any payload. Vendor identifiers stay
 * minimized: the company id itself is not echoed back to the agent.
 */
class ServosityReadOnlyToolset
{
    private const CLIENT_TOOL_NAMES = [
        'servosity_get_backup_posture',
    ];

    /** Same threshold as the sibling backup/DNS read surfaces: >48h = a missed daily sync cycle. */
    private const STALE_AFTER_HOURS = 48;

    private const MAX_DEVICE_ROWS = 100;

    private const MAX_DR_BACKUP_ROWS = 100;

    /** Live results are cached briefly so an agent loop cannot fan out into unbounded vendor request volume. */
    private const LIVE_CACHE_SECONDS = 60;

    /** Failures are cached even shorter — loud unknowns, but no re-hammering a struggling API. */
    private const LIVE_FAILURE_CACHE_SECONDS = 30;

    /** Page-walk bound for the account-wide company summary (DRF pagination). */
    private const MAX_SUMMARY_PAGES = 20;

    private const LIVE_PAGE_SIZE = 100;

    /**
     * Vendor count-map keys are vendor-controlled text crossing into an agent
     * context; a real product key ("DRS", "Mailboxes") is short and plain, so
     * anything else is treated as schema drift rather than passed through.
     */
    private const COUNT_KEY_PATTERN = '/^[A-Za-z0-9 _.\-]{1,64}$/';

    private const MAX_COUNT_KEYS = 50;

    /**
     * Canonical psa-47vxh trio, scoped: the top-level envelope vouches ONLY for
     * the synced license plane. The per-device plane carries its own
     * (unverifiable) envelope — one plane's freshness must never bless another.
     */
    private const SYNCED_FRESHNESS_NOTE = 'data_as_of/data_stale cover ONLY synced_account_counts (the daily Servosity license sync): data_as_of is the OLDEST known sync stamp, and any missing, malformed, or future-dated stamp forces data_stale=true. They do NOT vouch for the per-device rows — see provisioning_freshness and each device\'s upstream_check. Live sections carry their own live_checked_at.';

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
                'name' => 'servosity_get_backup_posture',
                'description' => "Get a PSA client's Servosity backup posture: per-device enabled/provisioning state reconciled against a LIVE query of Servosity's DR backup accounts (each device's upstream_check: verified_live / upstream_missing / unverified / not_provisioned), synced backup account counts by product (with freshness), and live account + open-issue counts. IMPORTANT: Servosity job-level run history (did the last backup run, did it succeed) is NOT available through this integration — the response's job_run_history block explains why. Never infer 'backups are healthy' from an absence of failures here; issue counts and upstream_check are the strongest signals available, and job-level state must be verified in the Servosity console. Every section carries its own freshness (data_as_of/data_stale or live_checked_at); any status of unavailable/schema_drift/company_not_found means UNKNOWN — not zero, not passing.",
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
            'servosity_get_backup_posture' => $this->getBackupPosture($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getBackupPosture(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        $result = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
        ];

        $devices = $this->enabledDeviceQuery($client)
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->get(['id', 'hostname', 'name', 'asset_type', 'servosity_dr_backup_id']);

        // Live sections come first internally: per-device upstream_check
        // reconciles the local provisioning record against the live DR list.
        $live = $this->liveCompanyState($client);
        $drInfo = $this->liveDrBackups($client, $devices);

        // ── per-device provisioning posture (local records + live reconcile) ──
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
            'upstream_check' => $this->upstreamCheck($asset, $drInfo),
        ])->values()->all();
        $result['devices_truncated'] = $devices->count() > self::MAX_DEVICE_ROWS;

        // The enabled/provisioned flags are LOCAL provisioning records written
        // when backup was set up — no sync refreshes them, so this plane has no
        // observation stamp and must say so (canonical unverifiable trio),
        // instead of riding on the license plane's data_as_of.
        $result['provisioning_freshness'] = [
            'data_as_of' => null,
            'data_stale' => null,
            'freshness_note' => 'UNVERIFIABLE: enabled/provisioned flags are local PSA provisioning records with no sync stamp, so their current upstream truth cannot be aged. Each device row carries upstream_check, which reconciles it against the live DR backup list at live_checked_at — treat anything other than verified_live as unconfirmed.',
        ];
        $result['upstream_check_note'] = 'upstream_check per device: verified_live = its DR backup account exists in the live Servosity list; upstream_missing = the live list answered well-formed but does NOT contain it (the account may have been deleted upstream — investigate, the local record is out of date); unverified = the live list was unavailable, malformed, or truncated, so no claim is made; not_provisioned = no DR account is recorded locally.';

        if ($devices->isEmpty()) {
            $result['devices_note'] = "No PSA asset for {$client->name} has Servosity backup enabled. M365/mailbox and NAS protection do not require an enabled PSA asset, so check the account counts below before treating this client as not covered.";
        }

        // ── synced license (account-count) posture, with its own freshness ────
        $licenses = License::with('licenseType')
            ->where('client_id', $client->id)
            ->whereHas('licenseType', fn ($query) => $query->where('vendor', 'servosity'))
            ->get();

        $result['synced_account_counts'] = $licenses->map(function (License $license): array {
            $syncedAt = $this->trustworthyPastTime($license->synced_at);

            return [
                'product' => $license->licenseType?->name,
                'vendor_sku_id' => $license->licenseType?->vendor_sku_id,
                'quantity' => $license->quantity,
                'status' => $license->status,
                'synced_at' => $syncedAt?->toIso8601ZuluString(),
                'stale' => $this->isStale($syncedAt),
            ];
        })->values()->all();
        $result = array_merge($result, $this->syncedFreshness($licenses->pluck('synced_at')));

        // ── live Servosity state (validated envelopes; degrades LOUDLY) ───────
        $result['live'] = $live;
        $result['live_dr_backups'] = $drInfo['payload'];

        // ── the structural honesty block (the acceptance line of psa-z30dv) ───
        $result['job_run_history'] = [
            'available' => false,
            'note' => 'Servosity job-level run history (whether the last backup ran and whether it succeeded) is not available through this PSA integration yet. '
                .'The vendor API does document job endpoints (backup-jobs/{backup_id}/, backup-job-status/{backup_id}/{backup_date}/, dr-backups/{id}/latest-success/ and /failures/), '
                .'but their response shapes are not reliably documented in the official spec, so per this repo\'s vendor-shape rules they are not read until a captured real payload proves the shape. '
                .'This answer covers configuration/provisioning posture and account/issue counts only. Do NOT infer "backups are healthy" from the absence of failures here — verify job-level state in the Servosity console.',
        ];

        return $result;
    }

    // ── live sections ──────────────────────────────────────────────────────────

    /**
     * Live account/issue counts for this company from companies/summary-ng —
     * page-walked with per-page envelope validation, both count maps validated
     * against the documented CompanySummaryNg shape (REQUIRED integer maps).
     *
     * @return array<string, mixed>
     */
    private function liveCompanyState(Client $client): array
    {
        $fetch = $this->cachedLiveFetch('servosity_reads:companies', fn (): array => $this->fetchAllSummaryRows());

        if ($fetch['status'] !== 'ok') {
            return $this->liveFailurePayload($fetch, 'account/issue counts', 'the synced counts above may be out of date');
        }

        ['rows' => $rows, 'list_truncated' => $listTruncated] = $fetch['value'];

        $companyId = (int) $client->servosity_company_id;
        $company = collect($rows)->first(
            fn ($row): bool => is_array($row) && (int) ($row['id'] ?? 0) === $companyId,
        );

        if ($company === null) {
            $note = "Servosity's live company list does not contain this client's mapped company. The mapping may be stale or the company removed — verify in the Servosity console. This is NOT an all-clear.";
            if ($listTruncated) {
                $note .= ' The company list was truncated at '.self::MAX_SUMMARY_PAGES.' pages, so absence here is not conclusive.';
            }

            return [
                'status' => 'company_not_found',
                'live_checked_at' => $fetch['fetched_at'],
                'note' => $note,
            ];
        }

        $accounts = $this->validatedIntMap($company['account_counts'] ?? null);
        $issues = $this->validatedIntMap($company['issue_counts'] ?? null);

        return [
            // Both maps are REQUIRED in the documented shape — a drifted one
            // downgrades the whole section, never silently reads as zero.
            'status' => ($accounts['map'] !== null && $issues['map'] !== null) ? 'ok' : 'schema_drift',
            'live_checked_at' => $fetch['fetched_at'],
            'account_counts' => $accounts['map'],
            'account_counts_note' => $accounts['map'] !== null
                ? 'Live backup account counts per product key (documented shape: an integer per product).'
                : 'account_counts is REQUIRED in the documented response but was '.$accounts['why'].' — live account coverage is UNKNOWN, not zero.',
            'issue_counts' => $issues['map'],
            'issue_counts_note' => $issues['map'] !== null
                ? 'Non-zero values mean Servosity is flagging problems for this company; an all-zero map means Servosity reports no open issues — which is a weaker claim than "all backups succeeded".'
                : 'issue_counts is REQUIRED in the documented response but was '.$issues['why'].' — issue state is UNKNOWN, not zero.',
        ];
    }

    /**
     * Live DR backup accounts for this company, matched back to PSA assets by
     * hostname. Proves which per-device backup accounts EXIST upstream and
     * whether each is linked to an agent; the returned id set feeds each
     * device's upstream_check.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $enabledDevices
     * @return array{payload: array<string, mixed>, ids: ?array<int, true>, truncated: bool}
     */
    private function liveDrBackups(Client $client, \Illuminate\Support\Collection $enabledDevices): array
    {
        $companyId = (int) $client->servosity_company_id;
        $fetch = $this->cachedLiveFetch("servosity_reads:dr_backups:{$companyId}", function () use ($companyId): array {
            $response = $this->client()->get('dr-backups/', ['company' => $companyId, 'page_size' => self::LIVE_PAGE_SIZE]);
            $this->assertDrfEnvelope($response, 'dr-backups/');

            return $response;
        });

        if ($fetch['status'] !== 'ok') {
            return [
                'payload' => $this->liveFailurePayload($fetch, 'DR backup accounts', 'per-device provisioning could not be verified upstream (every device\'s upstream_check reads unverified)'),
                'ids' => null,
                'truncated' => false,
            ];
        }

        $response = $fetch['value'];
        $validRows = array_values(array_filter($response['results'], 'is_array'));

        $ids = [];
        foreach ($validRows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $ids[(int) $row['id']] = true;
            }
        }

        // DRF pagination: a non-null `next` or a total count beyond this page
        // means more accounts exist upstream than we saw.
        $listTruncated = ! empty($response['next']) || $response['count'] > count($validRows);

        $assetsByHostname = $enabledDevices->keyBy(
            fn (Asset $asset): string => mb_strtolower((string) $asset->hostname),
        );

        $rows = collect($validRows)
            ->take(self::MAX_DR_BACKUP_ROWS)
            ->map(function (array $row) use ($assetsByHostname): array {
                $deviceName = is_scalar($row['device_name'] ?? null) ? (string) $row['device_name'] : '';
                $matched = $deviceName !== '' ? $assetsByHostname->get(mb_strtolower($deviceName)) : null;
                $productType = $row['product_type'] ?? null;

                return [
                    'dr_backup_id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
                    // Vendor free text at this trust boundary — travels fenced.
                    'device_name' => $this->textSanitizer->sanitizeNullable('Servosity device name', $deviceName, 200),
                    'agent_linked' => ! empty($row['agent_session_id']),
                    // Documented on the DRBackup list serializer (official spec);
                    // product_type is enum-checked, state has no documented
                    // vocabulary so it travels fenced like any vendor string.
                    'product_type' => in_array($productType, ['DR_DESKTOP', 'DR_SERVER', 'DR_LINUX'], true)
                        ? $productType
                        : $this->textSanitizer->sanitizeNullable('Servosity product type', is_scalar($productType) ? (string) $productType : null, 50),
                    'state' => $this->textSanitizer->sanitizeNullable('Servosity DR state', is_scalar($row['state'] ?? null) ? (string) $row['state'] : null, 100),
                    'matched_asset_id' => $matched?->id,
                    'matched_hostname' => $matched?->hostname,
                ];
            })->values()->all();

        $baseNote = 'Rows come from the documented dr-backups list. state is vendor-defined text — relay it to a human rather than interpreting it; treat each PSA device\'s upstream_check as the provisioning truth.';
        $payload = [
            'status' => 'ok',
            'live_checked_at' => $fetch['fetched_at'],
            'count' => count($validRows),
            'dr_backups' => $rows,
        ];

        if ($listTruncated) {
            $payload['truncated'] = true;
            $payload['note'] = 'Servosity reports more DR backup accounts than shown (first page only). '.$baseNote;
        } elseif ($rows === []) {
            $payload['note'] = 'Servosity reports no DR backup accounts for this company (well-formed response — a verified zero, not a failed read). M365/mailbox and NAS protection are separate products and do not appear here — check account_counts.';
        } else {
            $payload['note'] = $baseNote;
        }

        return ['payload' => $payload, 'ids' => $ids, 'truncated' => $listTruncated];
    }

    /**
     * Reconcile one device's local DR-account record against the live list.
     *
     * @param  array{payload: array<string, mixed>, ids: ?array<int, true>, truncated: bool}  $drInfo
     */
    private function upstreamCheck(Asset $asset, array $drInfo): string
    {
        if ($asset->servosity_dr_backup_id === null) {
            return 'not_provisioned';
        }
        if ($drInfo['ids'] === null) {
            return 'unverified'; // live list unavailable or malformed — no claim
        }
        if (isset($drInfo['ids'][(int) $asset->servosity_dr_backup_id])) {
            return 'verified_live';
        }

        // Absent from a TRUNCATED list proves nothing; absent from a complete
        // well-formed list is a real contradiction worth investigating.
        return $drInfo['truncated'] ? 'unverified' : 'upstream_missing';
    }

    // ── live fetch plumbing (validation, caching, redaction) ───────────────────

    /**
     * Walk the account-wide company summary pages with per-page envelope
     * validation. Bounded at MAX_SUMMARY_PAGES; the bound is reported, never
     * silent.
     *
     * @return array{rows: array<int, array<string, mixed>>, list_truncated: bool}
     */
    private function fetchAllSummaryRows(): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_SUMMARY_PAGES; $page++) {
            $response = $this->client()->get('companies/summary-ng/', ['page' => $page, 'page_size' => self::LIVE_PAGE_SIZE]);
            $this->assertDrfEnvelope($response, 'companies/summary-ng/');

            $rows = array_merge($rows, array_values(array_filter($response['results'], 'is_array')));

            if (empty($response['next'])) {
                return ['rows' => $rows, 'list_truncated' => false];
            }
        }

        return ['rows' => $rows, 'list_truncated' => true];
    }

    /**
     * The documented DRF envelope requires BOTH count (integer) and results
     * (array) — official OpenAPI responses.200 for both endpoints we read.
     * ServosityClient collapses invalid JSON to [], which arrives here as a
     * missing envelope: that must scream as drift, never read as zero rows.
     */
    private function assertDrfEnvelope(mixed $response, string $endpoint): void
    {
        if (! is_array($response) || ! is_int($response['count'] ?? null) || ! is_array($response['results'] ?? null)) {
            throw new ServosityShapeDriftException("Servosity {$endpoint} response did not match the documented envelope (count + results required).");
        }
    }

    /**
     * Fetch-through cache for live vendor reads: results are held briefly so an
     * agent loop cannot fan out into unbounded Servosity request volume, and
     * failures are held even shorter (still loud, no re-hammering). fetched_at
     * is stamped at real fetch time, so a cache-served answer never claims to
     * be fresher than it is. Logs are redacted: exception class + code only —
     * vendor messages can embed the configured base URL or response text.
     *
     * @return array{status: 'ok'|'failed'|'schema_drift', value?: mixed, fetched_at: string}
     */
    private function cachedLiveFetch(string $key, \Closure $fetch): array
    {
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached;
        }

        try {
            $result = ['status' => 'ok', 'value' => $fetch(), 'fetched_at' => now()->toIso8601ZuluString()];
            Cache::put($key, $result, self::LIVE_CACHE_SECONDS);
        } catch (ServosityShapeDriftException $e) {
            // Safe by construction: names the endpoint, never response content.
            Log::warning('[Servosity reads] response shape drift', ['detail' => $e->getMessage()]);
            $result = ['status' => 'schema_drift', 'fetched_at' => now()->toIso8601ZuluString()];
            Cache::put($key, $result, self::LIVE_FAILURE_CACHE_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('[Servosity reads] live fetch failed', ['cache_key' => $key, 'exception' => $e::class, 'code' => $e->getCode()]);
            $result = ['status' => 'failed', 'fetched_at' => now()->toIso8601ZuluString()];
            Cache::put($key, $result, self::LIVE_FAILURE_CACHE_SECONDS);
        }

        return $result;
    }

    /**
     * The degraded-read payload for a live section. Generic by design: raw
     * vendor error detail (URLs, response text) must not cross the agent
     * boundary — diagnostics live in the application log.
     *
     * @param  array{status: string, fetched_at: string}  $fetch
     * @return array<string, mixed>
     */
    private function liveFailurePayload(array $fetch, string $what, string $consequence): array
    {
        if ($fetch['status'] === 'schema_drift') {
            return [
                'status' => 'schema_drift',
                'live_checked_at' => $fetch['fetched_at'],
                'note' => "Live {$what} are UNKNOWN — Servosity answered with a response that does not match its documented shape (possible API change; details in the application log). Do not read this as zero/none; {$consequence}. Verify in the Servosity console.",
            ];
        }

        return [
            'status' => 'unavailable',
            'live_checked_at' => $fetch['fetched_at'],
            'note' => "Live {$what} are UNAVAILABLE — the Servosity API could not be queried (network or API error; details in the application log). {$consequence}. Verify in the Servosity console.",
        ];
    }

    /**
     * Validate a vendor count map against the documented shape: CompanySummaryNg
     * declares account_counts/issue_counts as REQUIRED objects of INTEGER values
     * (official OpenAPI, additionalProperties: {type: integer}). Any violation
     * returns map=null + why — a drifted map screams, it is never silently
     * projected or silently trimmed. The why string never echoes content that
     * failed validation.
     *
     * @return array{map: ?array<string, int>, why: ?string}
     */
    private function validatedIntMap(mixed $value): array
    {
        if (! is_array($value)) {
            return ['map' => null, 'why' => $value === null ? 'missing from the response' : 'not an object of counts'];
        }
        if (count($value) > self::MAX_COUNT_KEYS) {
            return ['map' => null, 'why' => 'implausibly large ('.count($value).' keys)'];
        }

        $out = [];
        foreach ($value as $key => $count) {
            if (! is_string($key) || preg_match(self::COUNT_KEY_PATTERN, $key) !== 1) {
                return ['map' => null, 'why' => 'carrying a key that fails validation'];
            }
            if (! is_int($count)) {
                // $key passed the conservative pattern above, so it is safe to name.
                return ['map' => null, 'why' => "carrying a non-integer value (key \"{$key}\")"];
            }
            $out[$key] = $count;
        }

        return ['map' => $out, 'why' => null];
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

    // ── freshness (canonical psa-47vxh idiom) ──────────────────────────────────

    /**
     * The synced-license-plane envelope: data_as_of is the OLDEST trustworthy
     * sync stamp — the freshest the whole plane can honestly claim — and
     * data_stale is true when that is beyond the threshold OR any row's stamp
     * is unknown (missing or future-dated). freshness_note is always present.
     *
     * @param  \Illuminate\Support\Collection<int, ?Carbon>  $syncStamps
     * @return array{data_as_of: ?string, data_stale: bool, freshness_note: string}
     */
    private function syncedFreshness(\Illuminate\Support\Collection $syncStamps): array
    {
        $trusted = $syncStamps->map(fn (?Carbon $stamp): ?Carbon => $this->trustworthyPastTime($stamp));
        $hasUnknown = $trusted->isEmpty() || $trusted->contains(null);

        $oldest = null;
        foreach ($trusted as $stamp) {
            if ($stamp !== null && ($oldest === null || $stamp->lt($oldest))) {
                $oldest = $stamp;
            }
        }

        return [
            'data_as_of' => $oldest?->toIso8601ZuluString(),
            'data_stale' => $hasUnknown || $this->isStale($oldest),
            'freshness_note' => self::SYNCED_FRESHNESS_NOTE,
        ];
    }

    /**
     * A DB sync stamp is trustworthy only when it is a real PAST time — a
     * future-dated stamp (writer bug or clock skew) must read as unknown and
     * therefore stale, never fresh (canonical psa-47vxh rule;
     * UnifiReadOnlyToolset::parseTimestamp is the string-input sibling).
     */
    private function trustworthyPastTime(?Carbon $timestamp): ?Carbon
    {
        return ($timestamp === null || $timestamp->gt(now())) ? null : $timestamp;
    }

    private function isStale(?Carbon $timestamp): bool
    {
        return $timestamp === null || $timestamp->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

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
