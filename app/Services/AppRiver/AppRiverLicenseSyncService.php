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
        'Active', 'Trial', 'Cancelled', 'Canceled', 'Suspended', 'Expired', 'Pending', 'Deleted',
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
            // destructive cleanup of THAT subscription's licence. But it is an ordinary
            // vendor state that can persist for weeks, so it is neither of two things:
            //
            //   - an error. recordError() feeds `$result->errors > 0 ? FAILURE : SUCCESS`
            //     in the sync commands, and a subscription the vendor leaves suspended
            //     would fail every nightly run until it is restored.
            //   - grounds to withhold cleanup from the client's OTHER subscriptions.
            //     $unobserved is per-client, so counting one here excludes the whole
            //     client from deactivateStale() — and a genuinely Cancelled sibling would
            //     keep its seat count and go on billing for as long as this one stays
            //     suspended.
            //
            // So protect exactly the licence this entry names, by marking it seen: it is
            // held out of stale cleanup with its stored seat count untouched, and the rest
            // of the client is still observed normally.
            if (in_array($status, self::INCONCLUSIVE_SUBSCRIPTION_STATUSES, true)) {
                $inconclusiveKey = $sub['SubscriptionKey'] ?? null;

                if (! $inconclusiveKey) {
                    // An entry with no key names no licence, so nothing can be held out
                    // individually — fall back to withholding cleanup for the whole client,
                    // as with any other malformed entry.
                    $unobserved++;
                    $this->recordUnobserved($result, $client, "inconclusive SubscriptionStatus {$status} with no SubscriptionKey");

                    continue;
                }

                $protectedIds = License::where('client_id', $client->id)
                    ->where('vendor_ref', $inconclusiveKey)
                    ->pluck('id')
                    ->all();

                if (empty($protectedIds)) {
                    // No licence row of ours carries this key, and that is the ORDINARY case
                    // rather than a malformed one: rows are only ever created on the
                    // Active/Trial path below, so a subscription first reported Pending — or
                    // one the vendor has left Suspended since before we held a row — has never
                    // had one. Such an entry holds nothing out of stale cleanup, but it also
                    // puts nothing at risk: there is no row here for deactivateStale() to zero.
                    //
                    // So it is neither of the two things the block above rules out. Counting it
                    // unobserved would fail every nightly run for as long as provisioning takes
                    // — indefinitely for a subscription the vendor never restores — and would
                    // withhold cleanup from the whole client while it did, letting a genuinely
                    // Cancelled sibling keep its seat count and go on billing. Log it and move
                    // on; the keyless case above still withholds, because an entry with no key
                    // says nothing about which licence it means.
                    Log::info("[AppRiverSync] Subscription {$inconclusiveKey} for {$client->name} is {$status} and matches no licence on this client; nothing to hold out of stale cleanup");

                    continue;
                }

                $seenLicenseIds = array_merge($seenLicenseIds, $protectedIds);

                Log::info("[AppRiverSync] Subscription {$inconclusiveKey} for {$client->name} is {$status}; leaving its licence as it stands and out of stale cleanup");

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

                $identity = [
                    'license_type_id' => $licenseType->id,
                    'client_id' => $client->id,
                    'vendor_ref' => $subscriptionKey,
                ];

                // Read the row BEFORE updateOrCreate overwrites its status. A suspended
                // row carrying a queued reduction is one an older build zeroed and left
                // queued — this build clears at the point of zeroing — and reviving it
                // is the one path that makes that instruction sendable again: quantity
                // comes back at the CURRENT seat count, so the queued value sits below
                // it, the retry guard passes, and a months-old instruction bills a
                // resubscription down unattended. The instruction was written against a
                // subscription that has since ended; it does not carry over.
                $revived = License::where($identity)
                    ->where('status', 'suspended')
                    ->whereNotNull('scheduled_quantity')
                    ->first();

                $license = License::updateOrCreate($identity, [
                    'quantity' => $counts['total'] ?? 0,
                    'assigned_quantity' => $counts['assigned'],
                    'status' => 'active',
                    'synced_at' => now(),
                ]);

                if ($revived) {
                    Log::warning("[AppRiverSync] Discarding queued seat reduction to {$revived->scheduled_quantity} for {$productName} on {$client->name}: the subscription was suspended and has been reported again at {$license->quantity} seats, so the queued value predates the subscription it would now change. Re-queue it if the reduction is still wanted.");
                    $result->recordWithdrawn("Queued seat reduction to {$revived->scheduled_quantity} discarded for licence #{$revived->id} ({$productName}) on {$client->name} — subscription {$subscriptionKey} returned after suspension");
                    $license->update(['scheduled_quantity' => null]);
                }

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
     *
     * The queued reduction is cleared with the rest of the row, and every clear is
     * NAMED in the log first, because it is an operator's billing instruction and
     * dropping one in silence is its own defect.
     *
     * Keeping it instead was tried and is worse. Staleness here is decided by absence
     * from one getSubscriptions() response, so the argument for keeping it — a paging
     * glitch is indistinguishable from a cancellation, and quantity self-heals while a
     * cleared queue does not — is real but narrow: this method only runs for clients
     * where every subscription was positively observed, so a failed or malformed entry
     * already excludes the client entirely. What it cannot survive is reactivation. A
     * queue left standing on a zeroed row has no expiry, and if AppRiver ever reissues
     * the SubscriptionKey, updateOrCreate() revives THIS row at the new seat count;
     * the `total <= scheduled` clear does not fire when the new count is larger, so a
     * months-old instruction passes the guard below and silently bills a new
     * subscription down. An instruction the operator can re-issue is the cheaper loss.
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

        // Read the queued instructions before the bulk update destroys them, so each
        // one is named rather than dropped in silence. The row set is the same one the
        // update below acts on.
        foreach ((clone $query)->whereNotNull('scheduled_quantity')->with(['licenseType', 'client'])->get() as $discarded) {
            Log::warning("[AppRiverSync] Discarding queued seat reduction to {$discarded->scheduled_quantity} for {$discarded->licenseType?->name} on {$discarded->client?->name}: AppRiver no longer reports the subscription, so there is nothing left to reduce. Re-queue it if the subscription returns.");
            $result->recordWithdrawn("Queued seat reduction to {$discarded->scheduled_quantity} discarded for licence #{$discarded->id} ({$discarded->licenseType?->name}) on {$discarded->client?->name} — subscription {$discarded->vendor_ref} no longer reported by AppRiver");
        }

        $deactivated = $query->update([
            'quantity' => 0,
            'assigned_quantity' => 0,
            'scheduled_quantity' => null,
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

            // scheduled_quantity is only ever WRITTEN on the reduction path
            // (updateQuantity() queues it solely when $newQuantity < $oldQuantity), so a
            // queued value above the current quantity does not mean "increase to this" —
            // it means quantity moved down underneath the queue after it was written.
            // Sending it is an outbound billing INCREASE nobody asked for, so this
            // refuses rather than guesses. The stale-cleanup case that motivated it is
            // closed at source in deactivateStale(); what reaches here is what a clear
            // cannot repair — above all a row an EARLIER build already zeroed and left
            // queued, which the stale query can no longer see (quantity 0, already
            // suspended) and which is sitting in the database right now. It also
            // subsumes "the licence is suspended", because a suspended row is zeroed
            // and a queued value is always >= 1.
            //
            // Refuse, do not clear. Here the row's history is unknown — this is the
            // path that meets state no code in this build created — so leaving the
            // instruction for a person to read beats a guard deleting billing intent
            // it does not understand. deactivateStale() clears because it knows
            // exactly why, and says so in the log when it does.
            if ($license->scheduled_quantity > $license->quantity) {
                Log::warning("[AppRiverSync] Refusing queued seat change for {$license->licenseType->name} on {$license->client->name}: scheduled {$license->scheduled_quantity} exceeds current {$license->quantity}, which would be an increase, not the queued reduction");

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
