<?php

namespace App\Services\Zorus;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class ZorusLicenseSyncService
{
    private const LICENSE_TYPES = [
        [
            'vendorSkuId' => 'endpoints',
            'name' => 'Zorus Endpoints',
            'field' => 'deployedEndpointCount',
        ],
        [
            'vendorSkuId' => 'filtering',
            'name' => 'Zorus Filtering',
            'field' => 'filteringEnabledCount',
        ],
        [
            'vendorSkuId' => 'cybersight',
            'name' => 'Zorus CyberSight',
            'field' => 'cyberSightEnabledCount',
        ],
    ];

    public function __construct(
        private readonly ZorusClient $client,
    ) {}

    /**
     * Sync endpoint counts from Zorus for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('zorus_customer_id')
            ->operational()
            ->get()
            ->keyBy('zorus_customer_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[ZorusSync] No clients mapped to Zorus customers');

            return $result;
        }

        // Fetch all customers from Zorus (paginate if >100)
        $allCustomers = [];
        $page = 1;

        try {
            do {
                $batch = $this->client->searchCustomers([], $page, 100);
                $allCustomers = array_merge($allCustomers, $batch);
                $page++;
            } while (count($batch) === 100);
        } catch (\Throwable $e) {
            Log::warning("[ZorusSync] Failed to fetch customers: {$e->getMessage()}");
            $result->recordError("Failed to fetch customers: {$e->getMessage()}");

            return $result;
        }

        $seenClientIds = [];

        foreach ($allCustomers as $customer) {
            $customerUuid = $customer['uuid'] ?? null;
            if (! $customerUuid) {
                continue;
            }

            $client = $clients->get($customerUuid);
            if (! $client) {
                continue; // Not mapped
            }

            $seenClientIds[] = $client->id;

            try {
                $this->syncCustomerLicenses($client, $customer, $result);
            } catch (\Throwable $e) {
                Log::warning("[ZorusSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Zero out licenses for mapped clients no longer in API response
        $this->deactivateMissingClients($clients, $seenClientIds, $result);

        // Deactivate licenses on clients that no longer have a Zorus mapping
        $result->deactivated += License::deactivateOrphaned('zorus', 'zorus_customer_id');

        return $result;
    }

    private function syncCustomerLicenses(Client $client, array $customer, SyncResult $result): void
    {
        $vendorRef = (string) $customer['uuid'];
        $deploymentInfo = $customer['deploymentInfo'] ?? [];

        foreach (self::LICENSE_TYPES as $lt) {
            $quantity = (int) ($deploymentInfo[$lt['field']] ?? 0);

            $this->upsertLicense(
                vendorSkuId: $lt['vendorSkuId'],
                name: $lt['name'],
                client: $client,
                vendorRef: $vendorRef,
                quantity: $quantity,
                isActive: $quantity > 0,
                result: $result,
            );
        }
    }

    private function upsertLicense(
        string $vendorSkuId,
        string $name,
        Client $client,
        string $vendorRef,
        int $quantity,
        bool $isActive,
        SyncResult $result,
    ): void {
        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'zorus', 'vendor_sku_id' => $vendorSkuId],
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

    /**
     * Zero out licenses for mapped clients that no longer appear in the API response.
     */
    private function deactivateMissingClients($mappedClients, array $seenClientIds, SyncResult $result): void
    {
        $missingClientIds = $mappedClients->pluck('id')->diff($seenClientIds);

        if ($missingClientIds->isEmpty()) {
            return;
        }

        $zorusTypeIds = LicenseType::where('vendor', 'zorus')->pluck('id');

        if ($zorusTypeIds->isEmpty()) {
            return;
        }

        $deactivated = License::whereIn('license_type_id', $zorusTypeIds)
            ->whereIn('client_id', $missingClientIds)
            ->where('quantity', '>', 0)
            ->update([
                'quantity' => 0,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);

        $result->deactivated += $deactivated;

        if ($deactivated > 0) {
            Log::warning("[ZorusSync] Deactivated {$deactivated} license(s) for clients no longer in Zorus");
        }
    }
}
