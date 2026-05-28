<?php

namespace App\Services\Printix;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class PrintixLicenseSyncService
{
    public function __construct(
        private readonly PrintixClient $client,
    ) {}

    /**
     * Sync Printix license counts for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('printix_tenant_id')
            ->where('is_active', true)
            ->get()
            ->keyBy('printix_tenant_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[PrintixSync] No clients mapped to Printix tenants');

            return $result;
        }

        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'printix', 'vendor_sku_id' => 'active_users'],
            ['name' => 'Printix Active Users', 'is_active' => true],
        );

        // Deactivate old license types from initial implementation
        LicenseType::where('vendor', 'printix')
            ->whereIn('vendor_sku_id', ['user_licenses', 'printing_users'])
            ->update(['is_active' => false]);

        $seenLicenseIds = [];

        foreach ($clients as $tenantId => $client) {
            try {
                $billing = $this->client->getBillingInfo($tenantId);

                $currentPeriod = $billing['current_billing_period'] ?? [];
                $activeUsers = $currentPeriod['active_users'] ?? 0;
                $printingUsers = $currentPeriod['printing_users'] ?? null;

                $license = License::updateOrCreate(
                    [
                        'license_type_id' => $licenseType->id,
                        'client_id' => $client->id,
                        'vendor_ref' => $tenantId,
                    ],
                    [
                        'quantity' => $activeUsers,
                        'assigned_quantity' => $printingUsers,
                        'status' => 'active',
                        'synced_at' => now(),
                    ],
                );

                $seenLicenseIds[] = $license->id;

                if ($license->wasRecentlyCreated) {
                    $result->created++;
                } else {
                    $result->updated++;
                }
            } catch (\Throwable $e) {
                Log::error("[PrintixSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate stale licenses
        if (! empty($seenLicenseIds)) {
            $stale = License::where('license_type_id', $licenseType->id)
                ->where('quantity', '>', 0)
                ->whereNotIn('id', $seenLicenseIds)
                ->update(['quantity' => 0, 'assigned_quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
            $result->deactivated += $stale;
        }

        // Deactivate orphaned licenses
        $result->deactivated += License::deactivateOrphaned('printix', 'printix_tenant_id');

        return $result;
    }
}
