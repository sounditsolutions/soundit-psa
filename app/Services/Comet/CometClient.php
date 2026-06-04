<?php

namespace App\Services\Comet;

use App\Support\CometConfig;
use Illuminate\Support\Facades\Log;

class CometClient
{
    private \Comet\Server $server;

    public function __construct()
    {
        $url = rtrim(CometConfig::serverUrl(), '/').'/';
        $user = CometConfig::get('comet_admin_user');
        $password = CometConfig::get('comet_admin_password');

        $this->server = new \Comet\Server($url, $user, $password);
    }

    public function listUsersFull(): array
    {
        try {
            return $this->server->AdminListUsersFull();
        } catch (\Exception $e) {
            Log::error("[Comet] listUsersFull failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function getUserProfile(string $username): \Comet\UserProfileConfig
    {
        try {
            return $this->server->AdminGetUserProfile($username);
        } catch (\Exception $e) {
            Log::error("[Comet] getUserProfile({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function getJobsForUser(string $username): array
    {
        try {
            return $this->server->AdminGetJobsForUser($username);
        } catch (\Exception $e) {
            Log::error("[Comet] getJobsForUser({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function getJobsForDateRange(int $startTimestamp, int $endTimestamp): array
    {
        try {
            return $this->server->AdminGetJobsForDateRange($startTimestamp, $endTimestamp);
        } catch (\Exception $e) {
            Log::error("[Comet] getJobsForDateRange failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function listActive(): array
    {
        try {
            return $this->server->AdminDispatcherListActive();
        } catch (\Exception $e) {
            Log::error("[Comet] listActive failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function getOrganizations(): array
    {
        try {
            return $this->server->AdminOrganizationList();
        } catch (\Exception $e) {
            Log::error("[Comet] getOrganizations failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * List all user groups.
     */
    public function getUserGroups(): array
    {
        try {
            return $this->server->AdminUserGroupsListFull();
        } catch (\Exception $e) {
            Log::error("[Comet] getUserGroups failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Create a new user group.
     */
    public function createUserGroup(string $name): string
    {
        try {
            $result = $this->server->AdminUserGroupsNew($name);

            return $result->UserGroupID;
        } catch (\Exception $e) {
            Log::error("[Comet] createUserGroup({$name}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Set the list of users for a group.
     */
    public function setGroupUsers(string $groupId, array $usernames): void
    {
        try {
            $this->server->AdminUserGroupsSetUsersForGroup($groupId, $usernames);
        } catch (\Exception $e) {
            Log::error("[Comet] setGroupUsers({$groupId}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Create a new backup user account.
     */
    public function addUser(string $username, string $password): void
    {
        try {
            $this->server->AdminAddUser($username, $password);
        } catch (\Exception $e) {
            Log::error("[Comet] addUser({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Reset a backup user's password.
     */
    public function resetUserPassword(string $username, string $newPassword): void
    {
        try {
            $this->server->AdminResetUserPassword($username, $newPassword);
        } catch (\Exception $e) {
            Log::error("[Comet] resetUserPassword({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Create a backup user. If user already exists, logs warning but does not delete.
     * Returns true if created, false if already existed.
     */
    public function ensureUser(string $username, string $password): bool
    {
        try {
            $this->server->AdminAddUser($username, $password, 1); // StoreRecoveryCode=1

            return true;
        } catch (\Exception $e) {
            // User likely already exists — don't delete (could destroy backup data)
            Log::warning("[Comet] User {$username} may already exist: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a single-use install token for passwordless agent deployment.
     */
    public function createInstallToken(string $username, string $password): string
    {
        try {
            $serverUrl = rtrim(CometConfig::serverUrl(), '/').'/';
            $result = $this->server->AdminCreateInstallToken($username, $password, $serverUrl);

            return $result->InstallToken->Token;
        } catch (\Exception $e) {
            Log::error("[Comet] createInstallToken failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Get a user profile with atomic hash for safe read-modify-write.
     */
    public function getUserProfileAndHash(string $username): \Comet\GetProfileAndHashResponseMessage
    {
        try {
            return $this->server->AdminGetUserProfileAndHash($username);
        } catch (\Exception $e) {
            Log::error("[Comet] getUserProfileAndHash({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Write a user profile with hash verification (atomic update).
     */
    public function setUserProfileHash(string $username, \Comet\UserProfileConfig $profile, string $hash): \Comet\GetProfileAndHashResponseMessage
    {
        try {
            return $this->server->AdminSetUserProfileHash($username, $profile, $hash);
        } catch (\Exception $e) {
            Log::error("[Comet] setUserProfileHash({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    public function isHealthy(): bool
    {
        try {
            $this->server->AdminMetaVersion();

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
