<?php

namespace App\Services\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScreenConnectSyncService
{
    private const DEVICE_EVENTS = [
        'Connected',
        'Disconnected',
        'ProcessedGuestInfoUpdate',
        'ModifiedName',
        'CreatedSession',
    ];

    private const ACTIVITY_EVENTS = [
        'RanCommand',
        'SentMessage',
        'SentFiles',
        'CopiedFiles',
        'CopiedText',
        'DraggedFiles',
        'RanFiles',
        'RequestedElevation',
        'ApprovedRequest',
        'DeniedRequest',
        'SentPrintJob',
        'ReceivedPrintJob',
    ];

    /**
     * Normalize ScreenConnect's native {*:json} payload into flat keys.
     *
     * Native format uses nested objects: Session.SessionID, Event.EventType, etc.
     * Also supports the legacy flat format for backwards compat.
     */
    public static function normalizePayload(array $payload): array
    {
        // Already in flat format (legacy template)
        if (isset($payload['event_type']) || isset($payload['session_id'])) {
            return $payload;
        }

        $session = $payload['Session'] ?? [];
        $event = $payload['Event'] ?? [];
        $connection = $payload['Connection'] ?? [];

        return [
            'session_id' => $session['SessionID'] ?? null,
            'session_name' => $session['Name'] ?? null,
            'session_type' => $session['SessionType'] ?? null,
            'company' => $session['CustomProperty1'] ?? null,
            'guest_machine_name' => $session['GuestMachineName'] ?? null,
            'guest_machine_domain' => $session['GuestMachineDomain'] ?? null,
            'guest_os' => $session['GuestOperatingSystemName'] ?? null,
            'guest_os_version' => $session['GuestOperatingSystemVersion'] ?? null,
            'guest_processor' => $session['GuestProcessorName'] ?? null,
            'guest_ram_mb' => $session['GuestSystemMemoryTotalMegabytes'] ?? null,
            'guest_network_address' => $session['GuestNetworkAddress'] ?? null,
            'guest_logged_on_user' => $session['GuestLoggedOnUserName'] ?? null,
            'guest_logged_on_domain' => $session['GuestLoggedOnUserDomain'] ?? null,
            'guest_last_activity' => $session['GuestLastActivityTime'] ?? null,
            'guest_client_version' => $session['GuestClientVersion'] ?? null,
            'guest_machine_serial' => $session['GuestMachineSerialNumber'] ?? null,
            'guest_machine_model' => $session['GuestMachineModel'] ?? null,
            'guest_machine_manufacturer' => $session['GuestMachineManufacturerName'] ?? null,
            'guest_connected_count' => $session['GuestConnectedCount'] ?? null,
            'host_connected_count' => $session['HostConnectedCount'] ?? null,
            'event_type' => $event['EventType'] ?? 'unknown',
            'event_time' => $event['Time'] ?? null,
            'event_data' => $event['Data'] ?? null,
            'event_host' => $event['Host'] ?? null,
            'connection_participant' => $connection['ParticipantName'] ?? null,
            'connection_network_address' => $connection['NetworkAddress'] ?? null,
        ];
    }

