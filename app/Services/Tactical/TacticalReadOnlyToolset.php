<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\TacticalConfig;
use Illuminate\Support\Facades\Log;

class TacticalReadOnlyToolset
{
    private const TOOL_NAMES = [
        'tactical_get_device',
        'tactical_get_device_checks',
        'tactical_get_device_network',
        'tactical_get_device_software',
        'tactical_get_device_services',
        'tactical_get_device_disks',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_values(array_filter(
            TriageToolDefinitions::tacticalTools(),
            fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), self::TOOL_NAMES, true),
        ));
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::TOOL_NAMES, true);
    }

    public function execute(string $toolName, array $input, int $clientId): array
    {
        if (! TacticalConfig::isConfigured()) {
            return ['error' => 'Tactical RMM is not configured'];
        }

        return match ($toolName) {
            'tactical_get_device' => $this->getDevice($input, $clientId),
            'tactical_get_device_checks' => $this->getDeviceChecks($input, $clientId),
            'tactical_get_device_network' => $this->getDeviceNetwork($input, $clientId),
            'tactical_get_device_software' => $this->getDeviceSoftware($input, $clientId),
            'tactical_get_device_services' => $this->getDeviceServices($input, $clientId),
            'tactical_get_device_disks' => $this->getDeviceDisks($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /**
     * @return array{agent_id: string, tactical_asset: TacticalAsset}|null
     */
    private function resolveAgent(string $hostname, int $clientId): ?array
    {
        $tacticalAsset = TacticalAsset::with('asset')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->first();

        if (! $tacticalAsset || ! $tacticalAsset->asset || $tacticalAsset->asset->client_id !== $clientId) {
            return null;
        }

        return ['agent_id' => (string) $tacticalAsset->agent_id, 'tactical_asset' => $tacticalAsset];
    }

    /** @return array{error: string}|array{agent_id: string, tactical_asset: TacticalAsset} */
    private function resolvedFromInput(array $input, int $clientId): array
    {
        $hostname = trim((string) ($input['hostname'] ?? ''));
        if ($hostname === '') {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveAgent($hostname, $clientId);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        return $resolved;
    }

    private function getDevice(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical device query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $checksSummary = null;
        $checks = $agent['checks'] ?? null;
        if (is_array($checks) && isset($checks['total'])) {
            $failing = (int) ($checks['failing'] ?? 0);
            $total = (int) $checks['total'];
            $checksSummary = "{$failing} failing / {$total} total";
        }

        return [
            'hostname' => $agent['hostname'] ?? $hostname,
            'status' => $agent['status'] ?? null,
            'os' => $agent['operating_system'] ?? null,
            'cpu' => $agent['cpu_model'] ?? null,
            'ram_gb' => TacticalFieldMap::ramGb($agent['total_ram'] ?? null),
            'make_model' => $agent['make_model'] ?? null,
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $agent['local_ips'] ?? null,
            'logged_in_user' => in_array($agent['logged_in_username'] ?? null, [null, '', 'None'], true)
                ? null
                : $agent['logged_in_username'],
            'needs_reboot' => $agent['needs_reboot'] ?? false,
            'uptime' => TacticalFieldMap::uptimeFromBootTime($agent['boot_time'] ?? null),
            'checks_summary' => $checksSummary,
        ];
    }

    private function getDeviceChecks(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $checks = app(TacticalClient::class)->getAgentChecks($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical checks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($check) => [
            'name' => $check['name'] ?? $check['readable_desc'] ?? 'Unknown',
            'status' => $check['check_result']['status'] ?? $check['status'] ?? 'unknown',
            'retcode' => $check['check_result']['retcode'] ?? null,
            'stdout' => mb_substr($check['check_result']['stdout'] ?? '', 0, 500),
        ], array_slice($checks, 0, 50));
    }

    private function getDeviceNetwork(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical network query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return TacticalFieldMap::mapNetwork($agent);
    }

    private function getDeviceSoftware(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $software = app(TacticalClient::class)->getSoftware($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical software query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        usort($software, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_map(fn ($softwareRow) => [
            'name' => $softwareRow['name'] ?? 'Unknown',
            'version' => $softwareRow['version'] ?? null,
            'publisher' => $softwareRow['publisher'] ?? null,
        ], array_slice($software, 0, 50));
    }

    private function getDeviceServices(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical services query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $services = collect($agent['services'] ?? []);
        $filter = isset($input['filter']) ? trim((string) $input['filter']) : '';

        if ($filter !== '') {
            $filterLower = mb_strtolower($filter);
            $services = $services->filter(function ($service) use ($filterLower) {
                if ($filterLower === 'running') {
                    return mb_strtolower($service['status'] ?? '') === 'running';
                }

                if ($filterLower === 'stopped') {
                    return mb_strtolower($service['status'] ?? '') === 'stopped';
                }

                return str_contains(mb_strtolower($service['display_name'] ?? $service['name'] ?? ''), $filterLower)
                    || str_contains(mb_strtolower($service['name'] ?? ''), $filterLower);
            });
        }

        return $services->take(50)->map(fn ($service) => [
            'name' => $service['name'] ?? null,
            'display_name' => $service['display_name'] ?? null,
            'status' => $service['status'] ?? null,
            'start_type' => $service['start_type'] ?? null,
        ])->values()->toArray();
    }

    private function getDeviceDisks(array $input, int $clientId): array
    {
        $resolved = $this->resolvedFromInput($input, $clientId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $hostname = (string) ($input['hostname'] ?? '');

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Tactical disks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return [
            'volumes' => collect($agent['disks'] ?? [])->take(10)->map(fn ($disk) => [
                'drive' => $disk['device'] ?? null,
                'total_gb' => TacticalFieldMap::diskSizeToGb($disk['total'] ?? null),
                'free_gb' => TacticalFieldMap::diskSizeToGb($disk['free'] ?? null),
                'percent_used' => $disk['percent'] ?? null,
                'fstype' => $disk['fstype'] ?? null,
            ])->toArray(),
            'physical_disks' => collect($agent['physical_disks'] ?? [])->take(10)->map(fn ($disk) => [
                'model' => $disk['caption'] ?? $disk['model'] ?? null,
                'size_gb' => isset($disk['size']) ? round($disk['size'] / 1073741824, 1) : null,
                'interface' => $disk['interface_type'] ?? null,
                'status' => $disk['status'] ?? null,
            ])->toArray(),
            'wmi_disk' => collect($agent['wmi_detail']['disk'] ?? [])->take(10)->map(fn ($disk) => [
                'caption' => $disk['Caption'] ?? null,
                'size_gb' => isset($disk['Size']) ? round($disk['Size'] / 1073741824, 1) : null,
                'free_gb' => isset($disk['FreeSpace']) ? round($disk['FreeSpace'] / 1073741824, 1) : null,
            ])->toArray(),
        ];
    }
}
