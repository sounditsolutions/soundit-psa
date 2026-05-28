<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Reads git version info and caches it. Used by the About page and navbar badge.
 *
 * Implicit requirements:
 * - .git directory must exist at base_path()
 * - `git` must be in PATH for the PHP process user
 * - Network access to the git remote is needed for checkForUpdates()
 */
class VersionService
{
    private const CACHE_KEY_CURRENT = 'psa_version_current';
    private const CACHE_KEY_UPDATES = 'psa_version_updates';
    private const CACHE_TTL_CURRENT = 86400;  // 24 hours
    private const CACHE_TTL_UPDATES = 3600;   // 1 hour
    private const FETCH_THROTTLE_SECONDS = 300; // 5 minutes

    /**
     * Get current version info (cached).
     */
    public function current(): array
    {
        return Cache::remember(self::CACHE_KEY_CURRENT, self::CACHE_TTL_CURRENT, function () {
            return $this->readCurrentFromGit();
        });
    }

    /**
     * Get cached update availability info. No git calls — returns empty state if never checked.
     */
    public function updates(): array
    {
        return Cache::get(self::CACHE_KEY_UPDATES, [
            'commits_behind' => 0,
            'available_commits' => [],
            'recent_history' => [],
            'checked_at' => null,
            'error' => null,
        ]);
    }

    /**
     * Check for updates by fetching from origin and comparing.
     * Throttled: returns cached result if checked within FETCH_THROTTLE_SECONDS.
     */
    public function checkForUpdates(): array
    {
        $cached = Cache::get(self::CACHE_KEY_UPDATES);
        if ($cached && !empty($cached['checked_at']) && empty($cached['error'])) {
            $checkedAt = \Carbon\Carbon::parse($cached['checked_at']);
            if ($checkedAt->diffInSeconds(now()) < self::FETCH_THROTTLE_SECONDS) {
                return $cached;
            }
        }

        $repoPath = base_path();

        try {
            // Fetch latest from origin
            $fetch = Process::path($repoPath)->timeout(30)->run('git fetch origin --quiet');
            if ($fetch->failed()) {
                return $this->cacheUpdateError('Git fetch failed: ' . trim($fetch->errorOutput()));
            }

            // Count total commits behind
            $countResult = Process::path($repoPath)->timeout(10)->run('git rev-list HEAD..origin/main --count');
            $commitsBehind = $countResult->successful() ? (int) trim($countResult->output()) : 0;

            // Available updates (capped at 50)
            $availableCommits = [];
            if ($commitsBehind > 0) {
                $logResult = Process::path($repoPath)->timeout(10)
                    ->run('git log HEAD..origin/main --format="%h|%s|%cr" -50');
                if ($logResult->successful()) {
                    $availableCommits = $this->parseCommitLog($logResult->output());
                }
            }

            // Recent history (last 20 installed commits)
            $recentHistory = [];
            $historyResult = Process::path($repoPath)->timeout(10)
                ->run('git log HEAD --format="%h|%s|%cr" -20');
            if ($historyResult->successful()) {
                $recentHistory = $this->parseCommitLog($historyResult->output());
            }

            $data = [
                'commits_behind' => $commitsBehind,
                'available_commits' => $availableCommits,
                'recent_history' => $recentHistory,
                'checked_at' => now()->toDateTimeString(),
                'error' => null,
            ];

            Cache::put(self::CACHE_KEY_UPDATES, $data, self::CACHE_TTL_UPDATES);

            return $data;
        } catch (\Throwable $e) {
            Log::warning('[Version] Update check failed: ' . $e->getMessage());

            return $this->cacheUpdateError($e->getMessage());
        }
    }

    /**
     * Refresh the current version cache. Called after deploy.
     */
    public function refreshCurrent(): array
    {
        Cache::forget(self::CACHE_KEY_CURRENT);

        return $this->current();
    }

    /**
     * Clear all version caches.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_CURRENT);
        Cache::forget(self::CACHE_KEY_UPDATES);
    }

    private function readCurrentFromGit(): array
    {
        $repoPath = base_path();

        try {
            $commitHash = trim(Process::path($repoPath)->timeout(5)->run('git rev-parse HEAD')->output());
            $commitShort = substr($commitHash, 0, 7);
            $commitDate = trim(Process::path($repoPath)->timeout(5)->run('git log -1 --format=%ci')->output());

            // Handle detached HEAD
            $branch = trim(Process::path($repoPath)->timeout(5)->run('git rev-parse --abbrev-ref HEAD')->output());
            if ($branch === 'HEAD') {
                $branch = trim(Process::path($repoPath)->timeout(5)->run('git describe --tags --always')->output());
                $branch = "detached at {$branch}";
            }

            return [
                'commit_hash' => $commitHash,
                'commit_short' => $commitShort,
                'commit_date' => $commitDate,
                'branch' => $branch,
                'deploy_timestamp' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[Version] Failed to read git info: ' . $e->getMessage());

            return [
                'commit_hash' => 'unknown',
                'commit_short' => 'unknown',
                'commit_date' => null,
                'branch' => 'unknown',
                'deploy_timestamp' => now()->toDateTimeString(),
            ];
        }
    }

    private function parseCommitLog(string $output): array
    {
        $commits = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) === 3) {
                $commits[] = [
                    'hash' => $parts[0],
                    'subject' => $parts[1],
                    'date' => $parts[2],
                ];
            }
        }

        return $commits;
    }

    private function cacheUpdateError(string $message): array
    {
        $data = [
            'commits_behind' => 0,
            'available_commits' => [],
            'recent_history' => [],
            'checked_at' => now()->toDateTimeString(),
            'error' => $message,
        ];

        Cache::put(self::CACHE_KEY_UPDATES, $data, self::CACHE_TTL_UPDATES);

        return $data;
    }
}
