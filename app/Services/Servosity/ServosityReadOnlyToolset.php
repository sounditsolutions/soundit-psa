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
 * JOB-RUN HISTORY — NOT READ AT ALL (psa-z30dv R5, superseding the R4
 * "unverified observation" posture): job_run_history is a CONSTANT
 * status=unverifiable block and GET backup-jobs/{backup_id}/ is never
 * queried. The endpoint is documented in the official OpenAPI
 * (https://api.servosity.com/docs/?format=openapi, retrieved 2026-07-26) but
 * its 200 response declares NO schema, so no reading of it can be proven
 * against a producer shape — and R4's "count of the apparent DRF list
 * envelope, labelled unverified" was still a shape-derived claim from an
 * unproven field (psa-z30dv.13). An unproven seam publishes NO count, NO
 * zero, and NO outcome; it says UNKNOWN (seam 3 in ServosityShapes). The
 * endpoint stays unread until a captured authenticated payload proves the
 * shape (follow-up bead psa-bh1i4). The remaining job endpoints stay unread
 * for concrete, non-premise reasons: backup-job-status/{backup_id}/
 * {backup_date}/ needs a backup_date that only a job-run record's contents
 * could supply; dr-backups/{id}/latest-success/ declares the DRBackup
 * ViewSet default while its summary says it returns a date;
 * dr-backups/{id}/failures/ declares a single SPXBackupFailure object for a
 * plural list action. Contradictory declarations are not a shape.
 *
 * VENDOR SHAPE — from the official OpenAPI spec (the producer for a
 * closed-source vendor), cross-checked against the running production
 * consumers. All live reads use ServosityClient::getJson(), which preserves
 * JSON container identity (objects → stdClass, arrays → PHP arrays,
 * psa-z30dv.7): a documented object arriving as `[]`, or documented `results`
 * arriving as `{}`, is DRIFT here — the assoc-array view would collapse both
 * into a clean empty and mint a false verified zero. Invalid JSON throws at
 * the client seam (ServosityShapeDriftException → schema_drift), never
 * collapses to [].
 *  - companies/summary-ng/ (definitions.CompanySummaryNg): account_counts and
 *    issue_counts are both REQUIRED objects of INTEGER values
 *    (additionalProperties: {type: integer}); the response envelope requires
 *    count + results (paths["/companies/summary-ng/"].get.responses.200).
 *    ServosityLicenseSyncService consumes results[].id + account_counts.
 *  - dr-backups/?company=N (paths["/dr-backups/"].get.responses.200): envelope
 *    requires count + results; rows are definitions.DRBackup, whose REQUIRED
 *    set is company (string, format uri), agent_session (AgentSession
 *    object), shadowprotect_keys (array of ShadowProtectKey), device_name
 *    (string, minLength 1), product_type (enum DR_DESKTOP / DR_SERVER /
 *    DR_LINUX) and encryption_key (DRBackupEncryptionKeyShort object), plus
 *    read-only integer id and read-only string agent_session_id / state.
 *    assertDrBackupRows() enforces every required field's documented TYPE
 *    (not just presence) down to the documented REQUIRED nested fields
 *    (AgentSession.agent_session_id; ShadowProtectKey.product_key +
 *    product_type), requires the consumed read-only strings
 *    (agent_session_id, state) to be string-or-null, and additionally proves
 *    each row's company field is a well-formed URI resolving to the
 *    REQUESTED company id — an untrusted response must not verify local
 *    state with rows that belong to (or claim) another tenant.
 *    ServosityDeploymentService consumes id / device_name / agent_session_id
 *    in production.
 *  - DRF pagination (both list endpoints): `next` is documented as a URI
 *    string or null (format uri, x-nullable). Completeness — "was that the
 *    whole list?" — is itself a truth claim (it decides verified zeros,
 *    company_not_found, upstream_missing and truncation caveats), so the
 *    cursor is proven by ServosityShapes::provenNextUrl() (enforced inside
 *    every assertDrfEnvelope()) and consumed ONLY through it. An
 *    undocumented value is drift for the whole section in EVERY direction:
 *    false/0/"" must not read as "no next page" (psa-z30dv.18:
 *    {"count":0,"results":[],"next":0} minted a verified zero +
 *    upstream_missing), array/object/non-URI junk must not read as mere
 *    truncation, and a syntactically valid URI that does not continue THIS
 *    request — the configured origin + this exact endpoint path, from
 *    ServosityClient::resolvedRequestUrl() — must not steer or complete the
 *    walk (psa-z30dv.22: an unrelated-origin cursor minted
 *    company_not_found from a foreign-steered list).
 * A response missing a REQUIRED container, or carrying a wrong-typed required
 * field, is SCHEMA DRIFT and is reported as an explicit unknown/unavailable
 * state — never as a clean zero/empty. A schema_drift section publishes NO
 * live_checked_at (one dialect rule, psa-z30dv R7): an uninterpretable answer
 * is not an observation, so no freshness stamp may accompany it — while
 * unavailable keeps its attempt stamp, which claims nothing about upstream
 * state.
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
    private const SYNCED_FRESHNESS_NOTE = 'data_as_of/data_stale cover ONLY synced_account_counts (the daily Servosity license sync): data_as_of is the OLDEST known sync stamp, and any missing, malformed, or future-dated stamp forces data_stale=true. They do NOT vouch for the per-device rows — see provisioning_freshness and each device\'s upstream_check. Live sections carry their own live_checked_at when their answer was interpretable; a schema_drift section publishes no timestamp.';

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
                'description' => "Get a PSA client's Servosity backup posture: per-device enabled/provisioning state reconciled against a LIVE query of Servosity's DR backup accounts (each device's upstream_check: verified_live / upstream_missing / unverified / not_provisioned), synced backup account counts by product (with freshness), and live account + open-issue counts. IMPORTANT: job-run state (did the last backup run, did it succeed) CANNOT be answered by this tool — the vendor documents its job endpoint but NOT the response schema, so job_run_history is always status=unverifiable with no run count or outcome; verify run outcomes in the Servosity console. Never infer 'backups are healthy' from anything in this answer. Every section carries its own freshness (data_as_of/data_stale, or live_checked_at for live sections) EXCEPT schema_drift, which publishes NO timestamp — an uninterpretable answer is not an observation. Any status of unavailable/schema_drift/unverifiable/company_not_found means UNKNOWN — not zero, not passing.",
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
        // Vendor identifiers stay minimized (psa-z30dv.6): the raw DR backup
        // account id is reconciliation plumbing, not something any documented
        // agent workflow needs — it never crosses into the payload.
        $result['devices'] = $devices->take(self::MAX_DEVICE_ROWS)->map(fn (Asset $asset): array => [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'asset_name' => $asset->name,
            'asset_type' => $asset->asset_type,
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

        // ── job-run state: constant, honest UNKNOWN (no proven producer shape) ─
        $result['job_run_history'] = $this->jobRunHistory();

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
            fn ($row): bool => $row instanceof \stdClass && (int) ($row->id ?? 0) === $companyId,
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

        $accounts = $this->validatedIntMap($company->account_counts ?? null);
        $issues = $this->validatedIntMap($company->issue_counts ?? null);
        // Both maps are REQUIRED in the documented shape — a drifted one
        // downgrades the whole section, never silently reads as zero.
        $mapsProven = $accounts['map'] !== null && $issues['map'] !== null;

        $section = ['status' => $mapsProven ? 'ok' : 'schema_drift'];
        // ONE dialect rule (psa-z30dv R7, .22): a schema_drift section
        // publishes NO live_checked_at — even here, where the envelope was
        // proven and only a count map drifted. A freshness stamp on drift
        // reads as a timestamped completed observation.
        if ($mapsProven) {
            $section['live_checked_at'] = $fetch['fetched_at'];
        }

        return $section + [
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
     * device's upstream_check. The raw account ids are internal plumbing
     * only — never in the payload.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $enabledDevices
     * @return array{payload: array<string, mixed>, ids: ?array<int, true>, truncated: bool}
     */
    private function liveDrBackups(Client $client, \Illuminate\Support\Collection $enabledDevices): array
    {
        $companyId = (int) $client->servosity_company_id;
        // Cache key versioned: the cached value's shape changed in R7 (the
        // response now travels with its fetch-time completeness flag) and
        // file-cache entries can survive a deploy by up to the TTL.
        $fetch = $this->cachedLiveFetch("servosity_reads:dr_backups:v2:{$companyId}", function () use ($companyId): array {
            $client = $this->client();
            $requestUrl = $client->resolvedRequestUrl('dr-backups/');
            $response = $client->getJson('dr-backups/', ['company' => $companyId, 'page_size' => self::LIVE_PAGE_SIZE]);
            ServosityShapes::assertDrfEnvelope($response, 'dr-backups/', $requestUrl);
            $this->assertDrBackupRows($response->results, $companyId);

            // DRF pagination: completeness is a truth claim (it decides the
            // "verified zero" copy and upstream_missing vs unverified), so
            // it is decided HERE, at fetch time, from the just-proven cursor
            // — origin/path-bound to the request that produced it (psa-z30dv
            // R7) — plus the proven integer count. Consumers of the cached
            // value read this flag; the raw `next` field is never re-read.
            return [
                'response' => $response,
                'list_truncated' => ServosityShapes::provenNextUrl($response, 'dr-backups/', $requestUrl) !== null
                    || $response->count > count($response->results),
            ];
        });

        if ($fetch['status'] !== 'ok') {
            return [
                'payload' => $this->liveFailurePayload($fetch, 'DR backup accounts', 'per-device provisioning could not be verified upstream (every device\'s upstream_check reads unverified)'),
                'ids' => null,
                'truncated' => false,
            ];
        }

        ['response' => $response, 'list_truncated' => $listTruncated] = $fetch['value'];
        $validRows = $response->results;

        $ids = [];
        foreach ($validRows as $row) {
            $ids[$row->id] = true;
        }

        $assetsByHostname = $enabledDevices->keyBy(
            fn (Asset $asset): string => mb_strtolower((string) $asset->hostname),
        );

        $rows = collect($validRows)
            ->take(self::MAX_DR_BACKUP_ROWS)
            ->map(function (\stdClass $row) use ($assetsByHostname): array {
                // Every row passed assertDrBackupRows: id is an int,
                // device_name a non-empty string, product_type in the
                // documented enum, and the company URI is proven in-scope.
                $matched = $assetsByHostname->get(mb_strtolower($row->device_name));

                return [
                    // Vendor free text at this trust boundary — travels fenced.
                    // The raw DR account id is deliberately absent (internal
                    // reconciliation plumbing only, psa-z30dv.6).
                    'device_name' => $this->textSanitizer->sanitizeNullable('Servosity device name', $row->device_name, 200),
                    // agent_session_id is a documented read-only string; the
                    // required agent_session OBJECT is credential-adjacent and
                    // never read beyond its validated shape. Validation
                    // guarantees string-or-null here: null/absent is "no
                    // value" — no linkage claim.
                    'agent_linked' => is_string($row->agent_session_id ?? null) && $row->agent_session_id !== '',
                    // Enum-validated — projects verbatim. state is a documented
                    // read-only string with no documented vocabulary, so it
                    // travels fenced like any vendor string (display plane
                    // only; validation guarantees string-or-null).
                    'product_type' => $row->product_type,
                    'state' => $this->textSanitizer->sanitizeNullable('Servosity DR state', $row->state ?? null, 100),
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

    /**
     * Job-run state cannot be verified through this integration (psa-z30dv
     * R5, seam 3 in ServosityShapes): the backup-jobs endpoint's 200
     * response declares NO schema in the official OpenAPI, so any reading of
     * it — including R4's "unverified count" of its apparent list envelope —
     * is a claim derived from an unproven shape. Until psa-bh1i4 captures
     * and cites a real authenticated payload, the endpoint is not queried
     * and this block publishes no count, no zero, and no outcome: only the
     * explicit unknown, identical for every account. The block stays in the
     * payload (rather than disappearing) so an agent asking "did backups
     * run" reads the blind spot instead of inferring one.
     *
     * @return array<string, mixed>
     */
    private function jobRunHistory(): array
    {
        return [
            'status' => 'unverifiable',
            'note' => 'Job-run state (did a backup run, did it succeed) is UNVERIFIABLE through this integration: Servosity\'s official OpenAPI documents the backup-jobs endpoint but declares NO schema for its response, so no run count, zero, or outcome can be proven — and none is published. The endpoint is not queried at all (a proven read is tracked as psa-bh1i4). Treat job-run state for EVERY account as UNKNOWN and verify run outcomes in the Servosity console. Do NOT infer "backups are healthy" from anything in this answer.',
        ];
    }

    // ── live fetch plumbing (validation, caching, redaction) ───────────────────

    /**
     * Walk the account-wide company summary pages with per-page envelope AND
     * row validation. Bounded at MAX_SUMMARY_PAGES; the bound is reported,
     * never silent. A malformed row is drift for the whole read — filtering
     * bad rows out would turn malformed evidence into apparent truth, and a
     * dropped row containing OUR company would read as company_not_found.
     *
     * @return array{rows: array<int, array<string, mixed>>, list_truncated: bool}
     */
    private function fetchAllSummaryRows(): array
    {
        $client = $this->client();
        $requestUrl = $client->resolvedRequestUrl('companies/summary-ng/');
        $rows = [];
        for ($page = 1; $page <= self::MAX_SUMMARY_PAGES; $page++) {
            $response = $client->getJson('companies/summary-ng/', ['page' => $page, 'page_size' => self::LIVE_PAGE_SIZE]);
            ServosityShapes::assertDrfEnvelope($response, 'companies/summary-ng/', $requestUrl);

            foreach ($response->results as $row) {
                if (! $row instanceof \stdClass || ! is_int($row->id ?? null)) {
                    throw new ServosityShapeDriftException('Servosity companies/summary-ng/ returned a row that is not an object with an integer id (documented CompanySummaryNg shape).');
                }
            }

            $rows = array_merge($rows, array_values($response->results));

            // Completeness is read ONLY through the shared pagination proof
            // (an undocumented cursor — wrong type or one that does not
            // continue this request — threw at the envelope proof above): a
            // proven null is the documented end of the list, a proven URI
            // means another page exists.
            if (ServosityShapes::provenNextUrl($response, 'companies/summary-ng/', $requestUrl) === null) {
                return ['rows' => $rows, 'list_truncated' => false];
            }
        }

        return ['rows' => $rows, 'list_truncated' => true];
    }

    /**
     * The documented product_type vocabulary — definitions.DRBackup.product_type
     * enum in the official OpenAPI (retrieved 2026-07-26).
     */
    private const DR_PRODUCT_TYPES = ['DR_DESKTOP', 'DR_SERVER', 'DR_LINUX'];

    /**
     * The documented ShadowProtectKey product_type vocabulary —
     * definitions.ShadowProtectKey.product_type enum in the official OpenAPI
     * (retrieved 2026-07-26). Deliberately a DIFFERENT enum from the
     * DRBackup one above: a DR_SERVER inside a ShadowProtectKey is drift.
     */
    private const SHADOWPROTECT_PRODUCT_TYPES = ['Desktop', 'Server', 'SPX_LINUX'];

    /**
     * Every consumed DR row must match the documented DRBackup shape —
     * definitions.DRBackup in the official OpenAPI (retrieved 2026-07-26)
     * REQUIRES company (string, format uri), agent_session (AgentSession
     * OBJECT), shadowprotect_keys (ARRAY of ShadowProtectKey), device_name
     * (string, minLength 1), product_type (enum DR_DESKTOP/DR_SERVER/DR_LINUX)
     * and encryption_key (DRBackupEncryptionKeyShort OBJECT), and list rows
     * carry the read-only integer id we reconcile with. Each required field is
     * checked against its documented TYPE, not just for presence (psa-z30dv.9):
     * a null/string agent_session or an integer company is drift exactly like
     * a missing key. Rows arrive through the identity-preserving decode, so
     * "object" means stdClass and "array" means a PHP array — the two cannot
     * be confused. A row failing any check is drift for the WHOLE read: a
     * fragment like {"id": 501} must never mark a device verified_live on the
     * id alone, and silently dropping it would be filtering malformed evidence
     * into apparent truth (psa-z30dv.5).
     *
     * SCOPE PROOF (psa-z30dv.10): every row's REQUIRED company URI must
     * resolve to the company id this read requested. The response is untrusted
     * input; without this check a structurally plausible foreign-company row
     * could be projected (leaking another tenant's device name into this
     * client's answer) and could mark a local asset verified_live on evidence
     * that belongs to someone else. A row outside the requested scope is drift
     * for the whole read — not filtered, not trusted.
     *
     * NESTED SHAPES (psa-z30dv.14): the credential-shaped required fields are
     * proven to their documented REQUIRED depth, by type only — their values
     * are never read again, logged, or projected. agent_session must be an
     * AgentSession object carrying its REQUIRED non-empty string
     * agent_session_id (minLength 1); every shadowprotect_keys entry must be
     * a ShadowProtectKey object carrying its REQUIRED non-empty product_key
     * string and enum product_type. encryption_key stays a bare object check
     * because definitions.DRBackupEncryptionKeyShort declares NO required
     * properties — `instanceof stdClass` IS its complete documented
     * validation. Documented length CEILINGS (maxLength) are not treated as
     * drift; the projection sanitizer bounds anything that travels.
     *
     * CONSUMED READ-ONLY FIELDS (psa-z30dv.14): agent_session_id and state
     * are documented read-only strings this read projects (as agent_linked /
     * fenced display text). JSON null — like absence — is the serializer's
     * "no value" and projects as unlinked/null; a PRESENT non-null value of
     * the wrong documented type is drift, never silently normalized to null.
     */
    private function assertDrBackupRows(array $results, int $expectedCompanyId): void
    {
        foreach ($results as $row) {
            if (! $row instanceof \stdClass) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row that is not an object (documented DRBackup shape).');
            }
            if (! is_int($row->id ?? null)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row without an integer id (documented DRBackup shape).');
            }
            if (! is_string($row->device_name ?? null) || $row->device_name === '') {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row without a non-empty string device_name (REQUIRED, minLength 1 in the documented DRBackup shape).');
            }
            if (! in_array($row->product_type ?? null, self::DR_PRODUCT_TYPES, true)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose product_type is outside the documented enum (REQUIRED in the documented DRBackup shape).');
            }
            if (! is_string($row->company ?? null)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose REQUIRED company field is not the documented URI string (documented DRBackup shape).');
            }
            if (! $this->companyUriMatches($row->company, $expectedCompanyId)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose company field is not a well-formed URI resolving to the requested company — out-of-scope or unprovable rows cannot be used as evidence.');
            }
            if (! ($row->agent_session ?? null) instanceof \stdClass) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose REQUIRED agent_session is not the documented AgentSession object.');
            }
            if (! is_string($row->agent_session->agent_session_id ?? null) || $row->agent_session->agent_session_id === '') {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose agent_session is missing its REQUIRED non-empty string agent_session_id (documented AgentSession shape).');
            }
            if (! is_array($row->shadowprotect_keys ?? null)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose REQUIRED shadowprotect_keys is not the documented array.');
            }
            foreach ($row->shadowprotect_keys as $shadowProtectKey) {
                if (! $shadowProtectKey instanceof \stdClass) {
                    throw new ServosityShapeDriftException('Servosity dr-backups/ returned a shadowprotect_keys entry that is not the documented ShadowProtectKey object.');
                }
                if (! is_string($shadowProtectKey->product_key ?? null) || $shadowProtectKey->product_key === '') {
                    throw new ServosityShapeDriftException('Servosity dr-backups/ returned a ShadowProtectKey without its REQUIRED non-empty string product_key.');
                }
                if (! in_array($shadowProtectKey->product_type ?? null, self::SHADOWPROTECT_PRODUCT_TYPES, true)) {
                    throw new ServosityShapeDriftException('Servosity dr-backups/ returned a ShadowProtectKey whose product_type is outside the documented ShadowProtectKey enum.');
                }
            }
            if (! ($row->encryption_key ?? null) instanceof \stdClass) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose REQUIRED encryption_key is not the documented object (DRBackupEncryptionKeyShort declares no required properties, so the object check is its complete documented validation).');
            }
            $agentSessionId = $row->agent_session_id ?? null;
            if ($agentSessionId !== null && ! is_string($agentSessionId)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose agent_session_id is neither the documented read-only string nor null.');
            }
            $state = $row->state ?? null;
            if ($state !== null && ! is_string($state)) {
                throw new ServosityShapeDriftException('Servosity dr-backups/ returned a row whose state is neither the documented read-only string nor null.');
            }
        }
    }

    /**
     * Prove a DRBackup row's company field is a WELL-FORMED URI (documented
     * format: uri — in practice the DRF hyperlink `.../companies/{id}/`)
     * whose path denotes exactly the requested company. A bare suffix match
     * is not enough (psa-z30dv.14): a non-URI string that happens to end in
     * `/companies/{id}/` cannot prove scope, so the value must parse as an
     * absolute http(s) URL first, and the numeric tail must equal the
     * requested id EXACTLY as digits (no zero-padding aliases). Unprovable
     * is out, not in. The host is deliberately NOT pinned to the configured
     * base URL: this check proves which tenant the row CLAIMS to belong to
     * (scope), not channel authenticity — the transport already carries
     * that, and a forged response could forge the expected host anyway.
     */
    private function companyUriMatches(string $companyUri, int $expectedCompanyId): bool
    {
        if (filter_var($companyUri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        if (! in_array(strtolower((string) parse_url($companyUri, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            return false;
        }
        $path = parse_url($companyUri, PHP_URL_PATH);

        return is_string($path)
            && preg_match('#/companies/(\d+)/?$#', $path, $matches) === 1
            && $matches[1] === (string) $expectedCompanyId;
    }

    /**
     * Fetch-through cache for live vendor reads: results are held briefly so an
     * agent loop cannot fan out into unbounded Servosity request volume, and
     * failures are held even shorter (still loud, no re-hammering). fetched_at
     * is stamped at real fetch time, so a cache-served answer never claims to
     * be fresher than it is — and a schema_drift record carries NO fetched_at
     * at all (psa-z30dv R7: drift is not an observation, so there is no
     * freshness stamp to publish). Logs are redacted: exception class + code
     * only — vendor messages can embed the configured base URL or response
     * text.
     *
     * @return array{status: 'ok'|'failed'|'schema_drift', value?: mixed, fetched_at?: string}
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
            // No fetched_at on a drift record (psa-z30dv R7, .22): drift
            // publishes no freshness stamp, and the payload seam cannot leak
            // what the cache entry does not hold.
            $result = ['status' => 'schema_drift'];
            Cache::put($key, $result, self::LIVE_FAILURE_CACHE_SECONDS);
        } catch (\Throwable $e) {
            // Identifier minimization (psa-z30dv.10): the cache key embeds
            // vendor ids (company / DR account) — log the seam, not the id.
            Log::warning('[Servosity reads] live fetch failed', ['cache_key' => preg_replace('/\d+/', '{id}', $key), 'exception' => $e::class, 'code' => $e->getCode()]);
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
     * The two failure states carry deliberately different freshness
     * (psa-z30dv R7, .22): schema_drift publishes NO live_checked_at — an
     * uninterpretable response is not an observation, and a stamp here would
     * let the drift block read as a timestamped completed check — while
     * unavailable keeps its stamp, which records only when the ATTEMPT
     * failed and strengthens no claim about upstream state.
     *
     * @param  array{status: string, fetched_at?: string}  $fetch
     * @return array<string, mixed>
     */
    private function liveFailurePayload(array $fetch, string $what, string $consequence): array
    {
        if ($fetch['status'] === 'schema_drift') {
            return [
                'status' => 'schema_drift',
                'note' => "Live {$what} are UNKNOWN — Servosity answered with a response that does not match its documented shape (possible API change; details in the application log). No live_checked_at is published: an uninterpretable answer is not an observation. Do not read this as zero/none; {$consequence}. Verify in the Servosity console.",
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
     * declares account_counts/issue_counts as REQUIRED OBJECTS of INTEGER values
     * (official OpenAPI, additionalProperties: {type: integer}). The value
     * arrives through the identity-preserving decode, so a JSON object is
     * stdClass here and a JSON array is a PHP array: `[]` — an array where the
     * documented shape is an object — is DRIFT, while an empty OBJECT `{}` is
     * the genuine documented "no counts" and validates to an empty map
     * (psa-z30dv.7: the assoc-decode collapse previously made `[]` read as a
     * verified-zero map). Any violation returns map=null + why — a drifted map
     * screams, it is never silently projected or silently trimmed. The why
     * string never echoes content that failed validation.
     *
     * @return array{map: ?array<string, int>, why: ?string}
     */
    private function validatedIntMap(mixed $value): array
    {
        if (! $value instanceof \stdClass) {
            return ['map' => null, 'why' => match (true) {
                $value === null => 'missing from the response',
                is_array($value) => 'a JSON array where the documented shape is an object of counts',
                default => 'not an object of counts',
            }];
        }

        $entries = get_object_vars($value);
        if (count($entries) > self::MAX_COUNT_KEYS) {
            return ['map' => null, 'why' => 'implausibly large ('.count($entries).' keys)'];
        }

        $out = [];
        foreach ($entries as $key => $count) {
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
