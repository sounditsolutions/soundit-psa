<?php

namespace App\Services\Cipp;

use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The CIPP (M365) tool handlers, shared by TriageToolExecutor (auto-triage
 * agentic loop) and AssistantToolExecutor (inline ticket chat). Both surfaces
 * expose the same CIPP tool set defined once in TriageToolDefinitions::cippTools();
 * before this trait each executor carried its own copy of these method bodies and
 * they drifted (psa-202) — a new tool or bugfix landing in one and not the other.
 *
 * The using class must satisfy this contract:
 *   - `protected ?int $clientId` — the client scope (Triage: always set from the
 *     ticket; Assistant: nullable). Read by resolveCippUserId().
 *   - cippTenantDomain(): the tenant filter for CIPP calls. Triage reads it from
 *     `$this->ticket->client`, the Assistant from `$this->client` — the one true
 *     divergence, abstracted here so the query bodies stay identical.
 *   - cippLogPrefix(): the log tag ("[Triage]" / "[Assistant]") so failure logs
 *     stay attributable to their surface.
 *
 * cippMcpRelay() is an opt-in hook: it returns null by default (direct CIPP HTTP,
 * the Triage path) and the Assistant overrides it to route through the CIPP MCP
 * relay first. Every dispatch entry offers the relay the call before falling back
 * to the direct CippClient path below.
 *
 * @property ?int $clientId The using class must expose this.
 */
trait HandlesCippTools
{
    /** Tenant filter for CIPP calls — sourced differently per executor. */
    abstract protected function cippTenantDomain(): ?string;

    /** Log tag for CIPP failure logs, e.g. "[Triage]" or "[Assistant]". */
    abstract protected function cippLogPrefix(): string;

    /**
     * Give the CIPP MCP relay first refusal on a tool call. Returns null when the
     * relay is not in play (the default — direct CippClient HTTP path). The
     * Assistant overrides this to delegate to CippMcpToolRelay when enabled.
     */
    protected function cippMcpRelay(string $toolName, array $input): ?array
    {
        return null;
    }

