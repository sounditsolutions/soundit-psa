<?php

namespace App\Services\Cipp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The CIPP (M365) tool handlers, shared by TriageToolExecutor (auto-triage
 * agentic loop) and AssistantToolExecutor (inline ticket chat, and Chet's staff
 * MCP surface, which dispatches through it). Both surfaces expose the same CIPP
 * tool set defined once in TriageToolDefinitions::cippTools(); before this trait
 * each executor carried its own copy of these method bodies and they drifted
 * (psa-202) — a new tool or bugfix landing in one and not the other.
 *
 * The using class must satisfy this contract:
 *   - `protected ?int $clientId` — the client scope (Triage: always set from the
 *     ticket; Assistant: nullable). Read by the user-id resolver.
 *   - cippTenantDomain(): the tenant filter for CIPP calls. Triage reads it from
 *     `$this->ticket->client`, the Assistant from `$this->client` — the one true
 *     divergence, abstracted here so the query bodies stay identical.
 *   - cippLogPrefix(): the log tag ("[Triage]" / "[Assistant]") so failure logs
 *     stay attributable to their surface.
 *
 * EVERY tool body goes through cippDispatch(). That is not a style preference —
 * it is the only reason the fail-loud rules cannot be bypassed. See its docblock.
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

    /**
     * The single gate EVERY CIPP tool call passes through, on BOTH transports.
     *
     * The order matters and is the whole point of this method:
     *
     *   1. CippToolContract::unanswerable() — refuse questions CIPP structurally
     *      cannot answer, BEFORE any transport is chosen and before any upstream
     *      call is spent. This is the choke point. The fail-loud rules for per-user
     *      Conditional Access and per-user OAuth consent were previously written
     *      into CippMcpToolRelay alone, which left them enforced on exactly one of
     *      the two paths that reach these tools — and NOT on the one auto-triage
     *      always takes, nor on the one the assistant (and Chet) fall back to
     *      whenever the MCP relay is off or unconfigured (psa-dbrw, psa-idii).
     *      Putting the guard here means a CIPP tool cannot be dispatched, by any
     *      caller, without passing it. The relay re-checks the same predicate as
     *      defence in depth; it is one implementation, so the two cannot drift.
     *   2. CippToolContract::identityRefusal() — refuse questions THIS CALLER cannot
     *      ask: a user-scoped read whose endpoint filters on an Azure AD object ID
     *      and nothing else, for an identity this client's synced people cannot
     *      bridge to one. Sent as-is, such a request matches nothing upstream and
     *      the tool answers a clean "no sign-ins" (psa-cipp-p1). Same reasoning as
     *      (1): it is enforced here, before a transport is chosen, so no caller can
     *      reach CIPP without passing it.
     *   3. The MCP relay, if this executor has one and it is enabled.
     *   4. The tenant mapping, then the direct CippClient call.
     *
     * @param  callable(string): array<int|string, mixed>  $direct  Receives the tenant domain.
     * @return array<int|string, mixed>
     */
    private function cippDispatch(string $toolName, array $input, callable $direct): array
    {
        $refusal = CippToolContract::unanswerable($toolName, $input);
        if ($refusal !== null) {
            return ['error' => $refusal];
        }

        $identityRefusal = CippToolContract::identityRefusal($toolName, $input, $this->clientId);
        if ($identityRefusal !== null) {
            return ['error' => $identityRefusal];
        }

        $relay = $this->cippMcpRelay($toolName, $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->cippTenantDomain();
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        return $direct($tenantDomain);
    }

    private function cippQuery(string $toolName, array $input, string $endpoint): array
    {
        return $this->cippDispatch($toolName, $input, function (string $tenantDomain) use ($endpoint): array {
            try {
                return app(CippClient::class)->get($endpoint, ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }
        });
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
        return $this->cippDispatch($toolName, $input, function (string $tenantDomain) use ($input, $endpoint, $userParam): array {
            $userId = CippToolContract::requiredUserId($input);
            if ($userId === null) {
                return ['error' => 'user_id is required'];
            }

            $query = [
                'TenantFilter' => $tenantDomain,
                $userParam => $this->resolveCippUserId($userId),
            ];

            // ListUserMailboxRules also accepts userEmail, which CIPP uses when it
            // reports a failure. Cheap to send, and it keeps the request explicit.
            if ($userParam === 'UserID' && str_contains($userId, '@')) {
                $query['userEmail'] = $userId;
            }

            try {
                return app(CippClient::class)->get($endpoint, $query);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }
        });
    }

    private function cippListSignIns(array $input): array
    {
        return $this->cippDispatch('cipp_list_sign_ins', $input, function (string $tenantDomain) use ($input): array {
            $userId = CippToolContract::optionalUserId($input);
            $days = CippToolContract::windowDays($input['days'] ?? null);

            // CIPP has a per-user endpoint (api/ListUserSigninLogs) and a tenant-wide
            // endpoint (api/ListSignIns). Route to the per-user one when filtering by
            // user — it's authoritative and not subject to the tenant-wide window cap.
            //
            // ListUserSigninLogs filters Graph on the signIn `userId` property, which is
            // an Azure AD OBJECT ID and nothing else. A UPN there matches zero rows and
            // returns an empty 200 — so the identity is resolved through the contract's
            // requireObjectId(), which refuses rather than guess. cippDispatch() has
            // ALREADY run that same guard before choosing a transport, so an error here
            // is unreachable in practice; it is honoured rather than assumed away,
            // because "the caller checked" is exactly the assumption this series keeps
            // being punished for.
            $endpoint = $userId ? 'api/ListUserSigninLogs' : 'api/ListSignIns';
            $params = ['TenantFilter' => $tenantDomain];

            if ($userId) {
                $resolved = CippToolContract::requireObjectId($userId, $this->clientId);
                if (isset($resolved['error'])) {
                    return ['error' => $resolved['error']];
                }

                $params['userId'] = $resolved['objectId'];
            } elseif ($days !== null) {
                // Invoke-ListSignIns windows SERVER-SIDE and defaults to $Days = 7, so
                // a 30-day request only ever saw 7 days of sign-ins while the response
                // reported filtered_by_days: 30 — "we didn't look" dressed up as "there
                // was nothing to find" (psa-536g). The user_id path resolves to
                // ListUserSigninLogs, which has no date filter at all ($top=50, newest
                // first), so Days applies only to the tenant-wide endpoint.
                $params['Days'] = $days;
            }

            try {
                $events = app(CippClient::class)->get($endpoint, $params);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }

            if (! is_array($events)) {
                return ['error' => 'Unexpected CIPP response shape'];
            }

            $totalReturned = count($events);

            if ($days !== null) {
                $cutoff = now()->subDays($days);
                $events = array_values(array_filter($events, fn ($e) => is_array($e) && $this->eventWithinCutoff($e, $cutoff, ['createdDateTime'])));
            }

            return [
                'count' => count(array_slice($events, 0, 50)),
                'endpoint' => $endpoint,
                'filtered_by_user' => $userId,
                'filtered_by_days' => $days,
                'total_returned_by_cipp' => $totalReturned,
                'events' => array_slice($events, 0, 50),
            ];
        });
    }

    /**
     * ListAuditLogs is shaped by the shared CippToolContract, not here — the row
     * shape, the nested-payload filters and the projection allowlist are properties
     * of CIPP's response, not of the transport that fetched it. The direct path used
     * to carry its own copy of all three, reading the raw unified-audit-log keys at
     * the TOP level (CIPP nests them two levels down under Data.RawData), so
     * `days` or `user_id` dropped 100% of rows and the tool answered "no audit
     * events" to every question — while an unfiltered call handed the agent CIPP's
     * whole unbounded `Data` blob raw (psa-9d4l).
     */
    private function cippListAuditLogs(array $input): array
    {
        return $this->cippDispatch('cipp_list_audit_logs', $input, function (string $tenantDomain) use ($input): array {
            $params = ['TenantFilter' => $tenantDomain];

            // RelativeTime is ListAuditLogs' SERVER-SIDE window, (\d+)([dhm]); with no
            // window it defaults to 7 days. userId is NOT a parameter of this endpoint
            // — CIPP silently ignores it — so sending it was a false claim of a
            // server-side user filter. The user filter is applied against the nested
            // payload, in the contract.
            $days = CippToolContract::windowDays($input['days'] ?? null);
            if ($days !== null) {
                $params['RelativeTime'] = "{$days}d";
            }

            try {
                $events = app(CippClient::class)->get('api/ListAuditLogs', $params);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListAuditLogs', $e);
            }

            if (! is_array($events)) {
                return ['error' => 'Unexpected CIPP response shape'];
            }

            return app(CippToolContract::class)->shapeAuditLogs($events, $input, $this->clientId);
        });
    }

    private function cippListMessageTrace(array $input): array
    {
        return $this->cippDispatch('cipp_list_message_trace', $input, function (string $tenantDomain) use ($input): array {
            $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
            $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
            $days = CippToolContract::boundedDays($input['days'] ?? null, 2, 10);

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
                return $this->cippFailure('api/ListMessageTrace', $e);
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
        });
    }

    private function cippListMailQuarantine(array $input): array
    {
        return $this->cippDispatch('cipp_list_mail_quarantine', $input, function (string $tenantDomain) use ($input): array {
            $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;

            try {
                $entries = app(CippClient::class)->get('api/ListMailQuarantine', ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListMailQuarantine', $e);
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
        });
    }

    private function cippListUserMfaMethods(array $input): array
    {
        return $this->cippDispatch('cipp_list_user_mfa_methods', $input, function (string $tenantDomain) use ($input): array {
            $userId = CippToolContract::requiredUserId($input);
            if ($userId === null) {
                return ['error' => 'user_id is required'];
            }

            // CIPP's ListMFAUsers returns the user-level MFA picture: registration
            // status, method types, and which enforcement mechanism (CA / Security
            // Defaults / per-user) currently covers them. ListPerUserMFA is the
            // legacy per-user-toggle list and doesn't reflect modern MFA at all.
            try {
                $rows = app(CippClient::class)->get('api/ListMFAUsers', ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListMFAUsers', $e);
            }

            if (! is_array($rows)) {
                return ['error' => 'Unexpected CIPP response shape'];
            }

            $objectId = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

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
        });
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

    /**
     * Tenant-wide only. A per-user call never reaches this closure — cippDispatch()
     * refuses it via CippToolContract::unanswerable(), because CIPP's ListOAuthApps
     * drops principalId and consentType and so cannot attribute a consent to a user
     * at all. The filter that used to live here matched four keys CIPP never emits,
     * dropped every row, and reported a confident {count: 0, apps: []} — a false
     * negative on illicit consent grant (psa-dbrw).
     */
    private function cippListOauthApps(array $input): array
    {
        return $this->cippDispatch('cipp_list_oauth_apps', $input, function (string $tenantDomain): array {
            try {
                $apps = app(CippClient::class)->get('api/ListOAuthApps', ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListOAuthApps', $e);
            }

            if (! is_array($apps)) {
                return ['error' => 'Unexpected CIPP response shape'];
            }

            return app(CippToolContract::class)->shapeOauthApps($apps);
        });
    }

    /**
     * @return array{error: string}
     */
    private function cippFailure(string $endpoint, \Throwable $e): array
    {
        Log::warning($this->cippLogPrefix().' CIPP query failed', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
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
     * per-user endpoints, within the current client scope. Shared with the MCP
     * relay via CippToolContract so the two transports resolve identities the
     * same way.
     */
    private function resolveCippUserId(string $input): string
    {
        return CippToolContract::resolveUserId($input, $this->clientId);
    }
}
