<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class CippLicenseSyncService
{
    public function __construct(
        private readonly CippClient $cippClient,
    ) {}

    /**
     * Sync M365 licenses from CIPP for all clients with a cipp_tenant_domain.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('cipp_tenant_domain')
            ->operational()
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncClientLicenses($client, $result);
            } catch (\Throwable $e) {
                Log::error("[CippSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->errors++;
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate licenses on clients that no longer have a CIPP mapping
        $result->deactivated += License::deactivateOrphaned('cipp_m365', 'cipp_tenant_domain');

        return $result;
    }

    private function syncClientLicenses(Client $client, SyncResult $result): void
    {
        $licenses = $this->cippClient->listLicenses($client->cipp_tenant_domain);

        if (empty($licenses) || ! is_array($licenses)) {
            Log::info("[CippSync] No licenses returned for {$client->name} ({$client->cipp_tenant_domain})");

            return;
        }

        foreach ($licenses as $licenseData) {
            $skuId = $licenseData['skuId'] ?? $licenseData['SkuId'] ?? null;
            $skuPartNumber = $licenseData['skuPartNumber'] ?? $licenseData['SkuPartNumber'] ?? null;
            // CIPP's Get-CIPPLicenseOverview emits the friendly product name in
            // `License`; skuName / SkuName are never emitted at this layer, so they
            // were dead reads. skuPartNumber duplicates the pretty name and is the
            // last resort (psa-d6mf, verified against CIPP-API source).
            $displayName = $licenseData['License'] ?? $skuPartNumber ?? 'Unknown M365 License';
            // Seat counts are CountUsed / CountAvailable / TotalLicenses (STRINGS) —
            // NOT the raw Graph consumedUnits / prepaidUnits keys, which CIPP consumes
            // internally and never emits here. Reading consumedUnits resolved to 0 for
            // every row, so the license silently recorded its TOTAL seat count instead
            // of the used one (psa-d6mf).
            $consumed = (int) ($licenseData['CountUsed'] ?? $licenseData['countUsed'] ?? 0);
            $total = (int) ($licenseData['totalLicenses'] ?? $licenseData['TotalLicenses']
                ?? $licenseData['availableUnits'] ?? 0);

            if (! $skuId && ! $skuPartNumber) {
                continue;
            }

            $vendorSkuId = $skuId ?? $skuPartNumber;

            // Upsert the license type
            $licenseType = LicenseType::updateOrCreate(
                [
                    'vendor' => 'cipp_m365',
                    'vendor_sku_id' => $vendorSkuId,
                ],
                [
                    'name' => $displayName,
                    'is_active' => true,
                ]
            );

            // Upsert the license record — quantity is consumed units
            $license = License::updateOrCreate(
                [
                    'license_type_id' => $licenseType->id,
                    'client_id' => $client->id,
                    'vendor_ref' => $vendorSkuId,
                ],
                [
                    'quantity' => $consumed > 0 ? $consumed : $total,
                    'status' => 'active',
                    'synced_at' => now(),
                ]
            );

            if ($license->wasRecentlyCreated) {
                $result->created++;
            } else {
                $result->updated++;
            }
        }
    }
}
