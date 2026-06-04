<?php

namespace App\Services\Huntress;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class HuntressLicenseSyncService
{
    public function __construct(
        private readonly HuntressClient $huntressClient,
    ) {}

    /**
     * Sync EDR + ITDR license counts from Huntress for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('huntress_organization_id')
            ->where('is_active', true)
            ->get()
            ->keyBy('huntress_organization_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[HuntressSync] No clients mapped to Huntress organizations');

            return $result;
        }

        try {
            $organizations = $this->huntressClient->getOrganizations([
                'id', 'name', 'agents_count', 'billable_identity_count', 'sat_learner_count',
            ]);
        } catch (\Throwable $e) {
            Log::error("[HuntressSync] Failed to fetch organizations: {$e->getMessage()}");
            $result->recordError("Failed to fetch organizations: {$e->getMessage()}");

            return $result;
        }

        foreach ($organizations as $org) {
            $orgId = $org['id'] ?? null;
            if (! $orgId) {
                continue;
            }

            $client = $clients->get($orgId);
            if (! $client) {
                continue; // Not mapped
            }

            try {
                $this->syncOrgLicenses($client, $org, $result);
            } catch (\Throwable $e) {
                Log::error("[HuntressSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate licenses on clients that no longer have a Huntress mapping
        $result->deactivated += License::deactivateOrphaned('huntress', 'huntress_organization_id');

        return $result;
    }

    private function syncOrgLicenses(Client $client, array $org, SyncResult $result): void
    {
        $orgRef = (string) $org['id'];

        $licenseTypes = [
            [
                'vendor' => 'huntress',
                'vendorSkuId' => 'managed_edr',
                'name' => 'Huntress Managed EDR',
                'field' => 'agents_count',
            ],
            [
                'vendor' => 'huntress',
                'vendorSkuId' => 'managed_itdr',
                'name' => 'Huntress Managed ITDR',
                'field' => 'billable_identity_count',
            ],
            [
                'vendor' => 'huntress',
                'vendorSkuId' => 'managed_sat',
                'name' => 'Huntress Security Awareness Training',
                'field' => 'sat_learner_count',
            ],
        ];

        foreach ($licenseTypes as $lt) {
            $quantity = (int) ($org[$lt['field']] ?? 0);

            $this->upsertLicense(
                vendor: $lt['vendor'],
                vendorSkuId: $lt['vendorSkuId'],
                name: $lt['name'],
                client: $client,
                vendorRef: $orgRef,
                quantity: $quantity,
                isActive: $quantity > 0,
                result: $result,
            );
        }
    }

    private function upsertLicense(
        string $vendor,
        string $vendorSkuId,
        string $name,
        Client $client,
        string $vendorRef,
        int $quantity,
        bool $isActive,
        SyncResult $result,
    ): void {
        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => $vendor, 'vendor_sku_id' => $vendorSkuId],
            ['name' => $name, 'is_active' => true],
        );

        $license = License::updateOrCreate(
            [
                'license_type_id' => $licenseType->id,
                'client_id' => $client->id,
                'vendor_ref' => $vendorRef,
            ],
            [
                'quantity' => $quantity,
                'status' => $isActive ? 'active' : 'suspended',
                'synced_at' => now(),
            ],
        );

        if ($license->wasRecentlyCreated) {
            $result->created++;
        } else {
            $result->updated++;
        }
    }
}
