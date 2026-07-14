<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * The MCP transport for the CIPP read tools.
 *
 * This class owns HOW we talk to CIPP over ExecMCP — the upstream tool names and
 * the argument names those tools actually read. It does NOT own which questions
 * are answerable, how CIPP's rows are shaped, or how untrusted tenant text is
 * fenced: those are properties of CIPP's API, identical no matter which transport
 * fetched the row, and they live in the shared CippToolContract, which the direct
 * CippClient path (HandlesCippTools) is routed through too.
 *
 * That split is deliberate. When the fail-loud rules AND the row shaping lived in
 * this class alone, they were enforced on exactly one of the two paths that reach
 * these tools — and not on the one auto-triage always takes (psa-dbrw / psa-idii /
 * psa-9d4l / psa-d2hj). This is now purely a transport: fetch rows over the wire,
 * hand them to CippToolContract::shape().
 */
class CippMcpToolRelay
{
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

    private readonly CippToolContract $contract;

    public function __construct(
        private readonly CippMcpClient $client,
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
        ?CippToolContract $contract = null,
    ) {
        $this->contract = $contract ?? new CippToolContract($textSanitizer);
    }

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

        // Shaping — projection, filtering, fencing — is a property of CIPP's response,
        // not of this transport, so it lives in the shared contract and the direct
        // CippClient path runs the identical code (psa-d2hj).
        return $this->contract->shape($toolName, $rows, $input, $clientId);
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

        // Defence in depth. HandlesCippTools::cippDispatch() already refused this
        // before it chose a transport — that is the choke point both paths pass
        // through — but the relay must not be safe only because of who calls it.
        // It is the SAME predicate, so the two cannot drift apart.
        $unanswerable = CippToolContract::unanswerable($toolName, $input);
        if ($unanswerable !== null) {
            return ['error' => $unanswerable];
        }

        // Likewise for the identity guard: a user-scoped read whose endpoint filters on
        // an Azure AD object ID and nothing else must not be asked with an identity we
        // could not bridge to one. Over MCP that request would come back empty exactly
        // as it does over REST, and an empty answer to "has this account been signed
        // into?" is the most dangerous thing this tool can say.
        $identityRefusal = CippToolContract::identityRefusal($toolName, $input, $clientId);
        if ($identityRefusal !== null) {
            return ['error' => $identityRefusal];
        }

        $args = ['tenantFilter' => $tenantDomain];

        if (in_array($toolName, [
            'cipp_list_user_groups',
            'cipp_list_mailbox_permissions',
            'cipp_list_mailbox_rules',
        ], true)) {
            $userId = CippToolContract::requiredUserId($input);
            if ($userId === null) {
                return ['error' => 'user_id is required'];
            }

            $resolved = CippToolContract::resolveUserId($userId, $clientId);

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

        // Only ListUserSigninLogs actually consumes userId. ListAuditLogs does
        // not accept it (not a spec param) and silently ignored what we sent —
        // that filter is applied against the nested payload, in the contract.
        //
        // ListUserSigninLogs filters Graph on the signIn `userId` property, an Azure AD
        // OBJECT ID; a UPN matches nothing and comes back empty. requireObjectId() is the
        // same resolver the identity guard above consulted, so the value sent cannot
        // disagree with the value that was cleared.
        if ($toolName === 'cipp_list_sign_ins' && ! empty($input['user_id'])) {
            $resolved = CippToolContract::requireObjectId(trim((string) $input['user_id']), $clientId);
            if (isset($resolved['error'])) {
                return ['error' => $resolved['error']];
            }

            $args['userId'] = $resolved['objectId'];
        }

        // Both endpoints window SERVER-SIDE and default to the last 7 days when
        // handed no window, so a 30-day request silently saw 7 days of data
        // while the response still reported filtered_by_days: 30 — a lying
        // metadata field, which is worse than a missing one because it turns
        // "we didn't look" into "there was nothing to find" (psa-9d4l/psa-536g).
        // ListAuditLogs takes RelativeTime as (\d+)([dhm]); ListSignIns takes Days.
        if ($toolName === 'cipp_list_audit_logs') {
            $days = CippToolContract::windowDays($input['days'] ?? null);
            if ($days !== null) {
                $args['RelativeTime'] = "{$days}d";
            }
        }

        // The user_id path resolves to ListUserSigninLogs, which has no date
        // filter at all ($top=50, newest first) — so Days only applies to the
        // tenant-wide ListSignIns.
        if ($toolName === 'cipp_list_sign_ins' && empty($input['user_id'])) {
            $days = CippToolContract::windowDays($input['days'] ?? null);
            if ($days !== null) {
                $args['Days'] = $days;
            }
        }

        if ($toolName === 'cipp_list_message_trace') {
            $args['days'] = CippToolContract::boundedDays($input['days'] ?? null, 2, 10);
            foreach (['sender', 'recipient'] as $field) {
                if (! empty($input[$field])) {
                    $args[$field] = trim((string) $input[$field]);
                }
            }
        }

        return ['tool' => $execTool, 'arguments' => $args];
    }

    /**
     * @return array<int, string>
     */
    private function unknownArguments(string $toolName, array $input): array
    {
        $allowed = array_merge(['client_id'], self::ALLOWED_ARGUMENTS[$toolName] ?? []);

        return array_values(array_diff(array_keys($input), $allowed));
    }
}