    public function processWebhook(array $payload): string
    {
        $payload = self::normalizePayload($payload);

        $eventType = $payload['event_type'] ?? 'unknown';
        $sessionId = $payload['session_id'] ?? null;
        $sessionType = $payload['session_type'] ?? null;
        $company = $payload['company'] ?? null;
        $hostname = $payload['guest_machine_name'] ?? null;

        if ($sessionType && $sessionType !== 'Access') {
            return "Skipped non-Access session type: {$sessionType}";
        }

        if (! $sessionId) {
            return 'Skipped: no session_id';
        }

        $client = $this->resolveClient($company);
        $asset = $this->resolveAsset($sessionId, $hostname, $client);

        if (! $asset) {
            // Ids only (card 6abe578e): an ambiguous company resolves to no client and lands
            // here, so neither the company string nor the guest hostname is echoed into this
            // result, which the job logs and stores on the webhook row.
            return "No matching asset for session {$sessionId}";
        }

        // W1 (card 6abe578e): fail closed on a contradicted attribution. The session id
        // is linked to an asset of client B, but this webhook's company resolves to a
        // different client A. Either the original link is wrong or the device moved
        // between clients; we cannot tell which, so nothing is attached — no event row,
        // no asset field — and the webhook is marked skipped (the "Skipped" prefix is
        // what ProcessScreenConnectWebhook keys on). The payload stays on the webhook
        // row, and MislinkedAssetFinder's ScreenConnect rule surfaces the asset for a
        // human. A company that is missing, unresolved or ambiguous resolves to no
        // client, and then the session link stays authoritative, as before. Ids only in
        // the log and the result: no client names, hostnames or company strings.
        if ($client !== null && $asset->client_id !== null && (int) $asset->client_id !== (int) $client->id) {
            Log::warning('[ScreenConnect] Webhook company contradicts the session-linked asset\'s client; nothing attached', [
                'asset_id' => $asset->id,
                'linked_client_id' => (int) $asset->client_id,
                'resolved_client_id' => (int) $client->id,
                'session_id' => $sessionId,
            ]);

            return "Skipped: session {$sessionId} is linked to asset #{$asset->id} of client #{$asset->client_id}, but the webhook company resolves to client #{$client->id}; nothing attached";
        }

        // Card LDlXWBwA: the asset is about to take the session id, so it was matched by
        // hostname (step 2), and step 1 found no LIVE asset holding the id. The id can
        // still be held by a soft-deleted asset: resolveAsset step 1 runs under the
        // SoftDeletes scope and cannot see it, but the single-column unique key on
        // screenconnect_session_id does, so the save below would fail on every webhook
        // for this device. Such a trashed holder is released in the same transaction as
        // the save, whichever client it belonged to: a device whose old asset was trashed
        // under another client heals the same way. A live holder is never touched: both
        // the lookup below and the locked re-read in releaseTrashedHolder are
        // onlyTrashed(), so a live asset that took the id after step 1 is not released.
        $trashedHolder = null;
        if (! $asset->screenconnect_session_id) {
            $trashedHolder = Asset::onlyTrashed()->where('screenconnect_session_id', $sessionId)->first();

            $asset->screenconnect_session_id = $sessionId;
        }

        $attach = function () use ($asset, $payload, $eventType): void {
            if ($this->isDeviceEvent($eventType)) {
                $this->updateAssetFromPayload($asset, $payload, $eventType);
            }

            if ($this->isActivityEvent($eventType)) {
                $this->logActivityEvent($asset, $payload);
            }

            $asset->screenconnect_synced_at = now();
            $asset->save();
        };

        if ($trashedHolder) {
            $releasedFrom = DB::transaction(function () use ($trashedHolder, $sessionId, $attach): ?int {
                $releasedFrom = $this->releaseTrashedHolder($trashedHolder->id, $sessionId);
                $attach();

                return $releasedFrom;
            });

            if ($releasedFrom !== null) {
                Log::warning('[ScreenConnect] Released session id from a trashed asset to the hostname-matched asset', [
                    'trashed_asset_id' => $releasedFrom,
                    'asset_id' => $asset->id,
                    'session_id' => $sessionId,
                ]);
            }
        } else {
            $attach();
        }

        return "Processed {$eventType} for asset #{$asset->id} ({$asset->name})";
    }

    /**
     * Card LDlXWBwA: inside the caller's transaction, lock the trashed row and clear its
     * session id. The row is re-read under the lock and must still be trashed and still
     * hold the id. If it no longer does (restored, its id cleared or changed, or
     * force-deleted since it was found), nothing is released and null is returned; the
     * caller's save then succeeds if no row holds the id any more, or fails on the
     * unique key and rolls back if one still does.
     * Returns the released asset's id, or null when nothing was released.
     */
    private function releaseTrashedHolder(int $trashedAssetId, string $sessionId): ?int
    {
        $held = Asset::onlyTrashed()
            ->whereKey($trashedAssetId)
            ->where('screenconnect_session_id', $sessionId)
            ->lockForUpdate()
            ->first();

        if (! $held) {
            return null;
        }

        $held->screenconnect_session_id = null;
        $held->save();

        return $held->id;
    }

