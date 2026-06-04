<?php

namespace App\Services\Comet;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Services\AlertService;
use App\Support\CometConfig;
use Illuminate\Support\Facades\Log;

class CometAlertService
{
    private const STATUS_SUCCESS = 5000;

    private const STATUS_RUNNING = 6001;

    private const STATUS_WARNING = 7001;

    private const STATUS_ERROR = 7002;

    private const STATUS_CANCELLED = 7005;

    private const CLASS_BACKUP = 4;

    private const CLASS_RESTORE = 5;

    private const CLASS_RETENTION = 7;

    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    public function handleJobFailure(array $data): ?Alert
    {
        if (! CometConfig::alertsEnabled()) {
            Log::debug('[Comet Alert] Alerts disabled, ignoring');

            return null;
        }

        $username = $data['Username'] ?? null;
        $deviceId = $data['DeviceID'] ?? null;
        $status = $data['Status'] ?? null;
        $classification = $data['Classification'] ?? null;
        $fileErrors = $data['FileErrors'] ?? null;
        $startTime = $data['StartTime'] ?? null;
        $endTime = $data['EndTime'] ?? null;
        $totalSize = $data['TotalSize'] ?? null;

        if ($status !== self::STATUS_ERROR) {
            Log::debug('[Comet Alert] Non-error status, ignoring', ['status' => $status]);

            return null;
        }

        if ($classification !== self::CLASS_BACKUP) {
            Log::debug('[Comet Alert] Non-backup classification, ignoring', ['classification' => $classification]);

            return null;
        }

        $asset = $deviceId ? Asset::where('comet_device_id', $deviceId)->first() : null;
        $clientId = $asset?->client_id;

        if (! $clientId) {
            Log::info('[Comet Alert] No client match for device, creating unlinked alert', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
        }

        $hostname = $asset?->hostname ?? $username ?? 'Unknown';
        $jobType = $this->classificationLabel($classification);

        // source_alert_id: {DeviceID}:{Classification} — Comet has no unique job failure ID
        $sourceAlertId = "{$deviceId}:{$classification}";

        // Build message
        $msgLines = ["Device: {$hostname}", "Job type: {$jobType}", 'Status: Failed'];
        if ($startTime) {
            $msgLines[] = 'Started: '.date('Y-m-d H:i:s', $startTime);
        }
        if ($endTime) {
            $msgLines[] = 'Ended: '.date('Y-m-d H:i:s', $endTime);
        }
        if ($totalSize) {
            $msgLines[] = 'Total size: '.number_format($totalSize / (1024 ** 3), 2).' GB';
        }
        if ($fileErrors) {
            $msgLines[] = '';
            $msgLines[] = 'Errors:';
            $msgLines[] = substr($fileErrors, 0, 2000);
        }

        $alert = $this->alertService->upsert(
            AlertSource::Comet,
            $sourceAlertId,
            [
                'asset_id' => $asset?->id,
                'client_id' => $clientId,
                'severity' => AlertSeverity::fromVendor(AlertSource::Comet, null),
                'title' => mb_substr("Backup Failed - {$jobType} on {$hostname}", 0, 255),
                'message' => implode("\n", $msgLines),
                'hostname' => $hostname,
                'fired_at' => $endTime ? \Illuminate\Support\Carbon::createFromTimestamp($endTime) : now(),
                'metadata' => [
                    'device_id' => $deviceId,
                    'username' => $username,
                    'classification' => $classification,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'total_size' => $totalSize,
                ],
            ],
        );

        Log::info('[Comet Alert] Alert upserted', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
            'hostname' => $hostname,
            'client_id' => $clientId,
        ]);

        return $alert;
    }

    public function handleJobSuccess(array $data): ?Alert
    {
        $deviceId = $data['DeviceID'] ?? null;
        $classification = $data['Classification'] ?? null;

        if ($classification !== self::CLASS_BACKUP) {
            return null;
        }

        // Match the same source_alert_id format used in handleJobFailure
        $sourceAlertId = "{$deviceId}:{$classification}";

        $alert = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->latest()
            ->first();

        if (! $alert) {
            return null;
        }

        $this->alertService->resolve($alert, 'Backup completed successfully.');

        Log::info('[Comet Alert] Alert resolved on job success', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
        ]);

        return $alert;
    }

    private function classificationLabel(int $classification): string
    {
        return match ($classification) {
            self::CLASS_BACKUP => 'Backup',
            self::CLASS_RESTORE => 'Restore',
            self::CLASS_RETENTION => 'Retention',
            default => 'Job',
        };
    }
}
