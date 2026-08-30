<?php

namespace App\Services\Offboarding;

use App\Models\Asset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusClientException;
use App\Support\ScreenConnectConfig;
use App\Support\TacticalConfig;
use App\Support\ZorusConfig;
use Illuminate\Support\Facades\Log;

/**
 * "Is this device really gone from every portal?" — the offboarding read (psa-842a).
 *
 * The defect this exists to close: `zorus_*` and `screenconnect_*` answer from our own
 * synced rows, so a portal-side deletion is invisible until the next sync lands. For
 * ScreenConnect the feed is webhook-driven, which makes ABSENCE OF A WEBHOOK
 * INDISTINGUISHABLE FROM PRESENCE OF THE DEVICE. An operator verifying a teardown against
 * those rows is reading a snapshot and being told it is the portal.
 *
 * So every arm reports one of FOUR verdicts, and the third is the load-bearing one:
 *
 *   present            — LIVE evidence the device exists upstream, right now.
 *   absent             — LIVE evidence it does not.
 *   cannot_determine   — we could not ask the vendor. A snapshot-only integration lands
 *                        here ALWAYS, however fresh its rows look — and so does a MISSING
 *                        LINK: holding no id for this asset means the vendor was never
 *                        asked, which is ignorance, not evidence. Never rounded to
 *                        `absent`.
 *   not_applicable     — the integration ITSELF is off/unconfigured on this PSA, so there
 *                        is no vendor to ask. This is the only state dropped from the
 *                        roll-up, so a shop which does not run Zorus at all is not told
 *                        "unknown" forever. "We hold no link" is NOT this — that arm was
 *                        not asked, and dropping it would let a device still enrolled
 *                        upstream sit under an overall `absent`.
 *
 * `method` states HOW each verdict was reached (`live` / `snapshot` / `none`) so the
 * caller can never mistake a stale row for a vendor answer, and the overall roll-up
 * refuses `absent` unless EVERY applicable arm answered `absent` live.
 *
 * SCOPE, and it is stated in the payload rather than implied: this verifier knows THREE
 * integrations. The PSA carries other device-bearing lanes it cannot ask, so every
 * reading publishes `overall.not_checked` naming them, and `absent` never claims to be a
 * whole-estate teardown proof.
 */
