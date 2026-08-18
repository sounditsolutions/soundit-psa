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

        $customerTypes = $this->fetchCustomerTypes();

        $seenLicenseIds = [];
        $successfulClientIds = [];

        foreach ($clients as $appriverCustomerId => $client) {
            // A Referred customer buys from AppRiver directly — Sound IT takes a
            // percentage but is not the reseller, so the partner API has no access to
            // its subscriptions BY DESIGN (Charlie, 2026-08-18: "We don't have or need
            // the same access"). Syncing one can only produce the vendor's correct
            // refusal, so it is skipped at info, not attempted and logged at error.
            //
            // Exactly 'Referred', not "anything non-Resold": 'Partner' is our own
            // record and any type the vendor adds later is unmeasured — both fall
            // through to a normal sync attempt, whose per-client catch is loud and
            // withholds cleanup. Only the type the vendor is known to refuse is
            // silenced. A skipped client never enters $successfulClientIds, so stale
            // cleanup cannot touch whatever rows it holds.
            if (($customerTypes[$appriverCustomerId] ?? null) === 'Referred') {
                // By-design, but never invisible. A skipped client's licence rows keep
                // their last-synced quantity for as long as the type says Referred, and
                // those quantities are billed from — a reclassification or a mis-typed
                // record would otherwise freeze them behind one info line, with the run
                // reporting 'no changes' and exiting SUCCESS. recordSkipped() puts it in
                // the run summary without failing the run, the same split as
                // recordWithdrawn(): loud in the summary, silent in the exit status.
                Log::info("[AppRiverSync] Skipping {$client->name}: CustomerType Referred; no partner access by design");
                $result->recordSkipped("Skipped {$client->name}: CustomerType Referred; no partner access by design — its licences were not synced and stale cleanup was withheld");

                continue;
            }

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

                    // The affirmative outcome needs a per-client record of its own. The
                    // conclusive-inactive line in the status filter tells the operator the
                    // cleanup decision is logged separately, and only the withheld branch
                    // below had such a line: deactivateStale() emits nothing but an aggregate
                    // count that names no client, and nothing at all when it zeroes nothing.
                    // Without this, a client that DID qualify leaves no trace, and that
                    // pointer sends the operator looking for evidence that does not exist.
                    Log::info("[AppRiverSync] All subscriptions observed for {$client->name}; stale cleanup runs for this client");
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
     * Index CustomerType by customer id, one GET per run.
     *
     * The sync's worklist comes from the DB — a mapping holds only the GUID, so the
     * type has to be read from the customer list. Read fresh each run rather than
     * persisted: a client the reseller converts between Referred and Resold changes
     * type with no PSA-side event to update a stored copy on.
     *
     * Failure here must not fail the run. The filter only exists to skip clients the
     * vendor would refuse anyway; without it the sync degrades to exactly the
     * pre-filter behaviour — each Referred client errors per-client, loudly, and is
     * excluded from stale cleanup by the existing catch. So: warn and return empty,
     * and an id absent from the list (or carrying no readable type) is left unknown,
     * which syncs normally for the same reason.
     */
    private function fetchCustomerTypes(): array
    {
        $types = [];

        try {
            foreach ($this->client->getCustomers() as $customer) {
                if (! is_array($customer)) {
                    continue;
                }

                $id = $customer['CustomerId'] ?? null;
                $type = $customer['CustomerType'] ?? null;

                if ($id !== null && is_string($type)) {
                    $types[$id] = $type;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[AppRiverSync] Could not read the customer list for CustomerType filtering: {$e->getMessage()} — syncing all mapped clients without the Referred skip");

            return [];
        }

        return $types;
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
     *                                           Licences held out on an inconclusive
     *                                           status are included in the seen ids —
     *                                           that is what keeps them out of stale
     *                                           cleanup; their queued reductions are
     *                                           withdrawn at the point of observation,
     *                                           so no later pass needs to tell them
     *                                           apart.
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

                // Hold the DATA, withdraw the INSTRUCTION. The seat count, the status and
                // the row itself are left exactly as they stand — that is the whole point
                // of the inconclusive guard — but a queued seat reduction is not data
                // about the subscription, it is a pending outbound write against it, and
                // the vendor has just said it does not know what state that subscription
                // is in.
                //
                // This RESTORES behaviour rather than inventing it. Before the
                // inconclusive guards, a Suspended subscription was read as absence, so
                // its licence went through deactivateStale() — which names each queued
                // instruction and clears scheduled_quantity in the same update. A queued
                // reduction surviving a vendor suspension has never existed in this
                // system; the guard removed the clearing as a side effect of stopping the
                // zeroing. Without this, the row keeps its full seat count, so the queued
                // value sits BELOW quantity, passes the retry guard untouched, and the
                // reduction is pushed to a subscription the vendor is reporting suspended.
                //
                // Withdrawn, not held: the instruction is genuinely retired and the
                // operator re-queues it if they still want it. That is the recoverable
                // failure — loud in the summary, nothing sent — where carrying it across
                // the interruption fails by issuing an unattended billing write months
                // after it was written. On ANY inconclusive status, Pending included:
                // making the behaviour depend on which one would build on AppRiver
                // semantics nobody has, which is what this guard exists to stop.
                foreach (License::whereIn('id', $protectedIds)->whereNotNull('scheduled_quantity')->with(['licenseType'])->get() as $queued) {
                    Log::warning("[AppRiverSync] Discarding queued seat reduction to {$queued->scheduled_quantity} for {$queued->licenseType?->name} on {$client->name}: AppRiver reports subscription {$inconclusiveKey} as {$status}, so the state the reduction was written against no longer holds. The licence itself is left untouched. Re-queue the reduction once the subscription is reported active again.");
                    $result->recordWithdrawn("Queued seat reduction to {$queued->scheduled_quantity} discarded for licence #{$queued->id} ({$queued->licenseType?->name}) on {$client->name} — subscription {$inconclusiveKey} reported {$status}");
                    $queued->update(['scheduled_quantity' => null]);
                }

                continue;
            }

            // A known status this build treats as CONCLUSIVE about absence — Cancelled,
            // Canceled, Expired, Deleted. It is an observation, so the entry does not
            // count unobserved; the licence is simply not marked seen, and stale cleanup
            // will zero it.
            //
            // Stated as what we do rather than as what is true of the subscription. The
            // split between these statuses and INCONCLUSIVE_SUBSCRIPTION_STATUSES is a
            // judgement with no vendor documentation behind it — the constant's own
            // docblock says so — so "the subscription really is gone" asserts the very
            // thing we have no evidence for, on the branch that acts destructively.
            //
            // Log the status on the way past, because silently dropping the entry here is
            // what makes that judgement unfalsifiable: every other branch of this filter
            // names the status it saw, and this was the last one that did not. When the
            // credential is live again, this line is what says whether a real cancelled
            // subscription comes back as Cancelled or as something we have never seen.
            if (! in_array($status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
                $inactiveKey = json_encode($sub['SubscriptionKey'] ?? null);

                // Say only what this point in the run knows. Eligibility is NOT decided here:
                // this method returns an unobserved count, and syncLicenses() keeps the whole
                // client out of deactivateStale() unless that count is zero — so a single
                // unrecognised or malformed sibling later in this same loop leaves this seat
                // count exactly as it stands. Asserting eligibility from inside the loop would
                // tell an operator the opposite of what the run did on precisely the payload
                // they are investigating, so this line names the status and stops there; the
                // withheld-cleanup case has its own warning in the caller, and so now does the
                // affirmative one.
                Log::info("[AppRiverSync] Subscription {$inactiveKey} for {$client->name} reported conclusive inactive SubscriptionStatus {$status}; not holding its licence out of stale cleanup — whether cleanup runs for this client is decided once every subscription has been read, and is logged separately");

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
                //
                // This catches the ZEROED row only, and since the inconclusive-status
                // guards that is no longer the only way a subscription's state changes
                // underneath a queued reduction. A subscription reported Suspended and
                // later Active never leaves the payload: its licence is held out of
                // cleanup, keeps its seat count, and so comes back here as an ORDINARY
                // active row this query does not match. It does not need to. The
                // instruction was already withdrawn on the night the vendor first
                // reported the subscription inconclusive, at the point in the status
                // filter that knew why — so by the time the subscription returns there is
                // nothing left on the row to fire, and nothing for this query to catch.
                //
                // The two sites answer the same question — has the subscription changed
                // under the instruction — at the only two places the run can see it
                // happen: absence, and an inconclusive status. Neither subsumes the
                // other.
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
     *
     * Note what this method no longer sees. Since the inconclusive-status guards, a
     * subscription the vendor reports Suspended does NOT reach here: its licence is
     * marked seen and held out, so the row keeps its seat count and its status. This
     * clause is therefore no longer the only place a queued instruction is retired when
     * a subscription's state moves under it — the status filter does the same thing, on
     * the same reasoning, for the licences this method is deliberately not shown. The
     * hazard is one premise with two observation points, not two defects.
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
     *
     * Nothing here can tell an interrupted subscription from a healthy one, and it is
     * not asked to. A licence whose subscription the vendor reports on an inconclusive
     * status keeps its seat count, so its queued value sits BELOW quantity and reads as
     * ordinary — the status filter clears such an instruction where it observes it,
     * before this pass runs, which is the only point in the run that knows.
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
            // suspended) and which is sitting in the database right now.
            //
            // It used to subsume "the licence is suspended" as well, because every
            // suspended row was a zeroed one and a queued value is always >= 1. That
            // reasoning DIED with the inconclusive-status guards: a subscription the
            // vendor reports Suspended now keeps its row live, its seat count and — but
            // for the withdrawal in the status filter — its queued value, so it would
            // reach this loop looking like any other active licence and pass this test.
            // The clear happens at the point of observation instead, where the status is
            // actually known. This guard no longer covers that case and must not be read
            // as though it does.
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
