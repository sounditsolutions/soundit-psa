<?php

namespace App\Services\Servosity;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class ServosityLicenseSyncService
{
    /**
     * Mapping of Servosity account_counts keys to license type definitions.
     */
    private const ACCOUNT_TYPES = [
        'Mailboxes' => ['vendor_sku_id' => 'm365_mailboxes', 'name' => 'Servosity M365 Backup'],
        'DRS' => ['vendor_sku_id' => 'dr_server', 'name' => 'Servosity DR Server'],
        'DRD' => ['vendor_sku_id' => 'dr_desktop', 'name' => 'Servosity DR Desktop'],
        'Std' => ['vendor_sku_id' => 'standard', 'name' => 'Servosity Standard Backup'],
        'Pro' => ['vendor_sku_id' => 'pro', 'name' => 'Servosity Pro Backup'],
        'NAS' => ['vendor_sku_id' => 'nas', 'name' => 'Servosity NAS Backup'],
    ];

    public function __construct(
        private readonly ServosityClient $client,
    ) {}

    /**
     * Sync backup license counts from Servosity for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('servosity_company_id')
            ->operational()
            ->get()
            ->keyBy('servosity_company_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[ServositySync] No clients mapped to Servosity companies');

            return $result;
        }

        try {
            $companies = $this->client->getCompanies();
        } catch (\Throwable $e) {
            Log::warning("[ServositySync] Failed to fetch companies: {$e->getMessage()}");
            $result->recordError("Failed to fetch companies: {$e->getMessage()}");

            return $result;
        }

        $seenClientIds = [];

        foreach ($companies as $company) {
            $companyId = $company['id'] ?? null;
            if (! $companyId) {
                continue;
            }

            $client = $clients->get($companyId);
            if (! $client) {
                continue; // Not mapped
            }

            $seenClientIds[] = $client->id;

            try {
                $this->syncCompanyLicenses($client, $company, $result);
            } catch (\Throwable $e) {
                Log::warning("[ServositySync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Zero out licenses for mapped clients no longer in API response
        // Only safe because the full fetch succeeded (no early return from exception)
        $this->deactivateMissingClients($clients, $seenClientIds, $result);

        // Deactivate licenses on clients that no longer have a Servosity mapping
        $result->deactivated += License::deactivateOrphaned('servosity', 'servosity_company_id');

        return $result;
    }

    private function syncCompanyLicenses(Client $client, array $company, SyncResult $result): void
    {
        $companyRef = (string) $company['id'];
        $accountCounts = $company['account_counts'] ?? [];

        foreach (self::ACCOUNT_TYPES as $apiKey => $def) {
            $quantity = (int) ($accountCounts[$apiKey] ?? 0);

            // Skip zero-count license types to avoid noise
            if ($quantity === 0) {
                continue;
            }

            $this->upsertLicense(
                vendorSkuId: $def['vendor_sku_id'],
                name: $def['name'],
                client: $client,
                vendorRef: $companyRef,
                quantity: $quantity,
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
        SyncResult $result,
    ): void {
        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'servosity', 'vendor_sku_id' => $vendorSkuId],
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
                'status' => 'active',
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

        // Get all servosity license type IDs
        $servosityTypeIds = LicenseType::where('vendor', 'servosity')->pluck('id');

        if ($servosityTypeIds->isEmpty()) {
            return;
        }

        $deactivated = License::whereIn('license_type_id', $servosityTypeIds)
            ->whereIn('client_id', $missingClientIds)
            ->where('quantity', '>', 0)
            ->update([
                'quantity' => 0,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);

        $result->deactivated += $deactivated;

        if ($deactivated > 0) {
            Log::warning("[ServositySync] Deactivated {$deactivated} license(s) for clients no longer in Servosity");
        }
    }
}
