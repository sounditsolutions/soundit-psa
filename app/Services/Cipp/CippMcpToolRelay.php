<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Models\Person;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CippMcpToolRelay
{
    private const MAX_NESTED_ARRAY_DEPTH = 8;

    private const TOOL_MAP = [
        'cipp_list_users' => 'ListUsers',
        'cipp_list_mailboxes' => 'ListMailboxes',
        'cipp_list_licenses' => 'ListLicenses',
        'cipp_list_devices' => 'ListDevices',
        'cipp_list_groups' => 'ListGroups',
        'cipp_list_user_groups' => 'ListUserGroups',
        'cipp_list_mailbox_permissions' => 'ListmailboxPermissions',
        // ListUserMailboxRules, NOT ListMailboxRules. The latter accepts no user
        // parameter at all (its only OpenAPI params are tenantFilter and
        // UseReportDB) and returns EVERY mailbox's cached rules in the tenant,
        // silently ignoring the userId we sent — while this tool's own contract
        // requires user_id and promises one mailbox. ListUserMailboxRules reads
        // UserID and runs Get-InboxRule -Mailbox $UserID, so Exchange enforces
        // the scope server-side (psa-7lgo.1).
        'cipp_list_mailbox_rules' => 'ListUserMailboxRules',
        'cipp_list_defender_state' => 'ListDefenderState',
        'cipp_list_conditional_access_policies' => 'ListConditionalAccessPolicies',
        'cipp_list_user_conditional_access' => 'ListUserConditionalAccessPolicies',
        'cipp_list_audit_logs' => 'ListAuditLogs',
        'cipp_list_message_trace' => 'ListMessageTrace',
        'cipp_list_mail_quarantine' => 'ListMailQuarantine',
        'cipp_list_user_mfa_methods' => 'ListMFAUsers',
        'cipp_list_oauth_apps' => 'ListOAuthApps',
    ];

    private const DEFAULT_FIELDS = [
        'cipp_list_users' => ['id', 'displayName', 'userPrincipalName', 'accountEnabled', 'jobTitle', 'department', 'assignedLicenses'],
        // No mailboxSizeBytes / itemCount here: CIPP's ListMailboxes runs
        // Get-Mailbox, which has no size or item-count properties at all (those
        // live on Get-MailboxStatistics), so they never resolved under ANY
        // casing and were dead advertised fields (psa-7lgo).
        'cipp_list_mailboxes' => ['id', 'displayName', 'userPrincipalName', 'primarySmtpAddress', 'recipientTypeDetails', 'forwardingSmtpAddress', 'deliverToMailboxAndForward', 'litigationHoldEnabled'],
        'cipp_list_licenses' => ['skuId', 'skuPartNumber', 'totalLicenses', 'consumedLicenses', 'assignedLicenses', 'prepaidUnits', 'capabilityStatus'],
        'cipp_list_devices' => ['id', 'deviceName', 'displayName', 'userPrincipalName', 'operatingSystem', 'osVersion', 'complianceState', 'isCompliant', 'managementAgent', 'enrolledDateTime', 'lastSyncDateTime', 'serialNumber'],
        'cipp_list_groups' => ['id', 'displayName', 'mail', 'mailEnabled', 'securityEnabled', 'groupTypes', 'description'],
        'cipp_list_user_groups' => ['id', 'displayName', 'mail', 'mailEnabled', 'securityEnabled', 'groupTypes', 'description'],
        // Real ListmailboxPermissions shape (verified against CIPP-API
        // Invoke-ListmailboxPermissions.ps1, psa-3twu): CIPP collapses
        // Get-MailboxPermission / Get-RecipientPermission / GrantSendOnBehalfTo
        // into two-key {User, Permissions} rows server-side. Permissions is a
        // joined string on FullAccess rows but a raw accessRights ARRAY on
        // SendAs rows; SendOnBehalf rows carry display names in User.
        'cipp_list_mailbox_permissions' => ['user', 'permissions'],
        'cipp_list_mailbox_rules' => ['name', 'enabled', 'priority', 'description', 'from', 'sentTo', 'forwardTo', 'redirectTo', 'deleteMessage', 'moveToFolder'],
        // No cipp_list_defender_state entry: shapeResult() routes that tool to
        // shapeDefenderState(), which bypasses projectRows() and names its own
        // keys. The DEFAULT_FIELDS list that used to sit here was never read
        // and described a shape CIPP does not emit (psa-7lgo).
        'cipp_list_conditional_access_policies' => ['id', 'displayName', 'state', 'createdDateTime', 'modifiedDateTime', 'conditions', 'grantControls', 'sessionControls'],
        'cipp_list_user_conditional_access' => ['id', 'displayName', 'state', 'result', 'conditions', 'grantControls', 'sessionControls'],
        'cipp_list_sign_ins' => ['id', 'createdDateTime', 'userPrincipalName', 'appDisplayName', 'ipAddress', 'clientAppUsed', 'conditionalAccessStatus', 'status', 'location', 'riskDetail', 'riskLevelAggregated', 'deviceDetail'],
        'cipp_list_audit_logs' => ['id', 'createdDateTime', 'CreationTime', 'Operation', 'operation', 'UserId', 'userPrincipalName', 'Workload', 'ResultStatus'],
        'cipp_list_message_trace' => ['MessageTraceId', 'messageTraceId', 'Received', 'received', 'SenderAddress', 'senderAddress', 'RecipientAddress', 'recipientAddress', 'Subject', 'subject', 'Status', 'status', 'FromIP', 'toIP'],
        'cipp_list_mail_quarantine' => ['Identity', 'identity', 'ReceivedTime', 'receivedTime', 'SenderAddress', 'senderAddress', 'RecipientAddress', 'recipientAddress', 'Subject', 'subject', 'QuarantineTypes', 'ReleaseStatus', 'expires'],
        'cipp_list_oauth_apps' => ['id', 'appId', 'displayName', 'appDisplayName', 'publisherName', 'principalId', 'consentedBy', 'userPrincipalName', 'consentType', 'scopes'],
    ];

    private const FIELD_ALIASES = [
        'id' => ['id', 'Id', 'ID'],
        'displayName' => ['displayName', 'DisplayName', 'name', 'Name'],
        'deviceName' => ['deviceName', 'DeviceName', 'managedDeviceName', 'ManagedDeviceName'],
        'managedDeviceName' => ['managedDeviceName', 'ManagedDeviceName'],
        'managedDeviceId' => ['managedDeviceId', 'ManagedDeviceId'],
        'azureADDeviceId' => ['azureADDeviceId', 'AzureADDeviceId', 'azureAdDeviceId', 'AzureAdDeviceId'],
        'userPrincipalName' => ['userPrincipalName', 'UserPrincipalName', 'UPN', 'upn'],
        'user' => ['user', 'User'],
        'permissions' => ['permissions', 'Permissions'],
        'primarySmtpAddress' => ['primarySmtpAddress', 'PrimarySmtpAddress', 'mail', 'Mail'],

        // ListMailboxes (Exchange Get-Mailbox). CIPP's Select-Object renames
        // SOME properties to camelCase and leaves Exchange's PascalCase on the
        // rest, so the payload is deliberately MIXED-CASE. Verified against
        // CIPP-API Invoke-ListMailboxes.ps1 (psa-7lgo) — ExecMCP re-dispatches
        // through the same API function and serializes its body verbatim, so
        // the MCP relay sees exactly these key names. Resolve the casing CIPP
        // actually emits first, and keep the other as a defensive fallback.
        'recipientTypeDetails' => ['recipientTypeDetails', 'RecipientTypeDetails'],
        'forwardingSmtpAddress' => ['ForwardingSmtpAddress', 'forwardingSmtpAddress'],
        'deliverToMailboxAndForward' => ['DeliverToMailboxAndForward', 'deliverToMailboxAndForward'],
        'litigationHoldEnabled' => ['LitigationHoldEnabled', 'litigationHoldEnabled', 'LitigationHold', 'litigationHold'],

        // ListMailboxRules. CIPP caches the RAW Get-InboxRule object
        // (Push-ListMailboxRulesQueue.ps1: `Rules = [string]($Rule |
        // ConvertTo-Json)`) and Invoke-ListMailboxRules.ps1 hands those rows
        // straight back — so every property keeps Exchange's PascalCase. The
        // relay previously declared all ten fields camel/lowercase with no
        // aliases, so every key missed and every rule row projected to `{}`:
        // the malicious-inbox-rule signal (forward-to-external + delete) was
        // structurally invisible to the agent (psa-7lgo).
        'name' => ['Name', 'name'],
        'enabled' => ['Enabled', 'enabled'],
        'priority' => ['Priority', 'priority'],
        'description' => ['Description', 'description'],
        'from' => ['From', 'from'],
        'sentTo' => ['SentTo', 'sentTo'],
        'forwardTo' => ['ForwardTo', 'forwardTo'],
        'redirectTo' => ['RedirectTo', 'redirectTo'],
        'deleteMessage' => ['DeleteMessage', 'deleteMessage'],
        'moveToFolder' => ['MoveToFolder', 'moveToFolder'],

        'skuPartNumber' => ['skuPartNumber', 'SkuPartNumber', 'sku', 'SKU'],
        'totalLicenses' => ['totalLicenses', 'TotalLicenses', 'prepaidUnitsEnabled'],
        'consumedLicenses' => ['consumedLicenses', 'ConsumedLicenses', 'consumedUnits'],
        'assignedLicenses' => ['assignedLicenses', 'AssignedLicenses', 'licenses', 'Licenses'],
        'operatingSystem' => ['operatingSystem', 'OperatingSystem', 'os'],
        'osVersion' => ['osVersion', 'OSVersion', 'operatingSystemVersion'],
        'complianceState' => ['complianceState', 'ComplianceState'],
        'isCompliant' => ['isCompliant', 'IsCompliant'],
        'lastSyncDateTime' => ['lastSyncDateTime', 'LastSyncDateTime', 'lastSync'],
        'antiVirusStatus' => ['antiVirusStatus', 'AntiVirusStatus', 'antivirusStatus'],
        'antiVirusSignatureVersion' => ['antiVirusSignatureVersion', 'AntiVirusSignatureVersion', 'avSignatureVersion'],
        'lastFullScanDateTime' => ['lastFullScanDateTime', 'LastFullScanDateTime'],
        'lastQuickScanDateTime' => ['lastQuickScanDateTime', 'LastQuickScanDateTime'],
    ];

    private const SIGN_IN_NESTED_FIELDS = [
        'status' => ['errorCode', 'failureReason', 'additionalDetails'],
        'location' => ['city', 'state', 'countryOrRegion'],
        'deviceDetail' => ['displayName', 'operatingSystem', 'browser'],
    ];

    private const ALLOWED_ARGUMENTS = [
        'cipp_list_sign_ins' => ['user_id', 'days'],
        'cipp_list_user_groups' => ['user_id'],
        'cipp_list_mailbox_permissions' => ['user_id'],
        'cipp_list_mailbox_rules' => ['user_id'],
        'cipp_list_user_conditional_access' => ['user_id'],
        'cipp_list_audit_logs' => ['user_id', 'days'],
        'cipp_list_message_trace' => ['sender', 'recipient', 'days'],
        'cipp_list_mail_quarantine' => ['recipient'],
        'cipp_list_user_mfa_methods' => ['user_id'],
        'cipp_list_oauth_apps' => ['user_id'],
    ];

    public function __construct(
        private readonly CippMcpClient $client,
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    public static function handles(string $toolName): bool
    {
        return array_key_exists($toolName, self::TOOL_MAP) || $toolName === 'cipp_list_sign_ins';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function execute(string $toolName, array $input, ?Client $client, ?int $clientId): array
    {
        $unknownArguments = $this->unknownArguments($toolName, $input);
        if ($unknownArguments !== []) {
            return ['error' => 'Unsupported CIPP MCP argument(s): '.implode(', ', $unknownArguments)];
        }

        $tenantDomain = $client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $prepared = $this->prepareCall($toolName, $input, $tenantDomain, $clientId);
        if (isset($prepared['error'])) {
            return $prepared;
        }

        try {
            $rows = $this->client->callTool($prepared['tool'], $prepared['arguments']);
        } catch (\Throwable $e) {
            Log::warning('[CippMcpToolRelay] CIPP MCP query failed', [
                'tool' => $toolName,
                'exec_mcp_tool' => $prepared['tool'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'CIPP query failed: '.$this->textSanitizer->sanitize(
                    'CIPP query error',
                    mb_substr($e->getMessage(), 0, 200),
                    200,
                ),
            ];
        }

        return $this->shapeResult($toolName, $rows, $input, $prepared, $clientId);
    }

    /**
     * @return array{tool?: string, arguments?: array<string, mixed>, error?: string, filtered_by_user?: ?string, filtered_by_days?: ?int}
     */
    private function prepareCall(string $toolName, array $input, string $tenantDomain, ?int $clientId): array
    {
        $execTool = $toolName === 'cipp_list_sign_ins'
            ? (! empty($input['user_id']) ? 'ListUserSigninLogs' : 'ListSignIns')
            : (self::TOOL_MAP[$toolName] ?? null);

        if ($execTool === null) {
            return ['error' => "Unknown CIPP tool: {$toolName}"];
        }

        $args = ['tenantFilter' => $tenantDomain];

        if (in_array($toolName, [
            'cipp_list_user_groups',
            'cipp_list_mailbox_permissions',
            'cipp_list_mailbox_rules',
            'cipp_list_user_conditional_access',
        ], true)) {
            $userId = $this->requiredUserId($input);
            if ($userId === null) {
                return ['error' => 'user_id is required'];
            }

            $resolved = $this->resolveCippUserId($userId, $clientId);

            if ($toolName === 'cipp_list_mailbox_rules') {
                // ListUserMailboxRules names its parameter UserID and takes an
                // optional userEmail. The sibling user-scoped tools
                // (ListUserGroups, ListmailboxPermissions) genuinely honour
                // camelCase userId — verified in CIPP source — so only this one
                // differs.
                $args['UserID'] = $resolved;
                if (str_contains($userId, '@')) {
                    $args['userEmail'] = $userId;
                }
            } else {
                $args['userId'] = $resolved;
            }
        }

        if (in_array($toolName, ['cipp_list_sign_ins', 'cipp_list_audit_logs'], true) && ! empty($input['user_id'])) {
            $args['userId'] = $this->resolveCippUserId(trim((string) $input['user_id']), $clientId);
        }

        if ($toolName === 'cipp_list_message_trace') {
            $args['days'] = $this->boundedDays($input['days'] ?? null, 2, 10);
            foreach (['sender', 'recipient'] as $field) {
                if (! empty($input[$field])) {
                    $args[$field] = trim((string) $input[$field]);
                }
            }
        }

        return ['tool' => $execTool, 'arguments' => $args];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeResult(string $toolName, array $rows, array $input, array $prepared, ?int $clientId): array
    {
        $rows = $this->normalizeRows($rows);
        $totalReturned = count($rows);

        return match ($toolName) {
            'cipp_list_sign_ins' => $this->shapeEvents(
                $toolName,
                $rows,
                $input,
                ['createdDateTime'],
                [
                    'endpoint' => $prepared['tool'] ?? null,
                    'filtered_by_user' => ! empty($input['user_id']) ? trim((string) $input['user_id']) : null,
                    'filtered_by_days' => $this->optionalDays($input['days'] ?? null, 30),
                    'total_returned_by_cipp' => $totalReturned,
                ],
            ),
            'cipp_list_audit_logs' => $this->shapeAuditLogs($rows, $input, $totalReturned, $clientId),
            'cipp_list_message_trace' => $this->shapeMessageTrace($rows, $input, $totalReturned),
            'cipp_list_mail_quarantine' => $this->shapeMailQuarantine($rows, $input, $totalReturned),
            'cipp_list_user_mfa_methods' => $this->shapeUserMfaMethods($rows, $input, $clientId),
            'cipp_list_oauth_apps' => $this->shapeOauthApps($rows, $input, $totalReturned, $clientId),
            'cipp_list_defender_state' => $this->shapeDefenderState($rows),
            'cipp_list_mailbox_rules' => $this->shapeMailboxRules($rows, $input, $clientId),
            default => $this->projectRows($toolName, $rows),
        };
    }

    /**
     * Defence in depth behind the ListUserMailboxRules routing.
     *
     * Exchange already scopes the upstream call to one mailbox, so this should
     * never drop anything. It exists because the disclosure it guards against —
     * one user's inbox rules answered with another user's — is both a data leak
     * AND false investigation context, and because the previous endpoint made
     * exactly that mistake silently. If someone ever re-points this tool at a
     * tenant-wide endpoint, the leak fails loudly here instead of shipping.
     *
     * Only rules we can PROVE belong to another mailbox are dropped. An owner we
     * cannot compare (a display name, a legacy DN) is kept: dropping on "cannot
     * compare" would fail CLOSED and hide the requested user's own rules, which
     * is the very failure this series exists to kill.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shapeMailboxRules(array $rules, array $input, ?int $clientId): array
    {
        $requested = $this->requiredUserId($input);
        $dropped = 0;

        if ($requested !== null) {
            $needles = $this->userIdentityNeedles($requested, $clientId);

            $rules = array_values(array_filter($rules, function (array $rule) use ($needles, &$dropped): bool {
                if ($this->mailboxRuleIsForeign($rule, $needles)) {
                    $dropped++;

                    return false;
                }

                return true;
            }));
        }

        if ($dropped > 0) {
            // Rule content is untrusted tenant data and is never logged — only
            // the fact that upstream handed us somebody else's mailbox.
            Log::warning('[CippMcpToolRelay] Dropped mailbox rules belonging to another mailbox — upstream returned rules outside the requested scope', [
                'tool' => 'cipp_list_mailbox_rules',
                'dropped' => $dropped,
            ]);
        }

        return $this->projectRows('cipp_list_mailbox_rules', $rules);
    }

    /**
     * Every identity form the requested user is known by, lowercased — the
     * caller may pass a UPN while Exchange answers with an object ID, or vice
     * versa.
     *
     * @return array<int, string>
     */
    private function userIdentityNeedles(string $requested, ?int $clientId): array
    {
        $needles = [$requested, $this->resolveCippUserId($requested, $clientId)];

        if ($clientId !== null) {
            $person = Person::where('client_id', $clientId)
                ->where(function ($query) use ($requested): void {
                    $query->whereRaw('LOWER(cipp_upn) = ?', [mb_strtolower($requested)])
                        ->orWhere('cipp_user_id', $requested);
                })
                ->first();

            if ($person !== null) {
                $needles[] = (string) $person->cipp_upn;
                $needles[] = (string) $person->cipp_user_id;
                $needles[] = (string) $person->email;
            }
        }

        $needles = array_map(fn (string $needle): string => mb_strtolower(trim($needle)), $needles);

        return array_values(array_unique(array_filter($needles, fn (string $needle): bool => $needle !== '')));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function mailboxRuleIsForeign(array $rule, array $needles): bool
    {
        $owner = $this->mailboxRuleOwner($rule);
        if ($owner === null) {
            return false;
        }

        $ownerIsEmail = str_contains($owner, '@');
        $ownerIsGuid = $this->looksLikeObjectId($owner);

        // A display name or legacy DN is unmatchable, not foreign.
        if (! $ownerIsEmail && ! $ownerIsGuid) {
            return false;
        }

        // Compare LIKE WITH LIKE. The caller may pass an object ID while Exchange
        // answers with a UPN (or the reverse), and a GUID cannot adjudicate an
        // address. Without a needle of the same form we cannot PROVE the rule is
        // someone else's — and guessing would fail CLOSED, silently hiding the
        // requested user's own rules. Drop only on a real, comparable mismatch.
        $comparable = array_values(array_filter(
            $needles,
            fn (string $needle): bool => $ownerIsEmail
                ? str_contains($needle, '@')
                : $this->looksLikeObjectId($needle)
        ));

        if ($comparable === []) {
            return false;
        }

        return ! in_array($owner, $comparable, true);
    }

    private function looksLikeObjectId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function mailboxRuleOwner(array $rule): ?string
    {
        foreach (['MailboxOwnerId', 'mailboxOwnerId'] as $key) {
            if (! empty($rule[$key]) && is_string($rule[$key])) {
                return mb_strtolower(trim($rule[$key]));
            }
        }

        // Get-InboxRule's Identity is "<mailbox>\<ruleId>".
        foreach (['Identity', 'identity'] as $key) {
            if (! empty($rule[$key]) && is_string($rule[$key])) {
                return mb_strtolower(trim(explode('\\', $rule[$key])[0]));
            }
        }

        return null;
    }

    /**
     * Real ListDefenderState shape (captured live, psa-tpzr follow-up): Intune
     * managedDevice stubs — id/deviceName/deviceType/operatingSystem — with the AV
     * state in a NESTED, NULLABLE windowsProtectionState object (null for macOS and
     * other unsupported devices). Flatten the useful inner keys under `protection`;
     * a null stays null so "no Defender telemetry" is explicit, not absent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shapeDefenderState(array $rows): array
    {
        $innerFields = [
            'deviceState', 'productStatus',
            'malwareProtectionEnabled', 'realTimeProtectionEnabled', 'tamperProtectionEnabled',
            'quickScanOverdue', 'fullScanOverdue', 'signatureUpdateOverdue', 'rebootRequired',
            'signatureVersion', 'antiMalwareVersion',
            'lastQuickScanDateTime', 'lastFullScanDateTime', 'lastReportedDateTime',
        ];

        return array_map(function (array $row) use ($innerFields): array {
            $state = $row['windowsProtectionState'] ?? null;

            $protection = null;
            if (is_array($state)) {
                $protection = [];
                foreach ($innerFields as $field) {
                    if (array_key_exists($field, $state)) {
                        $protection[$field] = $this->sanitizeProjectedValue('cipp_list_defender_state', $field, $state[$field]);
                    }
                }
            }

            return [
                'id' => $row['id'] ?? null,
                'deviceName' => $this->sanitizeProjectedValue('cipp_list_defender_state', 'deviceName', $row['deviceName'] ?? null),
                'deviceType' => $row['deviceType'] ?? null,
                'operatingSystem' => $row['operatingSystem'] ?? null,
                'protection' => $protection,
            ];
        }, array_slice($rows, 0, 50));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        if (array_is_list($rows)) {
            return array_values(array_filter($rows, 'is_array'));
        }

        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (isset($rows[$key]) && is_array($rows[$key])) {
                return $this->normalizeRows($rows[$key]);
            }
        }

        return [$rows];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeEvents(string $toolName, array $events, array $input, array $dateKeys, array $meta): array
    {
        $days = $meta['filtered_by_days'] ?? null;
        if (is_int($days)) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn (array $event): bool => $this->eventWithinCutoff($event, $cutoff, $dateKeys)));
        }

        $projected = $this->projectRows($toolName, $events);

        return array_merge($meta, [
            'count' => count(array_slice($projected, 0, 50)),
            'events' => array_slice($projected, 0, 50),
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeAuditLogs(array $events, array $input, int $totalReturned, ?int $clientId): array
    {
        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = $this->optionalDays($input['days'] ?? null, 30);

        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn (array $event): bool => $this->eventWithinCutoff($event, $cutoff, ['createdDateTime', 'CreationTime', 'Date'])));
        }

        if ($userId) {
            $resolved = $this->resolveCippUserId($userId, $clientId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;
            $events = array_values(array_filter($events, fn (array $event): bool => $this->rowMatchesUser($event, $resolved, $upnNeedle)));
        }

        $projected = $this->projectRows('cipp_list_audit_logs', $events);

        return [
            'count' => count($projected),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($projected, 0, 50),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeMessageTrace(array $messages, array $input, int $totalReturned): array
    {
        $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
        $days = $this->boundedDays($input['days'] ?? null, 2, 10);

        if ($sender) {
            $needle = mb_strtolower($sender);
            $messages = array_values(array_filter($messages, fn (array $message): bool => mb_strtolower((string) ($message['SenderAddress'] ?? $message['senderAddress'] ?? '')) === $needle));
        }

        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $messages = array_values(array_filter($messages, fn (array $message): bool => mb_strtolower((string) ($message['RecipientAddress'] ?? $message['recipientAddress'] ?? '')) === $needle));
        }

        $projected = $this->projectRows('cipp_list_message_trace', $messages);

        return [
            'count' => count($projected),
            'filtered_by_sender' => $sender,
            'filtered_by_recipient' => $recipient,
            'window_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'messages' => array_slice($projected, 0, 50),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeMailQuarantine(array $entries, array $input, int $totalReturned): array
    {
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;

        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $entries = array_values(array_filter($entries, fn (array $entry): bool => $this->rowContainsRecipient($entry, $needle)));
        }

        $projected = $this->projectRows('cipp_list_mail_quarantine', $entries);

        return [
            'count' => count($projected),
            'filtered_by_recipient' => $recipient,
            'total_returned_by_cipp' => $totalReturned,
            'entries' => array_slice($projected, 0, 50),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeUserMfaMethods(array $rows, array $input, ?int $clientId): array
    {
        $userId = $this->requiredUserId($input);
        if ($userId === null) {
            return ['error' => 'user_id is required'];
        }

        $objectId = $this->resolveCippUserId($userId, $clientId);
        $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

        foreach ($rows as $row) {
            $rowUpn = mb_strtolower((string) ($row['UPN'] ?? $row['userPrincipalName'] ?? ''));
            $rowId = (string) ($row['ID'] ?? $row['Id'] ?? $row['userId'] ?? '');
            if ($rowId === $objectId || ($upnNeedle && $rowUpn === $upnNeedle)) {
                return TriageToolExecutor::summarizeMfaRow($this->projectMfaRow($row));
            }
        }

        return [
            'error' => "No MFA record found for {$userId} in this tenant",
            'searched_user_id' => $userId,
            'resolved_object_id' => $objectId,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function shapeOauthApps(array $apps, array $input, int $totalReturned, ?int $clientId): array
    {
        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;

        if ($userId) {
            $objectId = $this->resolveCippUserId($userId, $clientId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

            $apps = array_values(array_filter($apps, function (array $app) use ($objectId, $upnNeedle): bool {
                foreach (['principalId', 'consentedBy', 'userId', 'userPrincipalName'] as $key) {
                    $val = $app[$key] ?? null;
                    if (is_string($val) && ($val === $objectId || ($upnNeedle && mb_strtolower($val) === $upnNeedle))) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $projected = $this->projectRows('cipp_list_oauth_apps', $apps);

        return [
            'count' => count($projected),
            'filtered_by_user' => $userId,
            'total_returned_by_cipp' => $totalReturned,
            'apps' => array_slice($projected, 0, 50),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectRows(string $toolName, array $rows): array
    {
        $fields = self::DEFAULT_FIELDS[$toolName] ?? [];

        // Tracks whether each field's key was ever FOUND upstream, independent
        // of its value. "Key absent from every row" is schema drift; "key
        // present holding null" is a genuine no-value (an unset Exchange
        // property serializes as null) and must not be mistaken for drift.
        $keyResolved = array_fill_keys($fields, false);

        $projected = array_map(function (array $row) use ($toolName, $fields, &$keyResolved): array {
            $projected = [];

            foreach ($fields as $field) {
                $key = $this->resolveKey($row, $field);
                if ($key === null) {
                    continue;
                }

                $keyResolved[$field] = true;

                $value = $row[$key];
                if ($value === null) {
                    continue;
                }

                if ($field === 'assignedLicenses') {
                    $projected[$field] = $this->summarizeAssignedLicenses($value);

                    continue;
                }

                $projected[$field] = $this->sanitizeProjectedValue($toolName, $field, $value);
            }

            return $projected;
        }, $rows);

        if ($rows !== []) {
            $this->warnOnShapeDrift($toolName, $rows, $projected, $keyResolved);
        }

        return $projected;
    }

    /**
     * Row keys are schema names and safe to log; row values are untrusted
     * tenant data and are never logged.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $projected
     * @param  array<string, bool>  $keyResolved
     */
    private function warnOnShapeDrift(string $toolName, array $rows, array $projected, array $keyResolved): void
    {
        // Every row projecting to {} means DEFAULT_FIELDS has drifted wholesale
        // from the live CIPP response shape, and the tool reports a false "no
        // results" (psa-3twu).
        if (array_filter($projected) === []) {
            Log::warning('[CippMcpToolRelay] Every row projected empty — DEFAULT_FIELDS out of sync with CIPP response shape', [
                'tool' => $toolName,
                'row_count' => count($rows),
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);

            return;
        }

        // A PARTIAL drop is the invisible failure this guard exists for
        // (psa-7lgo): the row still projects id/displayName/UPN, so the
        // all-empty check above stays quiet while an individual field vanishes
        // because CIPP cases its key differently. That silently stripped
        // ForwardingSmtpAddress and every Get-InboxRule property from the
        // agent's view of a tenant — security signal lost with no error.
        $missing = array_keys(array_filter($keyResolved, fn (bool $resolved): bool => ! $resolved));
        if ($missing === []) {
            return;
        }

        Log::warning('[CippMcpToolRelay] Field(s) never resolved in any row — DEFAULT_FIELDS/FIELD_ALIASES out of sync with CIPP response shape', [
            'tool' => $toolName,
            'row_count' => count($rows),
            'missing_fields' => array_values($missing),
            'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectMfaRow(array $row): array
    {
        $fields = ['ID', 'UPN', 'DisplayName', 'MFARegistration', 'MFAMethods', 'PerUser', 'CoveredByCA', 'CoveredBySD', 'CAPolicies'];
        $projected = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $projected[$field] = $this->sanitizeProjectedValue('cipp_list_user_mfa_methods', $field, $row[$field]);
            }
        }

        return $projected;
    }

    private function sanitizeProjectedValue(string $toolName, string $field, mixed $value): mixed
    {
        if (is_string($value) && $this->isFreeTextField($field)) {
            return $this->textSanitizer->sanitize($this->fieldLabel($toolName, $field), $value, 1000);
        }

        if (is_array($value)) {
            if ($toolName === 'cipp_list_sign_ins' && isset(self::SIGN_IN_NESTED_FIELDS[$field])) {
                $value = $this->compactArray($value, self::SIGN_IN_NESTED_FIELDS[$field]);
            }

            return $this->boundArray($toolName, $field, $value);
        }

        return $value;
    }

    private function valueFor(array $row, string $field): mixed
    {
        $key = $this->resolveKey($row, $field);

        return $key === null ? null : $row[$key];
    }

    /**
     * The upstream key this field resolves to, or null when no candidate key
     * exists on the row at all. Matching is case-sensitive by design: the
     * alias lists carry the exact casings CIPP emits (verified against
     * CIPP-API source), so a miss is real schema drift worth reporting rather
     * than something to paper over with a fuzzy match.
     */
    private function resolveKey(array $row, string $field): ?string
    {
        foreach (self::FIELD_ALIASES[$field] ?? [$field] as $candidate) {
            if (array_key_exists($candidate, $row)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function unknownArguments(string $toolName, array $input): array
    {
        $allowed = array_merge(['client_id'], self::ALLOWED_ARGUMENTS[$toolName] ?? []);

        return array_values(array_diff(array_keys($input), $allowed));
    }

    private function summarizeAssignedLicenses(mixed $value): array
    {
        $licenses = is_array($value) ? $value : [];
        $skuIds = [];
        $skuPartNumbers = [];

        foreach ($licenses as $license) {
            if (! is_array($license)) {
                continue;
            }
            if (! empty($license['skuId'])) {
                $skuIds[] = (string) $license['skuId'];
            }
            if (! empty($license['skuPartNumber'])) {
                $skuPartNumbers[] = (string) $license['skuPartNumber'];
            }
        }

        return [
            'count' => count($licenses),
            'skuIds' => array_slice(array_values(array_unique($skuIds)), 0, 50),
            'skuPartNumbers' => array_slice(array_values(array_unique($skuPartNumbers)), 0, 50),
        ];
    }

    private function boundArray(string $toolName, string $field, array $value, int $depth = 0): array
    {
        $bounded = array_slice($value, 0, 20, preserve_keys: true);

        foreach ($bounded as $key => $item) {
            if (is_string($item)) {
                $bounded[$key] = $this->textSanitizer->sanitize($this->fieldLabel($toolName, "{$field} {$key}"), $item, 1000);
            } elseif (is_array($item) && $depth < self::MAX_NESTED_ARRAY_DEPTH) {
                $bounded[$key] = $this->boundArray($toolName, "{$field} {$key}", $item, $depth + 1);
            } elseif (is_array($item)) {
                $bounded[$key] = ['_truncated' => 'Nested array omitted'];
            }
        }

        return $bounded;
    }

    /**
     * @param  array<int, string>  $allowedKeys
     * @return array<string, mixed>
     */
    private function compactArray(array $value, array $allowedKeys): array
    {
        $compacted = [];

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $value)) {
                $compacted[$key] = $value[$key];
            }
        }

        return $compacted;
    }

    private function isFreeTextField(string $field): bool
    {
        return in_array($field, [
            'displayName',
            'DisplayName',
            'name',
            'Name',
            'deviceName',
            'DeviceName',
            'managedDeviceName',
            'ManagedDeviceName',
            'description',
            'Description',
            'jobTitle',
            'department',
            'officeLocation',
            // Mailbox-permission trustees can be display names (SendOnBehalf rows),
            // not just UPNs — treat as untrusted free text.
            'user',
            'Subject',
            'subject',
            'appDisplayName',
            'publisherName',
            'Operation',
            'operation',
        ], true);
    }

    private function fieldLabel(string $toolName, string $field): string
    {
        $tool = str_replace('_', ' ', preg_replace('/^cipp_/', '', $toolName) ?? $toolName);

        return "CIPP {$tool} {$field}";
    }

    private function requiredUserId(array $input): ?string
    {
        $userId = $input['user_id'] ?? null;
        if (! is_string($userId) && ! is_numeric($userId)) {
            return null;
        }

        $userId = trim((string) $userId);

        return $userId !== '' ? $userId : null;
    }

    private function resolveCippUserId(string $input, ?int $clientId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return $input;
        }

        if (str_contains($input, '@') && $clientId) {
            $objectId = Person::where('client_id', $clientId)
                ->whereRaw('LOWER(cipp_upn) = ?', [mb_strtolower($input)])
                ->value('cipp_user_id');

            if ($objectId) {
                return $objectId;
            }
        }

        return $input;
    }

    private function optionalDays(mixed $value, int $max): ?int
    {
        return isset($value) && is_numeric($value)
            ? (int) min(max(1, $value), $max)
            : null;
    }

    private function boundedDays(mixed $value, int $default, int $max): int
    {
        return isset($value) && is_numeric($value)
            ? (int) min(max(1, $value), $max)
            : $default;
    }

    private function eventWithinCutoff(array $event, Carbon $cutoff, array $dateKeys): bool
    {
        foreach ($dateKeys as $key) {
            if (! empty($event[$key])) {
                try {
                    return Carbon::parse($event[$key])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    private function rowMatchesUser(array $event, string $resolved, ?string $upnNeedle): bool
    {
        foreach (['userId', 'UserId', 'userPrincipalName', 'UserPrincipalName', 'initiatedBy'] as $key) {
            if (isset($event[$key])) {
                $val = mb_strtolower((string) $event[$key]);
                if ($val === mb_strtolower($resolved) || ($upnNeedle && $val === $upnNeedle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function rowContainsRecipient(array $entry, string $needle): bool
    {
        foreach (['RecipientAddress', 'recipientAddress', 'recipients'] as $key) {
            $val = $entry[$key] ?? null;
            if (is_string($val) && mb_strtolower($val) === $needle) {
                return true;
            }
            if (is_array($val)) {
                foreach ($val as $recipient) {
                    if (is_string($recipient) && mb_strtolower($recipient) === $needle) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
