<?php

namespace App\Services\Cipp;

use App\Support\SafeUrlInspector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CippRestWriteClient
{
    private const TOKEN_CACHE_KEY = 'cipp_rest_write_oauth_token';

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? 'gethostbynamel';
    }

    /** @return array<int|string, mixed> */
    public function setUserSignInState(string $tenantFilter, string $userId, bool $enabled): array
    {
        return $this->send('api/ExecDisableUser', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userId,
            'Enable' => $enabled,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function revokeUserSessions(string $tenantFilter, string $userId, string $userPrincipalName): array
    {
        return $this->send('api/ExecRevokeSessions', [
            'tenantFilter' => $tenantFilter,
            'id' => $userId,
            'Username' => $userPrincipalName,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function removeUserMfaMethods(string $tenantFilter, string $userPrincipalName): array
    {
        return $this->send('api/ExecResetMFA', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setLegacyPerUserMfa(string $tenantFilter, string $userPrincipalName, string $userId, string $state): array
    {
        return $this->send('api/ExecPerUserMFA', [
            'tenantFilter' => $tenantFilter,
            'userPrincipalName' => $userPrincipalName,
            'userId' => $userId,
            'State' => $state,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function assignUserLicense(string $tenantFilter, string $userId, string $skuId): array
    {
        return $this->send('api/ExecBulkLicense', [[
            'tenantFilter' => $tenantFilter,
            'userIds' => [$userId],
            'LicenseOperation' => 'Add',
            'Licenses' => [['value' => $skuId]],
            'LicensesToRemove' => [],
            'RemoveAllLicenses' => false,
            'ReplaceAllLicenses' => false,
        ]]);
    }

    /** @return array<int|string, mixed> */
    public function removeUserLicense(string $tenantFilter, string $userId, string $skuId): array
    {
        return $this->send('api/ExecBulkLicense', [[
            'tenantFilter' => $tenantFilter,
            'userIds' => [$userId],
            'LicenseOperation' => 'Remove',
            'Licenses' => [],
            'LicensesToRemove' => [['value' => $skuId]],
            'RemoveAllLicenses' => false,
            'ReplaceAllLicenses' => false,
        ]]);
    }

    /** @return array<int|string, mixed> */
    public function convertMailbox(string $tenantFilter, string $userPrincipalName, string $mailboxType): array
    {
        return $this->send('api/ExecConvertMailbox', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'MailboxType' => $mailboxType,
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxForwardingInternal(string $tenantFilter, string $userPrincipalName, string $targetUserPrincipalName, bool $keepCopy): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => $targetUserPrincipalName,
            'ForwardExternal' => null,
            'forwardOption' => 'internalAddress',
            'KeepCopy' => $keepCopy ? 'true' : 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxForwardingExternal(string $tenantFilter, string $userPrincipalName, string $externalSmtpAddress, bool $keepCopy): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => null,
            'ForwardExternal' => $externalSmtpAddress,
            'forwardOption' => 'ExternalAddress',
            'KeepCopy' => $keepCopy ? 'true' : 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function disableMailboxForwarding(string $tenantFilter, string $userPrincipalName): array
    {
        return $this->send('api/ExecEmailForward', [
            'tenantFilter' => $tenantFilter,
            'userID' => $userPrincipalName,
            'ForwardInternal' => null,
            'ForwardExternal' => null,
            'forwardOption' => 'disabled',
            'KeepCopy' => 'false',
        ]);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxGalVisibility(string $tenantFilter, string $userPrincipalName, bool $hidden): array
    {
        return $this->send('api/ExecHideFromGAL', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'HideFromGAL' => $hidden,
        ]);
    }

    /**
     * Reset one user's M365 password. Returns the captured response body so the
     * caller can read the server-generated temp password from Results.copyField.
     *
     * @return array<int|string, mixed>
     */
    public function resetUserPassword(string $tenantFilter, string $userPrincipalName, bool $mustChange): array
    {
        return $this->send('api/ExecResetPass', [
            'tenantFilter' => $tenantFilter,
            'ID' => $userPrincipalName,
            'MustChange' => $mustChange,
        ], captureBody: true);
    }

    /**
     * Create ONE new M365 user via CIPP's AddUser endpoint and return the
     * captured response body so the caller can read the created UPN and the
     * CIPP-generated temp password from the Results copyField entries.
     *
     * Source shape (CIPP-API Invoke-AddUser.ps1 → New-CIPPUserTask.ps1 →
     * New-CippUser.ps1): POST api/AddUser with a UserObj body; the UPN is
     * composed upstream as "{username}@{Domain}" (a plain Domain string wins
     * over the frontend's PrimDomain.value autocomplete shape), so both the
     * tenantFilter AND the UPN domain here are the server-resolved tenant —
     * a caller can never plant an identity in another domain. The password
     * key is deliberately omitted: CIPP generates one (New-passwordString,
     * PwPush-aware) and returns it once in Results[].copyField. MustChangePass
     * is pinned true (passwordProfile.forceChangePasswordNextSignIn) — every
     * account is born with a must-change temp credential. An optional single
     * license rides as the licenses [{value}] autocomplete shape and is
     * assigned post-create by CIPP (Set-CIPPUserLicense); a failed license
     * step reports a "Failed …" Results string while the create itself has
     * already succeeded (HTTP 200), so reported-failure guarding is left to
     * the caller, which must still deliver the password. A failed CREATE
     * returns HTTP 500 and send() throws.
     *
     * @return array<int|string, mixed>
     */
    public function createUser(
        string $tenantFilter,
        string $username,
        string $domain,
        string $displayName,
        string $givenName,
        string $surname,
        ?string $usageLocation,
        ?string $licenseSkuId,
    ): array {
        if (trim($username) === '') {
            throw new CippClientException('New user username (UPN local part) is required');
        }
        if (trim($domain) === '') {
            throw new CippClientException('New user UPN domain is required');
        }
        if (trim($displayName) === '') {
            throw new CippClientException('New user display name is required');
        }

        $body = [
            'tenantFilter' => $tenantFilter,
            'username' => $username,
            'Domain' => $domain,
            'displayName' => $displayName,
            'givenName' => $givenName,
            'surname' => $surname,
            'MustChangePass' => true,
        ];

        if ($usageLocation !== null && trim($usageLocation) !== '') {
            $body['usageLocation'] = $usageLocation;
        }

        if ($licenseSkuId !== null && trim($licenseSkuId) !== '') {
            $body['licenses'] = [['value' => $licenseSkuId]];
        }

        return $this->send('api/AddUser', $body, captureBody: true);
    }

    /** @return array<int|string, mixed> */
    public function setMailboxOutOfOffice(
        string $tenantFilter,
        string $userPrincipalName,
        string $state,
        ?string $internalMessage,
        ?string $externalMessage,
        ?string $startTime,
        ?string $endTime,
        ?string $timezone,
    ): array {
        $body = [
            'tenantFilter' => $tenantFilter,
            'userId' => $userPrincipalName,
            'AutoReplyState' => $state,
        ];

        if ($internalMessage !== null && trim($internalMessage) !== '') {
            $body['InternalMessage'] = $internalMessage;
        }
        if ($externalMessage !== null && trim($externalMessage) !== '') {
            $body['ExternalMessage'] = $externalMessage;
        }
        if ($state === 'Scheduled') {
            $body['StartTime'] = $startTime;
            $body['EndTime'] = $endTime;
        }
        if ($timezone !== null && trim($timezone) !== '') {
            $body['timezone'] = $timezone;
        }

        return $this->send('api/ExecSetOoO', $body);
    }

    /**
     * Grant or remove a single mailbox delegate permission (FullAccess, Send-As,
     * or Send-on-Behalf) for one trustee on one mailbox via CIPP's
     * ExecEditMailboxPermissions endpoint. Each permission bucket is an array of
     * {value,label} entries (CIPP autocomplete shape); exactly one bucket is
     * populated per call and the rest are sent empty. FullAccess grants choose
     * between auto-map (AddFullAccess) and no-auto-map (AddFullAccessNoAutoMap).
     *
     * @param  string  $permission  one of full_access|send_as|send_on_behalf
     * @param  string  $operation  one of grant|remove
     * @return array<int|string, mixed>
     */
    public function setMailboxDelegate(string $tenantFilter, string $mailboxUserPrincipalName, string $trusteeUserPrincipalName, string $permission, string $operation, bool $autoMap): array
    {
        if (trim($trusteeUserPrincipalName) === '') {
            throw new CippClientException('Mailbox delegate trustee UPN is required');
        }

        $body = [
            'TenantFilter' => $tenantFilter,
            'UserID' => $mailboxUserPrincipalName,
            'AddFullAccess' => [],
            'AddFullAccessNoAutoMap' => [],
            'RemoveFullAccess' => [],
            'AddSendAs' => [],
            'RemoveSendAs' => [],
            'AddSendOnBehalf' => [],
            'RemoveSendOnBehalf' => [],
        ];

        $entry = [['value' => $trusteeUserPrincipalName, 'label' => $trusteeUserPrincipalName]];

        // Each arm gates on BOTH permission and operation so an unrecognized
        // operation falls through to the throw rather than silently removing.
        $bucket = match (true) {
            $permission === 'full_access' && $operation === 'grant' && $autoMap => 'AddFullAccess',
            $permission === 'full_access' && $operation === 'grant' => 'AddFullAccessNoAutoMap',
            $permission === 'full_access' && $operation === 'remove' => 'RemoveFullAccess',
            $permission === 'send_as' && $operation === 'grant' => 'AddSendAs',
            $permission === 'send_as' && $operation === 'remove' => 'RemoveSendAs',
            $permission === 'send_on_behalf' && $operation === 'grant' => 'AddSendOnBehalf',
            $permission === 'send_on_behalf' && $operation === 'remove' => 'RemoveSendOnBehalf',
            default => throw new CippClientException("Unsupported mailbox delegate permission/operation {$permission}/{$operation}"),
        };

        $body[$bucket] = $entry;

        return $this->send('api/ExecEditMailboxPermissions', $body);
    }

    /**
     * List the tenant's ACTIVATED Entra directory roles with their members via
     * CIPP's ListRoles endpoint. Read support for the directory-role removal
     * write: the caller-facing tool accepts only the universal role TEMPLATE id,
     * and this read is how execution re-resolves it to the tenant's activated
     * role object id (and re-verifies name + membership) at approval time.
     *
     * Source shape (CIPP-API Invoke-ListRoles.ps1): GET api/ListRoles?tenantFilter=X
     * returning a bare array of {Id, roleTemplateId, DisplayName, Description,
     * Members: [{displayName, userPrincipalName, id}], SID}.
     *
     * @return array<int, mixed>
     */
    public function listDirectoryRoles(string $tenantFilter): array
    {
        $body = $this->sendGet('api/ListRoles', ['tenantFilter' => $tenantFilter]);

        if (array_is_list($body)) {
            return $body;
        }

        // Defensive: some CIPP endpoints wrap list payloads as {"Results": [...]}.
        $results = $body['Results'] ?? null;

        return is_array($results) && array_is_list($results) ? $results : [];
    }

    /**
     * Remove ONE user from ONE assigned Entra directory (admin) role via CIPP's
     * ExecRemoveAdminRole endpoint. RoleId must be the tenant's activated
     * directoryRole OBJECT id (from listDirectoryRoles), never a caller-supplied
     * value; RoleName is the resolved display name CIPP uses for its own log
     * line; the single Users entry follows the CIPP autocomplete {value,label}
     * shape with the server-derived user object id and UPN.
     *
     * Source shape (CIPP-API Invoke-ExecRemoveAdminRole.ps1): per user, CIPP runs
     * Graph DELETE /v1.0/directoryRoles/{RoleId}/members/{UserId}/$ref and
     * returns HTTP 500 when any removal fails (send() then throws).
     *
     * @return array<int|string, mixed>
     */
    public function removeDirectoryRoleMember(string $tenantFilter, string $roleId, string $roleName, string $userId, string $userPrincipalName): array
    {
        if (trim($roleId) === '') {
            throw new CippClientException('Directory role id is required');
        }
        if (trim($userId) === '') {
            throw new CippClientException('Directory role member user id is required');
        }

        return $this->send('api/ExecRemoveAdminRole', [
            'tenantFilter' => $tenantFilter,
            'RoleId' => $roleId,
            'RoleName' => $roleName,
            'Users' => [['value' => $userId, 'label' => $userPrincipalName]],
        ]);
    }

    /**
     * Release one quarantined message to all its recipients via CIPP's
     * ExecQuarantineManagement endpoint (Release-QuarantineMessage with
     * ReleaseToAll). Only the Release action is supported — Deny/delete is not
     * exposed. The AllowSender/SenderAddress/PolicyName keys are never sent, so
     * this call can never piggyback a content-filter allow-sender policy write;
     * tenant allow-list changes go through addTenantAllowListEntry explicitly.
     * The endpoint returns HTTP 200 even when the release fails — the only
     * failure signal is the Results text, so the body is captured and checked.
     *
     * @return array<int|string, mixed>
     */
    public function releaseQuarantineMessage(string $tenantFilter, string $identity): array
    {
        if (trim($identity) === '') {
            throw new CippClientException('Quarantine message identity is required');
        }

        $response = $this->send('api/ExecQuarantineManagement', [
            'tenantFilter' => $tenantFilter,
            'Type' => 'Release',
            'Identity' => $identity,
        ], captureBody: true);

        $this->guardReportedFailure('api/ExecQuarantineManagement', $response['body']['Results'] ?? null);

        return ['success' => true, 'status' => $response['status']];
    }

    /**
     * Add ONE allow entry (Sender or Url) to the tenant's M365 Tenant
     * Allow/Block List via CIPP's AddTenantAllowBlockList endpoint. The
     * listMethod is pinned to Allow (block adds are a different capability) and
     * expiration is pinned to RemoveAfter — Exchange's remove-45-days-after-
     * last-use mode, the only expiration it accepts for allow entries — so a
     * NoExpiration allow can never be created through this wrapper. The
     * endpoint fans out to every tenant when given 'AllTenants' and returns
     * HTTP 200 on failure, so both are guarded here.
     *
     * @return array<int|string, mixed>
     */
    public function addTenantAllowListEntry(string $tenantFilter, string $listType, string $entry, string $notes): array
    {
        if (trim($tenantFilter) === '' || strcasecmp(trim($tenantFilter), 'AllTenants') === 0) {
            throw new CippClientException('Tenant allow-list writes require a single resolved tenant');
        }

        if (trim($entry) === '') {
            throw new CippClientException('Tenant allow-list entry value is required');
        }

        if (! in_array($listType, ['Sender', 'Url'], true)) {
            throw new CippClientException("Unsupported tenant allow-list type {$listType}");
        }

        $response = $this->send('api/AddTenantAllowBlockList', [
            'tenantID' => $tenantFilter,
            'entries' => [$entry],
            'listType' => $listType,
            'notes' => $notes,
            'listMethod' => 'Allow',
            'RemoveAfter' => true,
        ], captureBody: true);

        $this->guardReportedFailure('api/AddTenantAllowBlockList', $response['body']['Results'] ?? null);

        return ['success' => true, 'status' => $response['status']];
    }

    /**
     * Verification read for the quarantine-release gate: the tenant's live
     * quarantine listing (Get-QuarantineMessage metadata rows — identity,
     * sender, recipients, subject, release status; never exported message
     * content). Uses the same credential set as the write it gates, so the
     * release tool cannot outrun its own verification.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMailQuarantine(string $tenantFilter): array
    {
        $response = $this->get('api/ListMailQuarantine', ['tenantFilter' => $tenantFilter]);

        $rows = $response['body']['Results'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Both spam-filter endpoints report failure inside an HTTP 200 body: a
     * Results string (quarantine) or list of strings (allow/block list) whose
     * failing entries start with "Failed". Surface that as the same exception
     * an HTTP failure raises so callers audit it instead of reporting success.
     */
    private function guardReportedFailure(string $endpoint, mixed $results): void
    {
        $messages = is_array($results) ? $results : [$results];

        foreach ($messages as $message) {
            if (is_string($message) && str_starts_with(mb_strtolower(ltrim($message)), 'failed')) {
                throw new CippClientException("CIPP write {$endpoint} reported failure: ".mb_substr($message, 0, 300));
            }
        }
    }

    /**
     * Issue ONE Intune device lifecycle action (full wipe or retire) for one
     * managed device via CIPP's ExecDeviceAction endpoint. The action arms are
     * a closed allowlist — CIPP's default arm forwards the WHOLE JSON body to
     * Graph POST /deviceManagement/managedDevices('{GUID}')/{Action}, so an
     * uncontrolled action string would be an arbitrary Graph device call.
     *
     * Source shape (CIPP-API Invoke-ExecDeviceAction.ps1 + New-CIPPDeviceAction.ps1):
     * POST api/ExecDeviceAction with tenantFilter, GUID (the Intune managedDevice
     * id), and Action. For a full wipe the data-destroying options are pinned
     * explicitly (keepUserData/keepEnrollmentData false) so Graph-side defaults
     * can never soften an approved wipe; retire takes no options, matching the
     * CIPP frontend's own Retire device action. The endpoint 500s on failure,
     * which send() converts into a CippClientException.
     *
     * @param  string  $action  one of wipe|retire
     * @return array<int|string, mixed>
     */
    public function wipeDevice(string $tenantFilter, string $deviceId, string $action): array
    {
        if (trim($deviceId) === '') {
            throw new CippClientException('Intune device id is required');
        }

        $body = match ($action) {
            'wipe' => [
                'tenantFilter' => $tenantFilter,
                'GUID' => $deviceId,
                'Action' => 'wipe',
                'keepUserData' => false,
                'keepEnrollmentData' => false,
            ],
            'retire' => [
                'tenantFilter' => $tenantFilter,
                'GUID' => $deviceId,
                'Action' => 'retire',
            ],
            default => throw new CippClientException("Unsupported device wipe action {$action}"),
        };

        return $this->send('api/ExecDeviceAction', $body);
    }

    /**
     * Grant one successor owner (site admin) access to one user's OneDrive via
     * CIPP's ExecSharePointPerms endpoint — the ownership-handover half of
     * offboarding. UPN is the OneDrive OWNER; the successor rides in the
     * onedriveAccessUser {value,label} autocomplete shape; RemovePermission
     * false adds. URL is deliberately omitted: CIPP resolves the OneDrive site
     * URL from Graph (/users/{UPN}/Drives) server-side, so no caller-supplied
     * URL exists anywhere in this flow.
     *
     * Source shape (CIPP-API Invoke-ExecSharePointPerms.ps1 + Set-CIPPSharePointPerms.ps1):
     * per-user CSOM failures are collected into Results and still return HTTP
     * 200, so a status check alone would report success on a failed
     * reassignment — the Results text is verified for the success marker and
     * the call fails closed otherwise. The upstream Results line (successor +
     * OneDrive URL) is then discarded; callers only see success/status.
     *
     * @return array<int|string, mixed>
     */
    public function reassignOneDriveOwnership(string $tenantFilter, string $ownerUserPrincipalName, string $successorUserPrincipalName): array
    {
        if (trim($ownerUserPrincipalName) === '') {
            throw new CippClientException('OneDrive owner UPN is required');
        }
        if (trim($successorUserPrincipalName) === '') {
            throw new CippClientException('OneDrive successor UPN is required');
        }

        $response = $this->send('api/ExecSharePointPerms', [
            'tenantFilter' => $tenantFilter,
            'UPN' => $ownerUserPrincipalName,
            'RemovePermission' => false,
            'onedriveAccessUser' => ['value' => $successorUserPrincipalName, 'label' => $successorUserPrincipalName],
        ], captureBody: true);

        $results = $response['body']['Results'] ?? null;
        $text = is_array($results)
            ? implode(' ', array_map(static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : (string) json_encode($entry), $results))
            : (string) $results;

        if (stripos($text, 'Successfully') === false || stripos($text, 'Failed') !== false) {
            throw new CippClientException('CIPP did not confirm the OneDrive permission change; treat the reassignment as not applied.');
        }

        return ['success' => true, 'status' => (int) $response['status']];
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @return array<int|string, mixed>
     */
    private function send(string $endpoint, array $body, bool $captureBody = false): array
    {
        $url = $this->endpointUrl($endpoint);
        $options = $this->safeRequestOptions($url);
        $token = $this->getToken();

        $response = Http::timeout(60)
            ->acceptJson()
            ->asJson()
            ->withOptions($options)
            ->withToken($token)
            ->post($url, $body);

        if ($response->failed()) {
            throw new CippClientException("CIPP write {$endpoint} failed: HTTP {$response->status()}");
        }

        if ($captureBody) {
            // Opt-in: only the password-reset wrapper reads the upstream body (the temp
            // password comes back in Results.copyField). All other callers discard it.
            return ['success' => true, 'status' => $response->status(), 'body' => $response->json()];
        }

        return ['success' => true, 'status' => $response->status()];
    }

    /**
     * Curated GET with the same URL-safety, DNS-pinning, and token handling as
     * send(). Returns the decoded body — used only by resolution reads that
     * support a write (listDirectoryRoles), never exposed as a generic getter.
     *
     * @param  array<string, string>  $query
     * @return array<int|string, mixed>
     */
    private function sendGet(string $endpoint, array $query): array
    {
        $url = $this->endpointUrl($endpoint);
        $options = $this->safeRequestOptions($url);
        $token = $this->getToken();

        $response = Http::timeout(60)
            ->acceptJson()
            ->withOptions($options)
            ->withToken($token)
            ->get($url, $query);

        if ($response->failed()) {
            throw new CippClientException("CIPP read {$endpoint} failed: HTTP {$response->status()}");
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    private function get(string $endpoint, array $query): array
    {
        $url = $this->endpointUrl($endpoint);
        $options = $this->safeRequestOptions($url);
        $token = $this->getToken();

        $response = Http::timeout(60)
            ->acceptJson()
            ->withOptions($options)
            ->withToken($token)
            ->get($url, $query);

        if ($response->failed()) {
            throw new CippClientException("CIPP read {$endpoint} failed: HTTP {$response->status()}");
        }

        return ['success' => true, 'status' => $response->status(), 'body' => $response->json()];
    }

    private function getToken(): string
    {
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        $applicationId = (string) (($this->config['application_id'] ?? null) ?: $clientId);

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new CippClientException('CIPP REST write client credentials are not configured');
        }

        $cacheKey = $this->tokenCacheKey($tenantId, $clientId, $applicationId);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$applicationId}/.default",
                ])
                ->throw();
        } catch (RequestException $e) {
            Log::error('[CippRestWriteClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP REST write OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        } catch (\Throwable $e) {
            Log::error('[CippRestWriteClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP REST write OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new CippClientException('CIPP REST write OAuth response missing access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function tokenCacheKey(string $tenantId, string $clientId, string $applicationId): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId.'|'.$applicationId);
    }

    private function endpointUrl(string $endpoint): string
    {
        $apiUrl = (string) ($this->config['api_url'] ?? '');
        if ($apiUrl === '') {
            throw new CippClientException('CIPP API URL is not configured');
        }

        return rtrim($apiUrl, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRequestOptions(string $url): array
    {
        $rejection = SafeUrlInspector::reject($url, $this->resolver);
        if ($rejection !== null) {
            throw new CippClientException(str_replace('Tactical API URL', 'CIPP API URL', $rejection));
        }

        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        $resolver = $this->resolver;
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            throw new CippClientException("CIPP API host '{$host}' did not resolve (refused for safety).");
        }

        foreach ($ips as $ip) {
            if (! SafeUrlInspector::ipIsSafe($ip)) {
                throw new CippClientException("CIPP API host '{$host}' resolved to a private or reserved address ({$ip}); refused.");
            }
        }

        $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $ips)]];

        return $options;
    }
}
