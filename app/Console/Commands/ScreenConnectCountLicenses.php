<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\License;
use App\Models\LicenseType;
use App\Support\ScreenConnectConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScreenConnectCountLicenses extends Command
{
    protected $signature = 'screenconnect:count-licenses';
    protected $description = 'Count ScreenConnect Access agents per client and update license records';

    public function handle(): int
    {
        if (! ScreenConnectConfig::isConfigured()) {
            $this->error('ScreenConnect is not configured.');
            return self::FAILURE;
        }

        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'screenconnect', 'vendor_sku_id' => 'access_agents'],
            ['name' => 'ScreenConnect Access Agents', 'is_active' => true],
        );

        $counts = Asset::whereNotNull('screenconnect_session_id')
            ->whereNotNull('client_id')
            ->where('is_active', true)
            ->select('client_id', DB::raw('COUNT(*) as agent_count'))
            ->groupBy('client_id')
            ->pluck('agent_count', 'client_id');

        $updated = 0;
        foreach ($counts as $clientId => $count) {
            License::updateOrCreate(
                ['license_type_id' => $licenseType->id, 'client_id' => $clientId],
                ['quantity' => $count, 'status' => 'active', 'synced_at' => now()],
            );
            $updated++;
        }

        License::where('license_type_id', $licenseType->id)
            ->whereNotIn('client_id', $counts->keys())
            ->where('status', 'active')
            ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);

        $this->info("Updated {$updated} client license counts.");

        Log::info('[ScreenConnect] License count updated', [
            'clients' => $updated,
            'total_agents' => $counts->sum(),
        ]);

        return self::SUCCESS;
    }
}