    private function cippQuery(string $toolName, array $input, string $endpoint): array
    {
        $relay = $this->cippMcpRelay($toolName, $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            return app(CippClient::class)->get($endpoint, ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * The direct (non-relay) path for a user-scoped CIPP tool.
     *
     * $userParam MUST be the parameter name the target endpoint actually reads.
     * Getting that wrong is not a no-op: CIPP silently ignores an unknown query
     * parameter, so a user-scoped request quietly becomes a TENANT-WIDE one and
     * the caller is handed every user's data without any error (psa-7lgo.1).
     * Most endpoints take camelCase `userId`; ListUserMailboxRules takes `UserID`.
     */
    private function cippQueryWithUser(string $toolName, array $input, string $endpoint, string $userParam = 'userId'): array
    {
        $relay = $this->cippMcpRelay($toolName, $input);
        if ($relay !== null) {
            return $relay;
        }

        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $query = [
            'TenantFilter' => $tenantDomain,
            $userParam => $this->resolveCippUserId($userId),
        ];

        // ListUserMailboxRules also accepts userEmail, which CIPP uses when it
        // reports a failure. Cheap to send, and it keeps the request explicit.
        if ($userParam === 'UserID' && str_contains((string) $userId, '@')) {
            $query['userEmail'] = (string) $userId;
        }

        try {
            return app(CippClient::class)->get($endpoint, $query);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function cippListSignIns(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_sign_ins', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        // CIPP has a per-user endpoint (api/ListUserSigninLogs) and a tenant-wide
        // endpoint (api/ListSignIns). Route to the per-user one when filtering by
        // user — it's authoritative and not subject to the tenant-wide window cap.
        // CIPP's userId param requires an Azure AD object ID (GUID), not a UPN —
        // translate via our synced Person record before calling.
        $endpoint = $userId ? 'api/ListUserSigninLogs' : 'api/ListSignIns';
        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get($endpoint, $params);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP sign-in query failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        // Days filter applied client-side — CIPP doesn't document a window param.
        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn ($e) => $this->eventWithinCutoff($e, $cutoff, ['createdDateTime'])));
        }

        // Cap to keep the AI context window manageable.
        $capped = array_slice($events, 0, 50);

        return [
            'count' => count($capped),
            'endpoint' => $endpoint,
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => $capped,
        ];
    }

    private function cippListAuditLogs(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_audit_logs', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get('api/ListAuditLogs', $params);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP ListAuditLogs failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn ($e) => $this->eventWithinCutoff($e, $cutoff, ['createdDateTime', 'CreationTime', 'Date'])));
        }

        // Match the resolved object ID (CIPP was queried with it) OR the raw UPN —
        // the events come back keyed by object ID, so filtering on the raw UPN
        // alone would drop every row when the caller passed an email.
        if ($userId) {
            $resolved = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;
            $events = array_values(array_filter($events, function ($e) use ($resolved, $upnNeedle) {
                foreach (['userId', 'UserId', 'userPrincipalName', 'UserPrincipalName', 'initiatedBy'] as $key) {
                    if (isset($e[$key])) {
                        $val = mb_strtolower((string) $e[$key]);
                        if ($val === mb_strtolower($resolved)) {
                            return true;
                        }
                        if ($upnNeedle && $val === $upnNeedle) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($events),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];
    }

    private function cippListMessageTrace(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_message_trace', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 10)
            : 2;

        $params = ['TenantFilter' => $tenantDomain, 'days' => $days];
        if ($sender) {
            $params['sender'] = $sender;
        }
        if ($recipient) {
            $params['recipient'] = $recipient;
        }

        try {
            $messages = app(CippClient::class)->get('api/ListMessageTrace', $params);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP ListMessageTrace failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($messages)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($messages);

        // Client-side filter — Message Trace upstream filtering is unreliable across CIPP versions.
        if ($sender) {
            $needle = mb_strtolower($sender);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['SenderAddress'] ?? $m['senderAddress'] ?? '')) === $needle));
        }
        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['RecipientAddress'] ?? $m['recipientAddress'] ?? '')) === $needle));
        }

        return [
            'count' => count($messages),
            'filtered_by_sender' => $sender,
            'filtered_by_recipient' => $recipient,
            'window_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'messages' => array_slice($messages, 0, 50),
        ];
    }

    private function cippListMailQuarantine(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_mail_quarantine', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;

        try {
            $entries = app(CippClient::class)->get('api/ListMailQuarantine', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP ListMailQuarantine failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($entries)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($entries);

        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $entries = array_values(array_filter($entries, function ($e) use ($needle) {
                foreach (['RecipientAddress', 'recipientAddress', 'recipients'] as $key) {
                    $val = $e[$key] ?? null;
                    if (is_string($val) && mb_strtolower($val) === $needle) {
                        return true;
                    }
                    if (is_array($val)) {
                        foreach ($val as $r) {
                            if (is_string($r) && mb_strtolower($r) === $needle) {
                                return true;
                            }
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($entries),
            'filtered_by_recipient' => $recipient,
            'total_returned_by_cipp' => $totalReturned,
            'entries' => array_slice($entries, 0, 50),
        ];
    }

    private function cippListUserMfaMethods(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_user_mfa_methods', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        // CIPP's ListMFAUsers returns the user-level MFA picture: registration
        // status, method types, and which enforcement mechanism (CA / Security
        // Defaults / per-user) currently covers them. ListPerUserMFA is the
        // legacy per-user-toggle list and doesn't reflect modern MFA at all.
        try {
            $rows = app(CippClient::class)->get('api/ListMFAUsers', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP ListMFAUsers failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($rows)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $objectId = $this->resolveCippUserId($userId);
        $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

        foreach ($rows as $row) {
            $rowUpn = mb_strtolower((string) ($row['UPN'] ?? $row['userPrincipalName'] ?? ''));
            $rowId = (string) ($row['ID'] ?? $row['Id'] ?? $row['userId'] ?? '');
            if ($rowId === $objectId || ($upnNeedle && $rowUpn === $upnNeedle)) {
                return self::summarizeMfaRow($row);
            }
        }

        return [
            'error' => "No MFA record found for {$userId} in this tenant",
            'searched_user_id' => $userId,
            'resolved_object_id' => $objectId,
        ];
    }

    /**
     * Add a derived `enforcement` summary to a raw CIPP ListMFAUsers row so
     * downstream consumers (AI tools) don't have to interpret PerUser/SD/CA
     * fields, which are easy to misread. Returns the row unchanged when an
     * existing `enforcement` key is present.
     *
     * Enforcement values: "conditional_access", "security_defaults",
     * "per_user_legacy", "none".
     */
    public static function summarizeMfaRow(array $row): array
    {
        $caState = mb_strtolower((string) ($row['CoveredByCA'] ?? ''));
        $sd = (bool) ($row['CoveredBySD'] ?? false);
        $perUser = mb_strtolower((string) ($row['PerUser'] ?? 'disabled'));

        $sources = [];
        if ($caState !== '' && $caState !== 'not enforced') {
            $sources[] = 'conditional_access';
        }
        if ($sd) {
            $sources[] = 'security_defaults';
        }
        if (in_array($perUser, ['enabled', 'enforced'], true)) {
            $sources[] = 'per_user_legacy';
        }

        $row['enforcement'] = [
            'sources' => $sources ?: ['none'],
            'primary' => $sources[0] ?? 'none',
            'note' => match (true) {
                $sources === [] => 'No MFA enforcement detected. User can sign in without MFA.',
                $sources === ['security_defaults'] => 'Protected by Security Defaults (tenant-wide). Cannot be tuned per-user or per-app.',
                in_array('conditional_access', $sources, true) => 'Protected by Conditional Access. Check CoveredByCA / CAPolicies for which policy.',
                $sources === ['per_user_legacy'] => 'On the legacy per-user MFA toggle. Modern tenants should migrate to Conditional Access.',
                default => 'Multiple enforcement sources — see sources array.',
            },
        ];

        return $row;
    }

    private function cippListOauthApps(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_oauth_apps', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            $apps = app(CippClient::class)->get('api/ListOAuthApps', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning($this->cippLogPrefix().' CIPP ListOAuthApps failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($apps)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($apps);
        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;

        if ($userId) {
            $objectId = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

            $apps = array_values(array_filter($apps, function ($app) use ($objectId, $upnNeedle) {
                // Match any field that may carry user identity for the consent grant.
                foreach (['principalId', 'consentedBy', 'userId', 'userPrincipalName'] as $key) {
                    $val = $app[$key] ?? null;
                    if (is_string($val) && $val !== '') {
                        if ($val === $objectId) {
                            return true;
                        }
                        if ($upnNeedle && mb_strtolower($val) === $upnNeedle) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($apps),
            'filtered_by_user' => $userId,
            'total_returned_by_cipp' => $totalReturned,
            'apps' => array_slice($apps, 0, 50),
        ];
    }

    private function eventWithinCutoff(array $e, Carbon $cutoff, array $dateKeys): bool
    {
        foreach ($dateKeys as $key) {
            if (! empty($e[$key])) {
                try {
                    return Carbon::parse($e[$key])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Translate a UPN (email) to the Azure AD object ID expected by CIPP's
     * per-user endpoints. Looks up the synced Person record for the current
     * client scope. Pass-through if input already looks like a GUID, or if no
     * Person match exists (caller will see CIPP's response on that).
     */
    private function resolveCippUserId(string $input): string
    {
        // Already a GUID — pass through.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return $input;
        }

        // Looks like an email — try to resolve via Person.cipp_upn → cipp_user_id.
        if (str_contains($input, '@') && $this->clientId) {
            $objectId = Person::where('client_id', $this->clientId)
                ->whereRaw('LOWER(cipp_upn) = ?', [mb_strtolower($input)])
                ->value('cipp_user_id');

            if ($objectId) {
                return $objectId;
            }
        }

        return $input;
    }
}
