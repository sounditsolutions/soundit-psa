<?php

namespace App\Services\AppRiver;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppRiverLicenseSyncService
{
    public function __construct(
        private readonly AppRiverClient $client,
    ) {}

    /**
     * Sync subscription seat counts from AppRiver for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('appriver_customer_id')
            ->where('is_active', true)
            ->get()
            ->keyBy('appriver_customer_id');

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[AppRiverSync] No clients mapped to AppRiver customers');

            return $result;
        }

        $seenLicenseIds = [];
        $successfulClientIds = [];

        foreach ($clients as $appriverCustomerId => $client) {
            try {
                $ids = $this->syncClientSubscriptions($client, $appriverCustomerId, $result);
                $seenLicenseIds = array_merge($seenLicenseIds, $ids);
                $successfulClientIds[] = $client->id;
            } catch (\Throwable $e) {
                Log::error("[AppRiverSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
                // Don't add to successfulClientIds — skip stale cleanup for failed clients
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        // Deactivate stale licenses only for clients we successfully synced
        $this->deactivateStale($seenLicenseIds, $successfulClientIds, $result);

        // Deactivate orphaned licenses (clients where mapping was removed)
        $result->deactivated += License::deactivateOrphaned('appriver', 'appriver_customer_id');

        // Retry any queued seat reductions (may succeed at start of new billing cycle)
        $this->retryScheduledReductions();

        return $result;
    }

    /**
     * Push a seat count change to AppRiver and re-sync the subscription.
     *
     * @throws AppRiverClientException on API failure or guard violation
     */
    public function updateQuantity(License $license, int $newQuantity, ?int $userId = null): void
    {
        $license->loadMissing(['licenseType', 'client']);

        // Guards
        if (! $license->vendor_ref) {
            throw new AppRiverClientException('License has no vendor_ref (subscription key).');
        }

        if (! $license->licenseType || $license->licenseType->vendor !== 'appriver') {
            throw new AppRiverClientException('License is not an AppRiver license.');
        }

        $customerId = $license->client?->appriver_customer_id;
        if (! $customerId) {
            throw new AppRiverClientException('Client has no AppRiver customer mapping.');
        }

        if ($newQuantity < 1) {
            throw new AppRiverClientException('Seat count must be at least 1.');
        }

        if ($license->assigned_quantity !== null && $newQuantity < $license->assigned_quantity) {
            throw new AppRiverClientException(
                "Cannot reduce to {$newQuantity} — {$license->assigned_quantity} seats are currently assigned."
            );
        }

        $oldQuantity = $license->quantity;

        // Push to AppRiver — if decrease is rejected, queue for retry at next billing cycle
        try {
            $this->client->updateSubscriptionQuantity($customerId, $license->vendor_ref, $newQuantity);
        } catch (AppRiverClientException $e) {
            if ($newQuantity < $oldQuantity && str_contains($e->getMessage(), 'refundable limit')) {
                $license->update(['scheduled_quantity' => $newQuantity]);
                Log::warning("[AppRiver] Queued reduction for {$license->licenseType->name} on {$license->client->name}: {$oldQuantity} → {$newQuantity} (next billing cycle) by user {$userId}");

                throw new AppRiverClientException(
                    "Reduction queued — will be applied automatically at the next billing cycle. Current: {$oldQuantity}, scheduled: {$newQuantity}."
                );
            }
            throw $e;
        }

        // Clear any pending scheduled change since the immediate update succeeded
        $license->scheduled_quantity = null;

        Log::warning("[AppRiver] Seat count changed for {$license->licenseType->name} on {$license->client->name}: {$oldQuantity} → {$newQuantity} by user {$userId}");

        // Brief pause for async processing, then re-fetch
        sleep(2);

        try {
            $detail = $this->client->getSubscriptionDetail($customerId, $license->vendor_ref);
            $counts = $this->extractLicenseCounts($detail);

            $license->update([
                'quantity' => $counts['total'] ?? $newQuantity,
                'assigned_quantity' => $counts['assigned'],
                'scheduled_quantity' => null,
                'synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Async update may not have applied yet — update optimistically
            Log::info("[AppRiver] Re-fetch after seat update returned stale data, updating optimistically");

            $license->update([
                'quantity' => $newQuantity,
                'scheduled_quantity' => null,
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * Sync all subscriptions for a single client. Returns array of seen license IDs.
     */
    private function syncClientSubscriptions(Client $client, string $customerId, SyncResult $result): array
    {
        $subscriptions = $this->client->getSubscriptions($customerId);

        if (! is_array($subscriptions)) {
            return [];
        }

        $seenLicenseIds = [];

        foreach ($subscriptions as $sub) {
            $status = $sub['SubscriptionStatus'] ?? '';
            if (! in_array($status, ['Active', 'Trial'])) {
                continue;
            }

            $subscriptionKey = $sub['SubscriptionKey'] ?? null;
            $productName = $sub['ProductName'] ?? null;

            if (! $subscriptionKey || ! $productName) {
                continue;
            }

            try {
                $detail = $this->client->getSubscriptionDetail($customerId, $subscriptionKey);
                $counts = $this->extractLicenseCounts($detail);

                $licenseType = LicenseType::updateOrCreate(
                    ['vendor' => 'appriver', 'vendor_sku_id' => Str::slug($productName)],
                    ['name' => $productName, 'is_active' => true],
                );

                $license = License::updateOrCreate(
                    [
                        'license_type_id' => $licenseType->id,
                        'client_id' => $client->id,
                        'vendor_ref' => $subscriptionKey,
                    ],
                    [
                        'quantity' => $counts['total'] ?? 0,
                        'assigned_quantity' => $counts['assigned'],
                        'status' => 'active',
                        'synced_at' => now(),
                    ],
                );

                // Clear scheduled_quantity if the actual quantity now matches or is below the target
                if ($license->scheduled_quantity !== null
                    && ($counts['total'] ?? 0) <= $license->scheduled_quantity) {
                    $license->update(['scheduled_quantity' => null]);
                }

                $seenLicenseIds[] = $license->id;

                if ($license->wasRecentlyCreated) {
                    $result->created++;
                } else {
                    $result->updated++;
                }
            } catch (\Throwable $e) {
                Log::warning("[AppRiverSync] Failed subscription {$productName} for {$client->name}: {$e->getMessage()}");
                $result->recordError("Subscription {$productName} for {$client->name}: {$e->getMessage()}");
            }
        }

        return $seenLicenseIds;
    }

    /**
     * Extract TotalLicenses and AssignedLicenses from subscription detail response.
     */
    private function extractLicenseCounts(array $detail): array
    {
        $total = null;
        $assigned = null;

        $readonlyDetails = $detail['ReadonlySubscriptionDetails'] ?? [];
        foreach ($readonlyDetails as $item) {
            $name = $item['Name'] ?? '';
            $value = $item['Value'] ?? null;

            if ($name === 'TotalLicenses') {
                $total = (int) $value;
            } elseif ($name === 'AssignedLicenses') {
                $assigned = (int) $value;
            }
        }

        return ['total' => $total, 'assigned' => $assigned];
    }

    /**
     * Deactivate licenses that were not seen in this sync run (stale subscriptions).
     */
    private function deactivateStale(array $seenLicenseIds, array $mappedClientIds, SyncResult $result): void
    {
        if (empty($mappedClientIds)) {
            return;
        }

        $appriverTypeIds = LicenseType::where('vendor', 'appriver')->pluck('id');
        if ($appriverTypeIds->isEmpty()) {
            return;
        }

        $query = License::whereIn('license_type_id', $appriverTypeIds)
            ->whereIn('client_id', $mappedClientIds)
            ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('status', 'active'));

        if (! empty($seenLicenseIds)) {
            $query->whereNotIn('id', $seenLicenseIds);
        }

        $deactivated = $query->update([
            'quantity' => 0,
            'assigned_quantity' => 0,
            'status' => 'suspended',
            'synced_at' => now(),
        ]);

        $result->deactivated += $deactivated;

        if ($deactivated > 0) {
            Log::warning("[AppRiverSync] Deactivated {$deactivated} stale license(s) no longer in AppRiver");
        }
    }

    /**
     * Retry any queued seat reductions. Called during each sync run.
     * Reductions queued when AppRiver rejects immediate decreases (past refundable window).
     * May succeed at the start of a new billing cycle.
     */
    private function retryScheduledReductions(): void
    {
        $pending = License::whereNotNull('scheduled_quantity')
            ->whereColumn('scheduled_quantity', '!=', 'quantity')
            ->with(['licenseType', 'client'])
            ->get();

        foreach ($pending as $license) {
            if (! $license->seat_manageable) {
                continue;
            }

            $customerId = $license->client->appriver_customer_id;

            try {
                $this->client->updateSubscriptionQuantity(
                    $customerId,
                    $license->vendor_ref,
                    $license->scheduled_quantity,
                );

                Log::warning("[AppRiverSync] Applied queued reduction for {$license->licenseType->name} on {$license->client->name}: {$license->quantity} → {$license->scheduled_quantity}");

                // Re-fetch to get actual counts
                sleep(2);
                $detail = $this->client->getSubscriptionDetail($customerId, $license->vendor_ref);
                $counts = $this->extractLicenseCounts($detail);

                $license->update([
                    'quantity' => $counts['total'] ?? $license->scheduled_quantity,
                    'assigned_quantity' => $counts['assigned'],
                    'scheduled_quantity' => null,
                    'synced_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Still rejected — will retry next sync
                Log::info("[AppRiverSync] Queued reduction for {$license->licenseType->name} on {$license->client->name} still pending: {$e->getMessage()}");
            }
        }
    }
}
