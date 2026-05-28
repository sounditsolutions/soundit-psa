<?php

namespace App\Services\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
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
            return "No matching asset for session {$sessionId} (host: {$hostname}, company: {$company})";
        }

        if (! $asset->screenconnect_session_id) {
            $asset->screenconnect_session_id = $sessionId;
        }

        if ($this->isDeviceEvent($eventType)) {
            $this->updateAssetFromPayload($asset, $payload, $eventType);
        }

        if ($this->isActivityEvent($eventType)) {
            $this->logActivityEvent($asset, $payload);
        }

        $asset->screenconnect_synced_at = now();
        $asset->save();

        return "Processed {$eventType} for asset #{$asset->id} ({$asset->name})";
    }

    private function resolveClient(?string $company): ?Client
    {
        if (! $company) {
            return null;
        }

        return Client::whereRaw('LOWER(name) = ?', [mb_strtolower($company)])->first();
    }

    private function resolveAsset(string $sessionId, ?string $hostname, ?Client $client): ?Asset
    {
        // 1. Match by session ID (already linked)
        $asset = Asset::where('screenconnect_session_id', $sessionId)->first();
        if ($asset) {
            return $asset;
        }

        // 2. Match by hostname, scoped to client
        if ($hostname && $client) {
            $shortHostname = explode('.', $hostname)[0];

            $asset = Asset::where('client_id', $client->id)
                ->whereRaw('LOWER(hostname) = ? OR LOWER(name) = ?', [
                    mb_strtolower($shortHostname),
                    mb_strtolower($shortHostname),
                ])
                ->first();

            if ($asset) {
                return $asset;
            }
        }

        // 3. Match by hostname without client scope (fallback)
        if ($hostname) {
            $shortHostname = explode('.', $hostname)[0];

            $asset = Asset::whereRaw('LOWER(hostname) = ? OR LOWER(name) = ?', [
                mb_strtolower($shortHostname),
                mb_strtolower($shortHostname),
            ])
                ->first();

            if ($asset) {
                return $asset;
            }
        }

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
                $user = $payload['guest_logged_on_domain'] . '\\' . $user;
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
