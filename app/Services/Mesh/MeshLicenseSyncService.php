<?php

namespace App\Services\Mesh;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class MeshLicenseSyncService
{
    public function __construct(
        private readonly MeshClient $meshClient,
    ) {}

    /**
     * Sync licenses from Mesh for all clients with a mesh_customer_id.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('mesh_customer_id')
            ->where('is_active', true)
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncClientLicenses($client, $result);
            } catch (\Throwable $e) {
                Log::error("[MeshSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->errors++;
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate licenses on clients that no longer have a Mesh mapping
        $result->deactivated += License::deactivateOrphaned('mesh', 'mesh_customer_id');

        return $result;
    }

    private function syncClientLicenses(Client $client, SyncResult $result): void
    {
        $customerData = $this->meshClient->getCustomer($client->mesh_customer_id);

        if (empty($customerData)) {
            Log::warning("[MeshSync] Empty response for client {$client->name} (mesh_customer_id: {$client->mesh_customer_id})");

            return;
        }

        // Mesh returns license counts at the customer level
        $licensesBilled = (int) ($customerData['licenses_billed'] ?? 0);
        $serviceName = $customerData['service_name'] ?? 'Mesh';
        $companyName = $customerData['company_name'] ?? $client->name;

        if ($licensesBilled <= 0) {
            Log::info("[MeshSync] Client {$client->name}: 0 licenses billed, skipping");

            return;
        }

        // Upsert the license type (one per Mesh service type)
        $licenseType = LicenseType::updateOrCreate(
            [
                'vendor' => 'mesh',
                'vendor_sku_id' => $serviceName,
            ],
            [
                'name' => "Mesh: {$serviceName}",
                'is_active' => true,
            ]
        );

        // Upsert the license record for this client
        $license = License::updateOrCreate(
            [
                'license_type_id' => $licenseType->id,
                'client_id' => $client->id,
                'vendor_ref' => $client->mesh_customer_id,
            ],
            [
                'quantity' => $licensesBilled,
                'status' => ($customerData['active'] ?? true) ? 'active' : 'suspended',
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
