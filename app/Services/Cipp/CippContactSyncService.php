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
            ->where('is_active', true)
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
     */
    public function syncClientContacts(Client $client, SyncResult $result, bool $dryRun = false): void
    {
        $lock = self::acquireLock("cipp-contact-sync:{$client->id}");

        if (! $lock) {
            Log::info("[CippContactSync] Skipping {$client->name} — sync already in progress");

            return;
        }

        try {
            $this->doSyncClientContacts($client, $result, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function doSyncClientContacts(Client $client, SyncResult $result, bool $dryRun): void
    {
        $tenantDomain = $client->cipp_tenant_domain;
        $groupId = $client->cipp_sync_group_id;

        // Fetch all users from the tenant
        $users = $this->client->listUsers($tenantDomain);

        if (! is_array($users) || empty($users)) {
            Log::info("[CippContactSync] No users returned for {$client->name}");

            return;
        }

        $fetchSucceeded = true;

        // Filter by group if configured
        if ($groupId) {
            $users = $this->filterByGroup($users, $tenantDomain, $groupId);
        }

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

        // Stale cleanup — only if fetch succeeded, only synced persons
        if ($fetchSucceeded && ! $dryRun) {
            $staleQuery = Person::where('client_id', $client->id)
                ->whereNotNull('cipp_user_id')
                ->where('is_active', true);

            if (! empty($seenPersonIds)) {
                $staleQuery->whereNotIn('id', $seenPersonIds);
            }

            $staleCount = $staleQuery->count();

            if ($staleCount > 0) {
                $staleQuery->update([
                    'is_active' => false,
                    'cipp_synced_at' => now(),
                ]);
                $result->deactivated += $staleCount;
                Log::info("[CippContactSync] Deactivated {$staleCount} stale contact(s) for {$client->name}");
            }
        } elseif ($fetchSucceeded && $dryRun) {
            $stalePersons = Person::where('client_id', $client->id)
                ->whereNotNull('cipp_user_id')
                ->where('is_active', true)
                ->when(! empty($seenPersonIds), fn ($q) => $q->whereNotIn('id', $seenPersonIds))
                ->get(['first_name', 'last_name', 'email']);

            $staleCount = $stalePersons->count();
            foreach ($stalePersons as $stale) {
                $result->details[] = [
                    'action' => 'deactivate',
                    'client' => $client->name,
                    'name' => trim("{$stale->first_name} {$stale->last_name}"),
                    'email' => $stale->email ?? '',
                ];
            }
            $result->deactivated += $staleCount;
        }

        // Enrich with mailbox + MFA data (independent API calls, each in try/catch)
        if ($fetchSucceeded && ! $dryRun) {
            $enrichment = new CippContactEnrichmentService($this->client);
            $enrichment->enrichForClient($client, $result);
        }
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
     */
    private function filterByGroup(array $users, string $tenantDomain, string $groupId): array
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
                // Skip user on group check failure (safe default — exclude)
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
