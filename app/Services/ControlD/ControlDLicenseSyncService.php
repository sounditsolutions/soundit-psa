<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class ControlDLicenseSyncService
{
    private const LICENSE_TYPES = [
        [
            'vendorSkuId' => 'endpoints',
            'name' => 'Control D Endpoints',
            'field' => 'users',
        ],
        [
            'vendorSkuId' => 'routers',
            'name' => 'Control D Routers',
            'field' => 'routers',
        ],
    ];

    public function __construct(
        private readonly ControlDClient $client,
    ) {}

    /**
     * Sync endpoint and router device counts from Control D for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('controld_org_id')
            ->operational()
            ->get()
            ->keyBy('controld_org_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[ControlDSync] No clients mapped to Control D sub-organizations');

            return $result;
        }

        try {
            $subOrgs = $this->client->getSubOrganizations();
        } catch (\Throwable $e) {
            Log::warning("[ControlDSync] Failed to fetch sub-organizations: {$e->getMessage()}");
            $result->recordError("Failed to fetch sub-organizations: {$e->getMessage()}");

            return $result;
        }

        $seenClientIds = [];

        foreach ($subOrgs as $org) {
            $orgPk = $org['PK'] ?? null;
            if (! $orgPk) {
                continue;
            }

            // Skip disabled/inactive sub-orgs (status 1 = active)
            $status = $org['status'] ?? null;
            if ($status !== null && $status !== 1) {
                continue;
            }

            $client = $clients->get($orgPk);
            if (! $client) {
                continue; // Not mapped
            }

            $seenClientIds[] = $client->id;

            try {
                $this->syncOrgLicenses($client, $org, $result);
            } catch (\Throwable $e) {
                Log::warning("[ControlDSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Zero out licenses for mapped clients no longer in API response
        // Only safe because the full fetch succeeded (no early return from exception)
        $this->deactivateMissingClients($clients, $seenClientIds, $result);

        // Deactivate licenses on clients that no longer have a Control D mapping
        $result->deactivated += License::deactivateOrphaned('controld', 'controld_org_id');

        return $result;
    }

    private function syncOrgLicenses(Client $client, array $org, SyncResult $result): void
    {
        $orgRef = (string) $org['PK'];

        foreach (self::LICENSE_TYPES as $lt) {
            $quantity = (int) ($org[$lt['field']]['count'] ?? 0);

            $this->upsertLicense(
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
        string $vendorSkuId,
        string $name,
        Client $client,
        string $vendorRef,
        int $quantity,
        bool $isActive,
        SyncResult $result,
    ): void {
        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'controld', 'vendor_sku_id' => $vendorSkuId],
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

        $controldTypeIds = LicenseType::where('vendor', 'controld')->pluck('id');

        if ($controldTypeIds->isEmpty()) {
            return;
        }

        $deactivated = License::whereIn('license_type_id', $controldTypeIds)
            ->whereIn('client_id', $missingClientIds)
            ->where('quantity', '>', 0)
            ->update([
                'quantity' => 0,
                'status' => 'suspended',
                'synced_at' => now(),
            ]);

        $result->deactivated += $deactivated;

        if ($deactivated > 0) {
            Log::warning("[ControlDSync] Deactivated {$deactivated} license(s) for clients no longer in Control D");
        }
    }
}