    private function resolveClient(?string $company): ?Client
    {
        return self::resolveCompanyClient($company);
    }

    /**
     * The ONE company → PSA client rule, shared by the webhook ingest and
     * MislinkedAssetFinder's ScreenConnect rule: an exact, case-insensitive match on
     * clients.name.
     *
     * W3 (card 6abe578e): two or more clients carrying the name are ambiguous, and an
     * ambiguous company resolves to NO client rather than to the lowest id. Soft-deleted
     * clients take no part (Client uses SoftDeletes, so the default scope excludes
     * them). Inactive clients DO take part — there is deliberately no is_active filter —
     * so a churned client sharing a live client's name makes the name ambiguous instead
     * of being silently out-voted. The log carries the count only.
     */
    public static function resolveCompanyClient(?string $company, bool $log = true): ?Client
    {
        if (! $company) {
            return null;
        }

        $matches = Client::whereRaw('LOWER(name) = ?', [mb_strtolower($company)])
            ->orderBy('id')
            ->get();

        if ($matches->count() > 1) {
            if ($log) {
                Log::info('[ScreenConnect] Webhook company matches more than one client by name; resolved to no client', [
                    'match_count' => $matches->count(),
                ]);
            }

            return null;
        }

        return $matches->first();
    }

    /**
     * Resolve the asset a webhook session belongs to.
     *
     *  1. An asset already carrying this session id.
     *  2. Otherwise, only when the company resolved to a PSA client, a hostname match
     *     SCOPED TO THAT CLIENT via ScreenConnectAssetMatcher: the webhook machine name
     *     is shortened to its first label, then (a) an exact LOWER(hostname)/LOWER(name)
     *     match is preferred; (b) failing that, an asset whose stored hostname is fully
     *     qualified with that first label (stored "test-mbp.lan" for "Test-MBP") links
     *     ONLY if it is the client's single such asset. Two or more first-label
     *     candidates are ambiguous: nothing is linked and a non-PII reason is logged.
     *
     * The client must resolve first (resolveClient is an exact, case-insensitive
     * company-name match); without it there is no hostname match at all — see below.
     */
    private function resolveAsset(string $sessionId, ?string $hostname, ?Client $client): ?Asset
    {
        // 1. Match by session ID (already linked)
        $asset = Asset::where('screenconnect_session_id', $sessionId)->first();
        if ($asset) {
            return $asset;
        }

        // 2. Match by hostname, scoped to client
        if ($hostname && $client) {
            $shortHostname = ScreenConnectAssetMatcher::firstLabel($hostname);

            $asset = ScreenConnectAssetMatcher::exactQuery($client->id, $shortHostname)->first()
                ?? ScreenConnectAssetMatcher::uniqueFirstLabelMatch($client->id, $shortHostname, 'webhook');

            if ($asset) {
                return $asset;
            }
        }

        // No client-scoped match. We deliberately do NOT fall back to an unscoped
        // hostname match. With two clients sharing a short hostname (e.g. WS-FRONT-01)
        // and a webhook whose company is missing or unmatched, an unscoped match attaches
        // this session — and its online state, client version, and activity — to whichever
        // client's asset is first, leaking one client's data under another (psa-ikzqz,
        // found by the psa-514da security lens). A hostname alone is not attributable to a
        // client anyway: the device may belong to a client that isn't in the PSA and only
        // coincidentally shares a hostname. So we fail closed — the session stays unlinked
        // until an evidenced (session-id or company-scoped) webhook, or a manual link,
        // resolves it. This is a data-boundary refusal, not a missed match.
        return null;
    }

