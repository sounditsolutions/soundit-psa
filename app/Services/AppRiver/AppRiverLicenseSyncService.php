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
    /**
     * Statuses AppRiver is known to report. Anything outside this set is treated as
     * an unobserved subscription rather than an inactive one — see the loop.
     */
    private const KNOWN_SUBSCRIPTION_STATUSES = [
        'Active', 'Trial', 'Cancelled', 'Canceled', 'Expired', 'Deleted',
        'Suspended', 'Pending',
    ];

    private const ACTIVE_SUBSCRIPTION_STATUSES = ['Active', 'Trial'];

    /**
     * Known statuses that are NOT evidence the subscription is gone. 'Pending' is a
     * subscription mid-provisioning and 'Suspended' is one the vendor may restore —
     * treating either as an observation of absence zeroes a licence that still exists.
     * They are known (so not drift) but not conclusive (so not cleanup-eligible).
     *
     * This list is not sourced from vendor documentation — none is public. It is a
     * judgement about which statuses are safe to act destructively on, and the safe
     * default for an unlisted one is the drift path, which withholds cleanup.
     */
    private const INCONCLUSIVE_SUBSCRIPTION_STATUSES = ['Suspended', 'Pending'];

    public function __construct(
        private readonly AppRiverClient $client,
    ) {}

    /**
     * Sync subscription seat counts from AppRiver for all mapped clients.
     */
    public function syncLicenses(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('appriver_customer_id')
            ->operational()
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
                [$ids, $unobserved] = $this->syncClientSubscriptions($client, $appriverCustomerId, $result);
                $seenLicenseIds = array_merge($seenLicenseIds, $ids);

                // Stale cleanup is destructive — it zeroes and suspends licences — so a
                // client only qualifies when the run POSITIVELY observed every one of its
                // subscriptions. Both a swallowed per-subscription failure and a silently
                // skipped malformed entry leave a licence out of $seenLicenseIds without
                // throwing, and either would otherwise have it zeroed.
                if ($unobserved === 0) {
                    $successfulClientIds[] = $client->id;
                } else {
                    Log::warning("[AppRiverSync] {$unobserved} subscription(s) unobserved for {$client->name}; skipping stale cleanup for this client");
                }
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
            Log::info('[AppRiver] Re-fetch after seat update returned stale data, updating optimistically');

            $license->update([
                'quantity' => $newQuantity,
                'scheduled_quantity' => null,
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * A subscription the run could not read is a withheld-cleanup event, not a
     * detail. It goes on SyncResult so the command's exit code and the run summary
     * report it — a log line alone leaves the run reporting success while
     * destructive cleanup was silently skipped.
     */
    private function recordUnobserved(SyncResult $result, Client $client, string $reason): void
    {
        Log::warning("[AppRiverSync] Unobserved subscription for {$client->name}: {$reason}");
        $result->recordError("Unobserved subscription for {$client->name}: {$reason} — stale cleanup skipped for this client");
    }

    /**
     * Sync all subscriptions for a single client.
     *
     * @return array{0: array<int, int>, 1: int} seen licence ids, and the number of
     *                                           subscriptions the run did NOT observe —
     *                                           failed or silently skipped. A non-zero
     *                                           count must keep the client out of the
     *                                           stale-cleanup set — see the caller.
     */
    private function syncClientSubscriptions(Client $client, string $customerId, SyncResult $result): array
    {
        $subscriptions = $this->client->getSubscriptions($customerId);

        // Unreachable while getSubscriptions() is declared `: array`; kept as a
        // belt-and-braces guard. Returns 1 unobserved rather than 0 so an unreadable
        // payload can never mark the client eligible for stale cleanup.
        if (! is_array($subscriptions)) {
            return [[], 1];
        }

        $seenLicenseIds = [];
        $unobserved = 0;

        foreach ($subscriptions as $sub) {
            if (! is_array($sub)) {
                $unobserved++;
                $this->recordUnobserved($result, $client, 'subscription entry was not an object');

                continue;
            }

            $status = $sub['SubscriptionStatus'] ?? null;

            // An UNRECOGNISED status is not the same as an inactive one. A vendor
            // rename of SubscriptionStatus would make every entry fall through this
            // filter with nothing counted unobserved — the client would then look
            // fully observed with zero licences seen, and deactivateStale() would
            // zero the lot. That is precisely the drift this guard exists to stop.
            if ($status === null || ! in_array($status, self::KNOWN_SUBSCRIPTION_STATUSES, true)) {
                $unobserved++;
                $this->recordUnobserved($result, $client, 'unrecognised SubscriptionStatus '.json_encode($status));

                continue;
            }

            // A known status that is not conclusive about absence — Pending is
            // mid-provisioning, Suspended may be restored — must not license a
            // destructive cleanup. Known, so not drift; inconclusive, so unobserved.
            if (in_array($status, self::INCONCLUSIVE_SUBSCRIPTION_STATUSES, true)) {
                $unobserved++;
                $this->recordUnobserved($result, $client, "inconclusive SubscriptionStatus {$status}");

                continue;
            }

            // A known, conclusive inactive status IS an observation: the subscription
            // really is gone, and its licence should be cleaned up.
            if (! in_array($status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
                continue;
            }

            $subscriptionKey = $sub['SubscriptionKey'] ?? null;
            $productName = $sub['ProductName'] ?? null;

            if (! $subscriptionKey || ! $productName) {
                // Not a *failure* by any test below, but the subscription was not
                // observed either — its licence will be absent from $seenLicenseIds
                // and would be zeroed by deactivateStale(). A vendor payload shape
                // change must not silently suspend licences.
                $unobserved++;
                $this->recordUnobserved($result, $client, 'missing SubscriptionKey or ProductName');

                continue;
            }

            try {
                $detail = $this->client->getSubscriptionDetail($customerId, $subscriptionKey);
                $counts = $this->extractLicenseCounts($detail);

                // Null counts are NOT an observation of zero seats. extractLicenseCounts()
                // returns nulls when ReadonlySubscriptionDetails is absent or renamed, and
                // the write below defaults total to 0 — so a drifted detail payload would
                // write quantity 0 / status active with nothing counted unobserved, zeroing
                // a live seat count on the write path itself. Same rule as the envelope and
                // the status filter: unreadable is not empty.
                //
                // Handled HERE rather than by raising inside extractLicenseCounts(), because
                // that helper is shared with updateQuantity() and retryScheduledReductions()
                // and a throw would be swallowed by the latter's catch AFTER its vendor write
                // has already succeeded — leaving scheduled_quantity set and the reduction
                // re-issued every run. Those two call sites are deliberately untouched.
                if ($counts['total'] === null && $counts['assigned'] === null) {
                    $unobserved++;
                    $this->recordUnobserved($result, $client, "unreadable licence counts for subscription {$productName}");

                    continue;
                }

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
                $unobserved++;
                Log::warning("[AppRiverSync] Failed subscription {$productName} for {$client->name}: {$e->getMessage()}");
                $result->recordError("Subscription {$productName} for {$client->name}: {$e->getMessage()}");
            }
        }

        return [$seenLicenseIds, $unobserved];
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
