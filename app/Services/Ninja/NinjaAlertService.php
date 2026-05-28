<?php

namespace App\Services\Ninja;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Services\AlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NinjaAlertService
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    /**
     * Handle a TRIGGERED alert from NinjaRMM.
     *
     * Upserts the unified Alert record. All severities create alerts.
     * (Previously only CRITICAL/MAJOR created tickets — now all severities are tracked.)
     */
    public function handleTriggered(array $payload): ?Alert
    {
        $seriesUid = $payload['seriesUid'] ?? null;
        $deviceId = $payload['deviceId'] ?? null;
        $severity = $payload['severity'] ?? null;
        $message = $payload['message'] ?? '';
        $firedAt = isset($payload['activityTime'])
            ? Carbon::createFromTimestamp($payload['activityTime'])
            : now();

        $conditionName = $this->extractConditionName($payload);

        if (! $seriesUid) {
            Log::warning('[NinjaAlert] Triggered payload missing seriesUid, skipping');

            return null;
        }

        // Look up asset by ninja_id
        $asset = $deviceId ? Asset::where('ninja_id', $deviceId)->first() : null;

        if ($deviceId && ! $asset) {
            Log::info('[NinjaAlert] No asset found for ninja device', [
                'ninja_device_id' => $deviceId,
            ]);
        }

        $hostname = $asset?->hostname ?? "Device #{$deviceId}";
        $unifiedSeverity = AlertSeverity::fromVendor(AlertSource::Ninja, $severity);
        $title = ($conditionName ?: 'Alert') . " on {$hostname}";

        $alert = $this->alertService->upsert(
            AlertSource::Ninja,
            $seriesUid,
            [
                'asset_id' => $asset?->id,
                'client_id' => $asset?->client_id,
                'severity' => $unifiedSeverity,
                'title' => mb_substr($title, 0, 255),
                'message' => mb_substr($message, 0, 65535),
                'hostname' => $hostname,
                'fired_at' => $firedAt,
                'metadata' => [
                    'ninja_device_id' => $deviceId,
                    'vendor_severity' => $severity,
                    'condition_name' => $conditionName,
                ],
            ],
        );

        Log::info('[NinjaAlert] Alert triggered', [
            'alert_id' => $alert->id,
            'series_uid' => $seriesUid,
            'severity' => $severity,
            'condition_name' => $conditionName,
            'asset_id' => $asset?->id,
        ]);

        return $alert;
    }

    /**
     * Handle a RESET alert from NinjaRMM.
     *
     * Resolves the matching unified Alert record. If the alert has a linked
     * ticket, AlertService::resolve() adds a note to it (but does NOT close it).
     */
    public function handleReset(array $payload): ?Alert
    {
        $seriesUid = $payload['seriesUid'] ?? null;

        if (! $seriesUid) {
            Log::warning('[NinjaAlert] Reset payload missing seriesUid, skipping');

            return null;
        }

        $alert = Alert::where('source', AlertSource::Ninja)
            ->where('source_alert_id', $seriesUid)
            ->first();

        if (! $alert) {
            Log::info('[NinjaAlert] Reset received for untracked alert, skipping', [
                'series_uid' => $seriesUid,
            ]);

            return null;
        }

        $resolvedAt = isset($payload['activityTime'])
            ? Carbon::createFromTimestamp($payload['activityTime'])
            : now();

        // Update resolved_at before calling resolve() so the timestamp is accurate
        $alert->resolved_at = $resolvedAt;

        $this->alertService->resolve($alert, 'Alert cleared in NinjaRMM monitoring.');

        Log::info('[NinjaAlert] Alert reset', [
            'alert_id' => $alert->id,
            'series_uid' => $seriesUid,
        ]);

        return $alert;
    }

    /**
     * Extract the condition name from a NinjaRMM alert payload.
     *
     * Checks sourceName first, then data.message.params.condition_name.
     */
    private function extractConditionName(array $payload): ?string
    {
        if (! empty($payload['sourceName'])) {
            return $payload['sourceName'];
        }

        return $payload['data']['message']['params']['condition_name']
            ?? $payload['data']['message']['params']['software']
            ?? null;
    }
}
