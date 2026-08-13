<?php

namespace App\Services\Cipp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Services\PersonService;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CippContactSyncService
{
    public function __construct(
        private readonly CippClient $client,
        private readonly PersonService $personService,
    ) {}

    /**
     * Sync M365 users from CIPP for all mapped clients.
     */
    public function syncContacts(?callable $onProgress = null, bool $dryRun = false): SyncResult
    {
        $clients = Client::whereNotNull('cipp_tenant_domain')
            ->operational()
            ->get();

        $result = new SyncResult;

        if ($clients->isEmpty()) {
            Log::info('[CippContactSync] No clients mapped to CIPP tenants');

            return $result;
        }

        foreach ($clients as $client) {
            try {
                $this->syncClientContacts($client, $result, $dryRun);
            } catch (\Throwable $e) {
                Log::error("[CippContactSync] Failed for client {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    /**
     * Sync contacts for a single client.
     *
     * Reports what this call actually did — see CippSyncOutcome. Only Synced means the
     * tenant was read and its users processed; a lock skip, an empty upstream read and
     * an unverified roster all leave $result untouched or partial, and a caller that
     * cannot tell them apart reports "no changes" for work that never happened. The
     * scheduled path can ignore the answer; an on-demand caller must not.
     */
    public function syncClientContacts(Client $client, SyncResult $result, bool $dryRun = false): CippSyncOutcome
    {
        $lock = self::acquireLock("cipp-contact-sync:{$client->id}");

        if (! $lock) {
            Log::info("[CippContactSync] Skipping {$client->name} — sync already in progress");

            return CippSyncOutcome::SkippedLocked;
        }

        try {
            return $this->doSyncClientContacts($client, $result, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function doSyncClientContacts(Client $client, SyncResult $result, bool $dryRun): CippSyncOutcome
    {
        $tenantDomain = $client->cipp_tenant_domain;
        $groupId = $client->cipp_sync_group_id;

        // Fetch all users from the tenant
        $users = $this->client->listUsers($tenantDomain);

        if (! is_array($users) || empty($users)) {
            // Nothing was READ, so nothing may be concluded: a throttled or degraded CIPP
            // answers with an empty payload exactly as a genuinely empty tenant does.
            Log::warning("[CippContactSync] No users returned for {$client->name} — tenant not read, roster left untouched");

            return CippSyncOutcome::NoUsersRead;
        }

        // A roster is verified only if EVERY user this pass read was accounted for. Stale
        // cleanup deactivates whoever is missing from the seen-list, and a user an upstream
        // failure hid is missing in exactly the way a departed user is — so the test is
        // "nobody unaccounted for", not "at least one survivor".
        $rosterVerified = true;

        // Filter by group if configured
        if ($groupId) {
            $groupLookupFailures = 0;
            $users = $this->filterByGroup($users, $tenantDomain, $groupId, $groupLookupFailures);

            // filterByGroup() excludes every user whose group check threw, so one bad spell
            // on the group endpoint yields an empty set that looks identical to an empty
            // group. Stop before stale cleanup — it would deactivate the whole roster.
            if (empty($users)) {
                Log::warning("[CippContactSync] Group filter left 0 users for {$client->name} — roster unverified, skipping stale cleanup");

                return CippSyncOutcome::RosterUnverified;
            }

            // A PARTIAL failure is no more verified than a total one: 199 failed lookups and
            // one success is not "one member", it is one member and 199 unknowns. Keep the
            // users we could read (creates/updates are additive), but the cleanup below must
            // not run — one surviving lookup would otherwise deactivate the rest.
            if ($groupLookupFailures > 0) {
                Log::warning("[CippContactSync] {$groupLookupFailures} group lookup(s) failed for {$client->name} — roster unverified, stale cleanup will be skipped");
                $rosterVerified = false;
            }
        }

        // Per-user failures unverify the roster the same way — a user whose syncUser() threw
        // never reaches $seenPersonIds. Measure from a baseline, not from zero: the scheduled
        // pass shares ONE SyncResult across every client.
        $errorsBefore = $result->errors;

        $seenPersonIds = [];

        foreach ($users as $userData) {
            $objectId = $userData['id'] ?? $userData['Id'] ?? null;
            if (! $objectId) {
                continue;
            }

            try {
                $person = $this->syncUser($client, $userData, $objectId, $dryRun);
                if ($person) {
                    $seenPersonIds[] = $person->id;
                    $displayName = trim(($userData['givenName'] ?? $userData['GivenName'] ?? '').' '.($userData['surname'] ?? $userData['Surname'] ?? ''))
                        ?: ($userData['displayName'] ?? $userData['DisplayName'] ?? 'Unknown');
                    $email = $userData['mail'] ?? $userData['Mail'] ?? '';

                    if ($person->wasRecentlyCreated) {
                        $result->created++;
                        if ($dryRun) {
                            $result->details[] = ['action' => 'create', 'client' => $client->name, 'name' => $displayName, 'email' => $email];
                        }
                    } else {
                        $result->updated++;
                        if ($dryRun) {
                            $result->details[] = ['action' => 'update', 'client' => $client->name, 'name' => $displayName, 'email' => $email];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[CippContactSync] Failed syncing user {$objectId} for {$client->name}: {$e->getMessage()}");
                $result->recordError("{$client->name}: {$e->getMessage()}");
            }
        }

        // Stale cleanup runs ONLY against a roster this pass actually verified. An empty
        // seen-list means we matched nobody — which a transient upstream failure produces
        // as readily as a real change — and an unfiltered deactivation there wipes every
        // CIPP-synced person for the client, stripping contract assignments and portal
        // access. Skip cleanup and tell the caller the roster is unverified instead.
        if (empty($seenPersonIds)) {
            Log::warning("[CippContactSync] No users matched for {$client->name} — skipping stale cleanup, roster unverified");

            return CippSyncOutcome::RosterUnverified;
        }

        // The same invariant one step down, and the reason the guard above is not enough:
        // cleanup runs ONLY against a roster this pass verified end to end. Any user hidden
        // by a failed group lookup or a failed syncUser() is absent from $seenPersonIds and
        // would be deactivated as though the tenant had removed them. A partial pass keeps
        // what it wrote and reports the roster unverified instead.
        if (! $rosterVerified || $result->errors > $errorsBefore) {
            Log::warning("[CippContactSync] Roster for {$client->name} not fully verified this pass — skipping stale cleanup");

            return CippSyncOutcome::RosterUnverified;
        }

        if (! $dryRun) {
            $staleQuery = Person::where('client_id', $client->id)
                ->whereNotNull('cipp_user_id')
                ->where('is_active', true)
                ->whereNotIn('id', $seenPersonIds);

            $staleCount = $staleQuery->count();

            if ($staleCount > 0) {
                $staleQuery->update([
                    'is_active' => false,
                    'cipp_synced_at' => now(),
                ]);
                $result->deactivated += $staleCount;
                Log::info("[CippContactSync] Deactivated {$staleCount} stale contact(s) for {$client->name}");
            }

            // Enrich with mailbox + MFA data (independent API calls, each in try/catch)
            $enrichment = new CippContactEnrichmentService($this->client);
            $enrichment->enrichForClient($client, $result);
        } else {
            $stalePersons = Person::where('client_id', $client->id)
                ->whereNotNull('cipp_user_id')
                ->where('is_active', true)
                ->whereNotIn('id', $seenPersonIds)
                ->get(['first_name', 'last_name', 'email']);

            foreach ($stalePersons as $stale) {
                $result->details[] = [
                    'action' => 'deactivate',
                    'client' => $client->name,
                    'name' => trim("{$stale->first_name} {$stale->last_name}"),
                    'email' => $stale->email ?? '',
                ];
            }
            $result->deactivated += $stalePersons->count();
        }

        return CippSyncOutcome::Synced;
    }

    /**
     * Sync a single M365 user to a Person record.
     */
    private function syncUser(Client $client, array $userData, string $objectId, bool $dryRun): ?Person
    {
        $email = $userData['mail'] ?? $userData['Mail'] ?? null;
        $accountEnabled = $userData['accountEnabled'] ?? $userData['AccountEnabled'] ?? true;

        // Build sync data — null-safe (only overwrite with non-null M365 values)
        $firstName = $userData['givenName'] ?? $userData['GivenName'] ?? null;
        $lastName = $userData['surname'] ?? $userData['Surname'] ?? null;

        $syncData = array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email ? mb_strtolower(trim($email)) : null,
            'job_title' => $userData['jobTitle'] ?? $userData['JobTitle'] ?? null,
            'mobile' => $userData['mobilePhone'] ?? $userData['MobilePhone'] ?? null,
            'phone' => $this->extractBusinessPhone($userData),
            'department' => $userData['department'] ?? $userData['Department'] ?? null,
            'office_location' => $userData['officeLocation'] ?? $userData['OfficeLocation'] ?? null,
            'cipp_upn' => $userData['userPrincipalName'] ?? $userData['UserPrincipalName'] ?? null,
        ], fn ($v) => $v !== null);

        // Always set these fields regardless of null
        $syncData['cipp_user_id'] = $objectId;
        $syncData['cipp_synced_at'] = now();
        $syncData['is_active'] = (bool) $accountEnabled;
        $syncData['m365_user_type'] = $userData['userType'] ?? $userData['UserType'] ?? null;
        $syncData['is_hybrid'] = isset($userData['onPremisesSyncEnabled'])
            ? (bool) $userData['onPremisesSyncEnabled']
            : null;

        // Match: cipp_user_id first (including soft-deleted), then email, then create
        $person = Person::withTrashed()
            ->where('client_id', $client->id)
            ->where('cipp_user_id', $objectId)
            ->first();

        if ($person && $person->trashed()) {
            if ($dryRun) {
                return $person; // Count as update in dry-run
            }
            $person->restore();
            $this->personService->updatePerson($person, $syncData);

            return $person->fresh();
        }

        if (! $person && ! empty($syncData['email'])) {
            $person = Person::where('client_id', $client->id)
                ->whereNull('cipp_user_id')
                ->whereEmailMatch($syncData['email'])
                ->first();
        }

        if ($person) {
            if ($dryRun) {
                return $person; // Count as update in dry-run
            }
            $this->personService->updatePerson($person, $syncData);

            return $person->fresh();
        }

        // Create new person
        if ($dryRun) {
            // Return a fake person that reports wasRecentlyCreated = true
            $fake = new Person($syncData);
            $fake->wasRecentlyCreated = true;

            return $fake;
        }

        $syncData['client_id'] = $client->id;
        $syncData['person_type'] = PersonType::User->value;
        $syncData['portal_enabled'] = false;

        return $this->personService->createPerson($syncData);
    }

    /**
     * Filter users to only those in the specified group.
     * Caps at 200 users for group membership checks to prevent API overload.
     *
     * $failedLookups counts the users excluded because their group check THREW, not because
     * they are not members. The returned list cannot tell the two apart, and treating an
     * unknown as a non-member is precisely what deactivates a live roster — so the count is
     * reported out rather than left in a debug log.
     */
    private function filterByGroup(array $users, string $tenantDomain, string $groupId, int &$failedLookups): array
    {
        if (count($users) > 200) {
            Log::warning("[CippContactSync] Tenant {$tenantDomain} has ".count($users).' users — skipping group filter (cap: 200)');

            return $users;
        }

        $filtered = [];

        foreach ($users as $user) {
            // Use Azure AD objectId for group checks — UPNs with #EXT# cause CIPP 500 errors
            $userId = $user['id'] ?? $user['Id'] ?? null;
            if (! $userId) {
                continue;
            }

            try {
                $groups = $this->client->listUserGroups($tenantDomain, $userId);

                foreach ($groups as $group) {
                    $gid = $group['id'] ?? $group['Id'] ?? null;
                    if ($gid === $groupId) {
                        $filtered[] = $user;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("[CippContactSync] Failed to check groups for user {$userId}: {$e->getMessage()}");
                // Skip user on group check failure (safe default — exclude), but REPORT it:
                // an excluded unknown must never reach stale cleanup as a non-member.
                $failedLookups++;
            }
        }

        Log::info('[CippContactSync] Group filter: '.count($filtered).'/'.count($users)." users matched group {$groupId}");

        return $filtered;
    }

    private function extractBusinessPhone(array $userData): ?string
    {
        $phones = $userData['businessPhones'] ?? $userData['BusinessPhones'] ?? [];

        return is_array($phones) && ! empty($phones) ? $phones[0] : null;
    }

    /**
     * Acquire a cache lock, handling missing file cache directories gracefully.
     * The file cache driver uses nested hash dirs (e.g., /5e/0c/) that can go missing
     * after cache:clear or deploys. On failure, clears cache to rebuild dirs and retries.
     *
     * @return \Illuminate\Contracts\Cache\Lock|null Lock instance if acquired, null if already held
     */
    public static function acquireLock(string $key, int $ttl = 600): ?\Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock($key, $ttl);

        try {
            return $lock->get() ? $lock : null;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'No such file or directory')) {
                // Rebuild cache directory structure and retry
                Artisan::call('cache:clear');
                $lock = Cache::lock($key, $ttl);

                return $lock->get() ? $lock : null;
            }
            throw $e;
        }
    }
}