    private function updateAssetFromPayload(Asset $asset, array $payload, string $eventType): void
    {
        if ($eventType === 'Connected') {
            $asset->screenconnect_online = true;
            $asset->screenconnect_last_seen_at = now();
        } elseif ($eventType === 'Disconnected') {
            $asset->screenconnect_online = false;
            $asset->screenconnect_last_seen_at = now();
        }

        if (! empty($payload['guest_client_version'])) {
            $asset->screenconnect_client_version = $payload['guest_client_version'];
        }

        // Serial number: set if missing or nonsense (e.g. hostname used as serial)
        if (! empty($payload['guest_machine_serial'])) {
            $serial = self::cleanSerial($payload['guest_machine_serial']);
            if ($serial && self::shouldUpdateSerial($asset, $serial)) {
                $asset->serial_number = $serial;
            }
        }

        // Backfill-only: don't overwrite RMM-authoritative data
        if (empty($asset->os) && ! empty($payload['guest_os'])) {
            $asset->os = $payload['guest_os'];
        }

        if (empty($asset->cpu) && ! empty($payload['guest_processor'])) {
            $asset->cpu = $payload['guest_processor'];
        }

        if (empty($asset->ram_gb) && ! empty($payload['guest_ram_mb'])) {
            $ramMb = (int) $payload['guest_ram_mb'];
            if ($ramMb > 0) {
                $asset->ram_gb = round($ramMb / 1024, 2);
            }
        }

        // Always-update: real-time state
        if (! empty($payload['guest_logged_on_user'])) {
            $user = $payload['guest_logged_on_user'];
            if (! empty($payload['guest_logged_on_domain'])) {
                $user = $payload['guest_logged_on_domain'].'\\'.$user;
            }
            $asset->last_user = $user;
        }

        if (! empty($payload['guest_network_address'])) {
            $asset->ip_address = $payload['guest_network_address'];
        }
    }

    private function logActivityEvent(Asset $asset, array $payload): void
    {
        ScreenConnectEvent::create([
            'asset_id' => $asset->id,
            'session_id' => $payload['session_id'],
            'event_type' => $payload['event_type'],
            'event_time' => $this->parseEventTime($payload['event_time'] ?? null),
            'host' => $payload['event_host'] ?? null,
            'data' => $payload['event_data'] ?? null,
            'participant' => $payload['connection_participant'] ?? null,
            'network_address' => $payload['connection_network_address'] ?? null,
        ]);
    }

    private function isDeviceEvent(string $eventType): bool
    {
        foreach (self::DEVICE_EVENTS as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isActivityEvent(string $eventType): bool
    {
        return in_array($eventType, self::ACTIVITY_EVENTS, true);
    }

    private function parseEventTime(?string $time): ?\Carbon\Carbon
    {
        if (! $time) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($time);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strip junk serial values returned by some BIOSes.
     */
    private static function cleanSerial(string $raw): ?string
    {
        $serial = trim($raw);
        if ($serial === '') {
            return null;
        }

        $junk = [
            'standard', 'default string', 'to be filled by o.e.m.', 'none',
            'not specified', 'system serial number', 'n/a', '0', 'unknown',
        ];

        if (in_array(mb_strtolower($serial), $junk, true)) {
            return null;
        }

        return $serial;
    }

    /**
     * Determine if the asset's serial should be updated.
     *
     * Overwrites when: no serial, serial matches hostname/name (placeholder),
     * or serial is suspiciously short (≤2 chars).
     */
    private static function shouldUpdateSerial(Asset $asset, string $newSerial): bool
    {
        $current = trim($asset->serial_number ?? '');
        if ($current === '') {
            return true;
        }

        // Current serial looks like the hostname or asset name — it's a placeholder
        if (mb_strtolower($current) === mb_strtolower($asset->hostname ?? '')
            || mb_strtolower($current) === mb_strtolower($asset->name ?? '')) {
            return true;
        }

        if (mb_strlen($current) <= 2) {
            return true;
        }

        return false;
    }
}
