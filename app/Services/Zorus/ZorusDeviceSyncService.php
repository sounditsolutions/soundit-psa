<?php

namespace App\Services\Zorus;

use App\Models\Asset;
use App\Models\Client;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ZorusDeviceSyncService
{
    public function __construct(
        private readonly ZorusClient $client,
    ) {}

    public function syncDevices(?callable $onProgress = null): SyncResult
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

        // NOTE: customerUuid filter on POST /endpoints/search is unreliable as of 2026-02.
        // Fetch all and filter client-side. Paginate in case count exceeds single page.
        $allEndpoints = [];
        $page = 1;
        $fetchSucceeded = false;

        try {
            do {
                $batch = $this->client->searchEndpoints([], $page, 500);
                $allEndpoints = array_merge($allEndpoints, $batch);
                $page++;
            } while (count($batch) === 500);

            $fetchSucceeded = true;
        } catch (\Throwable $e) {
            Log::warning("[ZorusSync] Failed to fetch endpoints: {$e->getMessage()}");
            $result->recordError("Failed to fetch endpoints: {$e->getMessage()}");

            return $result;
        }

        // Group endpoints by customer UUID
        $endpointsByCustomer = [];
        foreach ($allEndpoints as $endpoint) {
            $customerUuid = $endpoint['customerUuid'] ?? null;
            if ($customerUuid) {
                $endpointsByCustomer[$customerUuid][] = $endpoint;
            }
        }

        // Sync each mapped client's endpoints
        foreach ($clients as $zorusId => $client) {
            $customerEndpoints = $endpointsByCustomer[$zorusId] ?? [];

            try {
                $this->syncEndpointsForClient($client, $customerEndpoints, $fetchSucceeded, $result);
            } catch (\Throwable $e) {
                Log::warning("[ZorusSync] Device sync failed for {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    public function syncEndpointsForClient(Client $client, array $endpoints, bool $fetchSucceeded, SyncResult $result): void
    {
        $matchedAssetIds = [];

        foreach ($endpoints as $endpoint) {
            $endpointUuid = $endpoint['uuid'] ?? null;
            $endpointName = $endpoint['name'] ?? null;

            if (! $endpointUuid) {
                continue;
            }

            // Match: first by zorus_endpoint_id (re-sync), then by hostname (scoped to client)
            $asset = Asset::where('client_id', $client->id)
                ->where('zorus_endpoint_id', $endpointUuid)
                ->first();

            $matchedByHostname = false;

            if (! $asset && $endpointName) {
                $lowerName = mb_strtolower($endpointName);
                $shortName = mb_strtolower(explode('.', $endpointName)[0]);

                $asset = Asset::where('client_id', $client->id)
                    ->whereNull('zorus_endpoint_id')
                    ->where(function ($q) use ($lowerName, $shortName) {
                        $q->whereRaw('LOWER(hostname) = ?', [$lowerName]);
                        if ($lowerName !== $shortName) {
                            $q->orWhereRaw('LOWER(hostname) = ?', [$shortName]);
                        }
                    })
                    ->first();

                $matchedByHostname = $asset !== null;
            }

            if (! $asset) {
                Log::debug("[ZorusSync] No asset match for endpoint '{$endpointName}' (UUID: {$endpointUuid})", [
                    'client' => $client->name,
                ]);

                continue;
            }

            // #8278: the asset was matched by hostname, so it is about to take the
            // endpoint id. The id can still be held by a soft-deleted asset: the lookups
            // above run under the SoftDeletes scope and cannot see it, but the
            // single-column unique key on zorus_endpoint_id does, so the update would fail
            // on every sync for this client. Such a trashed holder is released in the same
            // transaction as the update. Only a trashed holder of this client is released:
            // both the lookup below and the locked re-read in releaseTrashedHolder are
            // onlyTrashed() and scoped to the client, so a live holder, or a trashed holder
            // under another client, still fails loud on the unique key.
            $trashedHolder = $matchedByHostname
                ? Asset::onlyTrashed()
                    ->where('client_id', $client->id)
                    ->where('zorus_endpoint_id', $endpointUuid)
                    ->first()
                : null;

            if ($trashedHolder) {
                $releasedFrom = DB::transaction(function () use ($trashedHolder, $client, $endpointUuid, $asset, $endpoint): ?int {
                    $releasedFrom = $this->releaseTrashedHolder($trashedHolder->id, $client->id, $endpointUuid);
                    $this->updateAssetFromEndpoint($asset, $endpoint);

                    return $releasedFrom;
                });

                if ($releasedFrom !== null) {
                    Log::warning('[ZorusSync] Released endpoint id from a trashed asset to the hostname-matched asset', [
                        'trashed_asset_id' => $releasedFrom,
                        'asset_id' => $asset->id,
                        'endpoint_uuid' => $endpointUuid,
                    ]);
                }
            } else {
                $this->updateAssetFromEndpoint($asset, $endpoint);
            }

            $matchedAssetIds[] = $asset->id;
            $result->updated++;
        }

        // Clear stale: only if the full API fetch succeeded (prevents wiping valid links on partial failure)
        if ($fetchSucceeded) {
            $staleCleared = Asset::where('client_id', $client->id)
                ->whereNotNull('zorus_endpoint_id')
                ->when(! empty($matchedAssetIds), fn ($q) => $q->whereNotIn('id', $matchedAssetIds))
                ->update([
                    'zorus_endpoint_id' => null,
                    'zorus_group_name' => null,
                    'zorus_filtering_enabled' => null,
                    'zorus_cybersight_enabled' => null,
                    'zorus_agent_version' => null,
                    'zorus_agent_state' => null,
                    'zorus_last_seen_at' => null,
                    'zorus_synced_at' => now(),
                ]);

            if ($staleCleared > 0) {
                $result->deactivated += $staleCleared;
                Log::info("[ZorusSync] Cleared stale Zorus data from {$staleCleared} asset(s)", [
                    'client' => $client->name,
                ]);
            }
        }
    }

    /**
     * #8278: inside the caller's transaction, lock the trashed row and clear its
     * endpoint id. The row is re-read under the lock and must still be trashed, still
     * belong to the syncing client and still hold the id; if it no longer does (restored,
     * moved, cleared or force-deleted since it was found), nothing is released and null
     * is returned, and the caller's update meets the unique key as the database stands.
     * Only the id is cleared: the trashed row's
     * other Zorus fields are left as they were.
     * Returns the released asset's id, or null when nothing was released.
     */
    private function releaseTrashedHolder(int $trashedAssetId, int $clientId, string $endpointUuid): ?int
    {
        $held = Asset::onlyTrashed()
            ->whereKey($trashedAssetId)
            ->where('client_id', $clientId)
            ->where('zorus_endpoint_id', $endpointUuid)
            ->lockForUpdate()
            ->first();

        if (! $held) {
            return null;
        }

        $held->zorus_endpoint_id = null;
        $held->save();

        return $held->id;
    }

    /**
     * Update a single asset from a Zorus endpoint API response.
     */
    public function updateAssetFromEndpoint(Asset $asset, array $endpoint): void
    {
        $lastSeen = isset($endpoint['lastSeenDateUtc'])
            ? Carbon::parse($endpoint['lastSeenDateUtc'])
            : null;

        $asset->update([
            'zorus_endpoint_id' => $endpoint['uuid'],
            'zorus_group_name' => $endpoint['groupName'] ?? null,
            'zorus_filtering_enabled' => $endpoint['isFilteringEnabled'] ?? null,
            'zorus_cybersight_enabled' => $endpoint['isCyberSightEnabled'] ?? null,
            'zorus_agent_version' => $endpoint['version'] ?? null,
            'zorus_agent_state' => $endpoint['agentState'] ?? null,
            'zorus_last_seen_at' => $lastSeen,
            'zorus_synced_at' => now(),
        ]);
    }
}
