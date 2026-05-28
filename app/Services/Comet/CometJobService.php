<?php

namespace App\Services\Comet;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

class CometJobService
{
    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Get recent backup jobs for an asset.
     */
    public function getRecentJobs(Asset $asset, int $days = 7): array
    {
        if (!$asset->comet_username) {
            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        try {
            $allJobs = $this->client->getJobsForUser($asset->comet_username);
        } catch (CometClientException $e) {
            Log::warning("[Comet Jobs] Failed to fetch jobs for {$asset->comet_username}: {$e->getMessage()}");
            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        $cutoff = now()->subDays($days)->timestamp;
        $deviceId = $asset->comet_device_id;

        $jobs = [];
        $lastSuccess = null;
        $lastFailure = null;

        foreach ($allJobs as $job) {
            // The SDK returns BackupJobDetail objects
            $jobDeviceId = $job->DeviceID ?? null;
            $startTime = $job->StartTime ?? 0;

            if ($deviceId && $jobDeviceId !== $deviceId) {
                continue;
            }

            $status = $job->Status ?? null;
            $endTime = $job->EndTime ?? null;
            $classification = $job->Classification ?? null;

            $formatted = [
                'status' => $this->statusLabel($status),
                'status_code' => $status,
                'classification' => $this->classificationLabel($classification),
                'started' => $startTime ? date('Y-m-d H:i:s', $startTime) : null,
                'ended' => $endTime ? date('Y-m-d H:i:s', $endTime) : null,
                'duration_seconds' => ($startTime && $endTime) ? ($endTime - $startTime) : null,
                'total_size' => $job->TotalSize ?? null,
                'upload_size' => $job->UploadSize ?? null,
                'total_files' => $job->TotalFiles ?? null,
                'error' => isset($job->FileErrors) ? substr($job->FileErrors, 0, 500) : null,
            ];

            // Track last success/failure across all time
            if ($status === 5000 && (!$lastSuccess || $startTime > $lastSuccess['started_ts'])) {
                $lastSuccess = $formatted + ['started_ts' => $startTime];
            }
            if ($status === 7002 && (!$lastFailure || $startTime > $lastFailure['started_ts'])) {
                $lastFailure = $formatted + ['started_ts' => $startTime];
            }

            // Only include recent jobs in the list
            if ($startTime >= $cutoff) {
                $jobs[] = $formatted;
            }
        }

        // Sort by start time descending
        usort($jobs, fn ($a, $b) => ($b['started'] ?? '') <=> ($a['started'] ?? ''));

        return [
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'jobs' => $jobs,
        ];
    }

    private function statusLabel(?int $status): string
    {
        return match ($status) {
            5000 => 'Completed',
            6001 => 'Running',
            7001 => 'Warning',
            7002 => 'Failed',
            7005 => 'Cancelled',
            default => 'Unknown',
        };
    }

    private function classificationLabel(?int $classification): string
    {
        return match ($classification) {
            4 => 'Backup',
            5 => 'Restore',
            7 => 'Retention',
            default => 'Other',
        };
    }
}