class DeviceAbsenceVerifier
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const CANNOT_DETERMINE = 'cannot_determine';

    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * Device-bearing integrations this PSA runs that this verifier CANNOT ask, published
     * on every reading. The tool's authority is exactly as wide as its arms: a device
     * torn down from Tactical and Zorus while a NinjaOne or Level agent keeps running is
     * still a live machine, and a roll-up that named only what it swept would read as a
     * completed teardown. Add an arm and remove its name here in the same change.
     */
    private const NOT_CHECKED = ['ninja', 'level', 'controld', 'm365_intune', 'comet'];

    /**
     * Page size for the Zorus endpoint sweep. Matches ZorusDeviceSyncService so the two
     * read the vendor the same way.
     */
    private const ZORUS_PAGE_SIZE = 500;

    /**
     * Hard ceiling on the Zorus sweep. The vendor list is fetched WHOLE (see below), so
     * an unbounded loop is a real hazard if the API ever stops shrinking the last page.
     * Hitting the cap is reported as cannot_determine, never as absent — a truncated
     * sweep cannot prove a device is missing from the part we did not read.
     */
    private const ZORUS_MAX_PAGES = 40;

    /**
     * @return array<string, mixed>
     */
    public function verify(Asset $asset): array
    {
        $integrations = [
            'tactical' => $this->verifyTactical($asset),
            'zorus' => $this->verifyZorus($asset),
            'screenconnect' => $this->verifyScreenConnect($asset),
        ];

        return [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'hostname' => $asset->hostname,
                'is_active' => $asset->is_active,
            ],
            'integrations' => $integrations,
            'overall' => $this->rollUp($integrations),
        ];
    }

    /**
     * The roll-up, and the whole point of the tool: `absent` requires that EVERY
     * applicable arm answered `absent` from a LIVE read. One `cannot_determine`
     * anywhere makes the overall answer `cannot_determine` — a partial sweep is not a
     * teardown proof, and the Sipher teardown (T-22800) is the case that paid for this.
     *
     * @param  array<string, array<string, mixed>>  $integrations
     * @return array<string, mixed>
     */
    private function rollUp(array $integrations): array
    {
        $applicable = array_filter(
            $integrations,
            fn (array $r): bool => $r['verdict'] !== self::NOT_APPLICABLE,
        );

        $verdicts = array_column($applicable, 'verdict');

        $overall = match (true) {
            $applicable === [] => self::NOT_APPLICABLE,
            in_array(self::PRESENT, $verdicts, true) => self::PRESENT,
            in_array(self::CANNOT_DETERMINE, $verdicts, true) => self::CANNOT_DETERMINE,
            default => self::ABSENT,
        };

        return [
            'verdict' => $overall,
            'checked' => array_keys($applicable),
            'present_in' => array_keys(array_filter($applicable, fn ($r) => $r['verdict'] === self::PRESENT)),
            'absent_from' => array_keys(array_filter($applicable, fn ($r) => $r['verdict'] === self::ABSENT)),
            'undetermined' => array_keys(array_filter($applicable, fn ($r) => $r['verdict'] === self::CANNOT_DETERMINE)),
            'not_applicable' => array_keys(array_diff_key($integrations, $applicable)),
            // Scope disclosure, on every reading and on every verdict: the arms above are
            // the ONLY integrations this verifier knows how to ask.
            'not_checked' => self::NOT_CHECKED,
            'summary' => $this->summaryLine($overall, $applicable),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $applicable
     */
    private function summaryLine(string $overall, array $applicable): string
    {
        return match ($overall) {
            self::NOT_APPLICABLE => 'No integration on this surface is configured for this client — nothing was asked of any vendor, and this is NOT evidence the device is gone.',
            self::PRESENT => 'Device is STILL PRESENT upstream — a live read found it. Not safe to call the teardown complete.',
            self::CANNOT_DETERMINE => 'CANNOT DETERMINE. At least one integration could not be asked, so absence is unproven — do not record this device as removed on the strength of this reading. Check `undetermined` for which arm, and its `reason`.',
            default => 'Absent from the integrations this tool can ask live ('.implode(', ', array_keys($applicable)).'). This is NOT a whole-estate teardown proof: `not_checked` names the device-bearing integrations this PSA runs that the tool cannot ask ('.implode(', ', self::NOT_CHECKED).') — verify those in their own portals before recording the teardown.',
        };
    }

    /**
     * Tactical: a genuine live per-device read. This is the arm that already worked —
     * `tactical_get_device` returning an upstream 404 is the one proof of removal the
     * operator had in the #842 report.
     *
     * 404 is the ONLY absent signal. Every other HTTP status and every transport failure
     * is cannot_determine: TacticalClientException's own contract is explicit that an
     * HTTP error must never be collapsed to a device-state claim (a 403 is an auth
     * failure, possibly a compromised key — not a missing agent).
     *
     * @return array<string, mixed>
     */
    private function verifyTactical(Asset $asset): array
    {
        if (! TacticalConfig::isEnabled() || ! TacticalConfig::isConfigured()) {
            return $this->notApplicable('Tactical RMM is not enabled/configured on this PSA.');
        }

        // The vendor agent id is tactical_assets.agent_id, reached through the
        // relation. `assets.tactical_asset_id` is a LOCAL foreign key to that row's
        // id — get_asset publishes it under a name that reads like an RMM id, and
        // sending it to the vendor would query a device that does not exist and
        // collect a 404: a false `absent` on every device the PSA has linked.
        $agentId = $asset->tacticalAsset?->agent_id;
        if (blank($agentId)) {
            // NOT not_applicable: Tactical is ON, we simply hold no agent id to ask
            // about — a sync gap, or a link stripped mid-offboard. The vendor was not
            // asked, so this arm stays in the roll-up as cannot_determine; dropping it
            // would let a live agent with remote-shell access sit under an `absent`.
            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'none',
                'reason' => 'This asset has no linked Tactical agent row — the PSA holds no agent id, so Tactical was NOT asked about this device. A machine still enrolled in Tactical whose tactical_assets row was never synced looks exactly like this.',
                'evidence' => [],
            ];
        }

        try {
            app(TacticalClient::class)->getAgent((string) $agentId);
        } catch (TacticalClientException $e) {
            if ($e->statusCode() === 404) {
                return [
                    'verdict' => self::ABSENT,
                    'method' => 'live',
                    'reason' => 'Live read: Tactical returned HTTP 404 for this agent id — confirmed gone upstream.',
                    'evidence' => ['agent_id' => (string) $agentId, 'http_status' => 404],
                ];
            }

            Log::warning('[DeviceAbsenceVerifier] Tactical live read failed', [
                'asset_id' => $asset->id,
                'http_status' => $e->statusCode(),
                'transport_failure' => $e->isTransportFailure(),
            ]);

            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'live',
                'reason' => $e->isTransportFailure()
                    ? 'Live read attempted but Tactical was unreachable (transport failure) — this says nothing about the device.'
                    : 'Live read attempted but Tactical answered HTTP '.((string) ($e->statusCode() ?? 'unknown')).' — not a 404, so it is not an absence signal.',
                'evidence' => ['agent_id' => (string) $agentId, 'http_status' => $e->statusCode()],
            ];
        }

        return [
            'verdict' => self::PRESENT,
            'method' => 'live',
            'reason' => 'Live read: Tactical returned the agent — the device still exists upstream.',
            'evidence' => ['agent_id' => (string) $agentId],
        ];
    }

    /**
     * Zorus: a live read is buildable, but only carefully.
     *
     * 🔴 The trap, documented in-code at both ZorusClient::searchEndpoints and
     * ZorusDeviceSyncService: **the customerUuid filter on POST api/endpoints/search is
     * silently ignored and the endpoint returns ALL endpoints.** A filtered query that
     * comes back non-empty therefore proves NOTHING about the device asked for. So this
     * sweeps the endpoint list whole — exactly as the sync does — and matches
     * CLIENT-SIDE on the recorded endpoint uuid, falling back to hostname when the PSA
     * holds no uuid link (the same two-step the sync uses to establish links in the
     * first place).
     *
     * Deliberately NOT scoped with Client::operational(): the Zorus sync only sweeps
     * operational clients, but a client being offboarded has usually left the Active
     * stage — that population is named in #842(b) as a false-positive source. The
     * customer id is read straight off this asset's own client — and it is read AFTER the
     * asset's own zorus_endpoint_id, never as a gate in front of it: the uuid match is
     * customer-independent, so a uuid-linked asset is answerable even for a client whose
     * mapping was never backfilled or was cleared on its way out of the Active stage.
     * Only the hostname pass needs the customer uuid, and it is skipped without one — and
     * because it is skipped, a uuid MISS with no customer uuid is reported as
     * cannot_determine, never as absent: the reinstall case (dead recorded uuid, same
     * hostname, still enrolled) was never ruled out, so absence there is unproven.
     *
     * @return array<string, mixed>
     */
    private function verifyZorus(Asset $asset): array
    {
        if (! ZorusConfig::isAvailable()) {
            return $this->notApplicable('Zorus is not enabled/configured on this PSA.');
        }

        // The asset's OWN link is read FIRST, the client mapping second: the uuid match
        // is exact and customer-independent, so a uuid-linked device can be answered live
        // without it. Gating on the mapping dropped still-enrolled devices out of the
        // quorum for exactly the unmapped clients this arm exists to serve.
        $endpointUuid = $asset->zorus_endpoint_id;
        $hostname = $asset->hostname;
        $customerUuid = $asset->client?->zorus_customer_id;

        // No uuid link and no usable hostname pass: the vendor cannot be asked ABOUT THIS
        // DEVICE. cannot_determine, not not_applicable — Zorus is on, we just hold no
        // link, and a link never synced (or stripped mid-offboard) must not leave the
        // roll-up as though the integration did not apply.
        if (blank($endpointUuid) && (blank($hostname) || blank($customerUuid))) {
            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'none',
                'reason' => blank($hostname)
                    ? 'No zorus_endpoint_id and no hostname on this asset — there is nothing to match a live Zorus endpoint against, so Zorus was NOT asked about this device.'
                    : 'No zorus_endpoint_id on this asset, and its client carries no zorus_customer_id — the hostname pass has no customer to scope itself to, and an unscoped hostname hit would be a different customer\'s identically-named machine. Zorus was NOT asked about this device.',
                'evidence' => [],
            ];
        }

        try {
            $endpoints = $this->fetchAllZorusEndpoints();
        } catch (ZorusClientException $e) {
            Log::warning('[DeviceAbsenceVerifier] Zorus live read failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'live',
                'reason' => 'Live read attempted but the Zorus endpoint sweep failed — this says nothing about the device.',
                'evidence' => [],
            ];
        }

        if ($endpoints === null) {
            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'live',
                'reason' => 'Live read attempted but the Zorus endpoint sweep hit its '.self::ZORUS_MAX_PAGES.'-page ceiling before the list ended — a truncated sweep cannot prove absence.',
                'evidence' => ['pages_capped_at' => self::ZORUS_MAX_PAGES],
            ];
        }

        $match = $this->matchZorusEndpoint($endpoints, $customerUuid, $endpointUuid, $hostname);

        if ($match !== null) {
            // Cross-tenant fence. This tool is reachable from the CLIENT-scoped surface,
            // and the uuid match is deliberately customer-independent — so the row it hit
            // may belong to another Zorus customer. The VERDICT still crosses (the device
            // is present upstream, which is the honest teardown answer); that customer's
            // endpoint name and customer uuid do not.
            $ownEndpoint = filled($customerUuid) && $match['customer_uuid'] === $customerUuid;

            return [
                'verdict' => self::PRESENT,
                'method' => 'live',
                'reason' => 'Live read: the device was found in the current Zorus endpoint list, matched by '.$match['matched_on'].'.',
                'evidence' => [
                    'matched_on' => $match['matched_on'],
                    'endpoint_uuid' => $match['uuid'],
                    'endpoint_name' => $ownEndpoint ? $match['name'] : null,
                    'endpoint_is_under_this_client' => $ownEndpoint,
                ],
            ];
        }

        // Nothing matched — but did we actually run both passes? Reaching here with a
        // blank hostname or a blank customer uuid means $endpointUuid is filled (the guard
        // above returned otherwise) and matchZorusEndpoint ran the uuid comparison ONLY:
        // the hostname pass is structurally skipped without a customer to scope it to. An
        // agent REINSTALL mints a new uuid, so a stale recorded uuid missing from the list
        // is exactly what a still-filtering device looks like. That is ignorance, not
        // evidence — and claiming `absent` here is the false teardown proof this arm exists
        // to prevent, on precisely the unmapped-client population it exists to serve.
        if (blank($hostname) || blank($customerUuid)) {
            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'live',
                'reason' => 'Live read: the recorded zorus_endpoint_id was not found anywhere in the current Zorus endpoint list, and the hostname pass could NOT be run — '
                    .(blank($hostname)
                        ? 'this asset carries no hostname.'
                        : 'its client carries no zorus_customer_id, and an unscoped hostname hit would be a different customer\'s identically-named machine.')
                    .' A reinstalled agent keeps the hostname and mints a new uuid, so a stale uuid alone does not prove this device is gone from Zorus.',
                'evidence' => [
                    'looked_for_endpoint_uuid' => $endpointUuid,
                    'looked_for_hostname' => $hostname,
                    'customer_uuid' => $customerUuid,
                    'hostname_pass_ran' => false,
                ],
            ];
        }

        return [
            'verdict' => self::ABSENT,
            'method' => 'live',
            'reason' => 'Live read: the whole current Zorus endpoint list was scanned and this device is not in it — '
                .(blank($endpointUuid)
                    ? 'no hostname match within this client\'s customer (the PSA holds no zorus_endpoint_id for it).'
                    : 'neither its recorded endpoint uuid anywhere in the list, nor its hostname within this client\'s customer.'),
            'evidence' => [
                'looked_for_endpoint_uuid' => $endpointUuid,
                'looked_for_hostname' => $hostname,
                'customer_uuid' => $customerUuid,
                // The whole-estate count is other customers' business and this tool
                // answers client-scoped callers, so only this client's own slice of the
                // swept list is published back.
                'endpoints_scanned_for_this_customer' => filled($customerUuid)
                    ? count(array_filter(
                        $endpoints,
                        fn ($endpoint): bool => is_array($endpoint) && ($endpoint['customerUuid'] ?? null) === $customerUuid,
                    ))
                    : null,
            ],
        ];
    }

    /**
     * Fetch the whole Zorus endpoint list, paginating the way the sync does.
     *
     * Returns null when the page ceiling is reached before the list ends — the caller
     * turns that into cannot_determine rather than risking a false `absent` off a
     * truncated read.
     *
     * @return array<int, array<string, mixed>>|null
     *
     * @throws ZorusClientException
     */
    private function fetchAllZorusEndpoints(): ?array
    {
        $client = app(ZorusClient::class);
        $all = [];
        $page = 1;

        do {
            $batch = $client->searchEndpoints([], $page, self::ZORUS_PAGE_SIZE);
            $all = array_merge($all, $batch);

            if (count($batch) < self::ZORUS_PAGE_SIZE) {
                return $all;
            }

            $page++;
        } while ($page <= self::ZORUS_MAX_PAGES);

        return null;
    }

    /**
     * Match one endpoint out of the swept list, client-side.
     *
     * uuid match is exact and customer-independent (a uuid is unique; if the device
     * moved customer it is still PRESENT in Zorus, which is the honest answer for a
     * teardown check). The hostname pass IS scoped to this client's customer uuid —
     * hostnames are not unique across customers, and an unscoped hostname hit would
     * report a different customer's identically-named machine as this one — so it is
     * skipped entirely when the client carries no customer uuid.
     *
     * The hostname pass runs even when a uuid link exists but missed: an agent
     * REINSTALL mints a new uuid, so a machine that is still actively filtering under
     * this customer can have a dead recorded uuid. Answering `absent` there is a false
     * teardown proof — the exact failure this tool exists to prevent.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, mixed>|null
     */
    private function matchZorusEndpoint(array $endpoints, ?string $customerUuid, ?string $endpointUuid, ?string $hostname): ?array
    {
        $lowerHost = filled($hostname) ? mb_strtolower((string) $hostname) : null;
        $shortHost = $lowerHost !== null ? mb_strtolower(explode('.', (string) $hostname)[0]) : null;

        $hostnameHit = null;

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $uuid = is_scalar($endpoint['uuid'] ?? null) ? (string) $endpoint['uuid'] : null;
            $name = is_scalar($endpoint['name'] ?? null) ? (string) $endpoint['name'] : null;
            $endpointCustomer = is_scalar($endpoint['customerUuid'] ?? null) ? (string) $endpoint['customerUuid'] : null;

            if (filled($endpointUuid) && $uuid !== null && $uuid === $endpointUuid) {
                return ['matched_on' => 'endpoint uuid', 'uuid' => $uuid, 'name' => $name, 'customer_uuid' => $endpointCustomer];
            }

            if ($hostnameHit === null && $lowerHost !== null && $name !== null && $customerUuid !== null && $endpointCustomer === $customerUuid) {
                $lowerName = mb_strtolower($name);
                $shortName = mb_strtolower(explode('.', $name)[0]);

                if ($lowerName === $lowerHost || $shortName === $lowerHost || $lowerName === $shortHost || $shortName === $shortHost) {
                    // Not returned yet: a uuid hit later in the list is the stronger
                    // identity and must win over a name collision.
                    $hostnameHit = [
                        'matched_on' => filled($endpointUuid)
                            ? 'hostname (recorded endpoint uuid is NOT in the live list — the agent was likely reinstalled under a new uuid)'
                            : 'hostname (no uuid link recorded)',
                        'uuid' => $uuid,
                        'name' => $name,
                        'customer_uuid' => $endpointCustomer,
                    ];
                }
            }
        }

        return $hostnameHit;
    }

    /**
     * ScreenConnect: structurally cannot be asked.
     *
     * There is no ScreenConnect API client in this codebase and no plan to add one —
     * the integration is webhook-RECEIVE-only by design (ScreenConnectSyncService is fed
     * by ProcessScreenConnectWebhook; ScreenConnectConfig holds a base_url and a webhook
     * secret and nothing that could authenticate an outbound query). Querying the vendor
     * live needs a credential class the PSA does not hold.
     *
     * So this arm ALWAYS returns cannot_determine while the integration is on. It hands
     * back the snapshot alongside — freshness stamps and all — precisely so an operator
     * can see the snapshot without it being dressed up as a vendor answer. A webhook that
     * never arrived and a device that never left produce byte-identical rows here; that
     * is the #842 finding, and reporting `present` off these fields would be the bug.
     *
     * @return array<string, mixed>
     */
    private function verifyScreenConnect(Asset $asset): array
    {
        if (! ScreenConnectConfig::isAvailable()) {
            return $this->notApplicable('ScreenConnect is not enabled/configured on this PSA.');
        }

        if (blank($asset->screenconnect_session_id)) {
            // Same rule as the other arms: ScreenConnect is ON and we hold no session id,
            // so nothing was asked — and here nothing COULD be asked even with one. That
            // is cannot_determine, and it stays in the roll-up.
            return [
                'verdict' => self::CANNOT_DETERMINE,
                'method' => 'none',
                'reason' => 'This asset carries no screenconnect_session_id — the PSA holds no link into ScreenConnect for it, and there is no outbound API to ask instead. ScreenConnect was NOT asked about this device.',
                'evidence' => [],
            ];
        }

        return [
            'verdict' => self::CANNOT_DETERMINE,
            'method' => 'snapshot',
            'reason' => 'ScreenConnect is webhook-receive-only in this PSA — there is no outbound API client, so the vendor CANNOT be asked whether this session still exists. The fields below are our last received snapshot, not a live answer: a session deleted in the portal looks exactly like a session that simply stopped emitting webhooks. Do not read them as presence.',
            'evidence' => [
                'session_id' => $asset->screenconnect_session_id,
                'snapshot_online' => $asset->screenconnect_online,
                'snapshot_last_seen_at' => $asset->screenconnect_last_seen_at?->toDateTimeString(),
                'snapshot_synced_at' => $asset->screenconnect_synced_at?->toDateTimeString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notApplicable(string $reason): array
    {
        return [
            'verdict' => self::NOT_APPLICABLE,
            'method' => 'none',
            'reason' => $reason,
            'evidence' => [],
        ];
    }
}
