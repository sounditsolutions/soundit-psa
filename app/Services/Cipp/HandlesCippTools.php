<?php

namespace App\Services\Cipp;

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
        return $this->cippDispatch($toolName, $input, function (string $tenantDomain) use ($toolName, $input, $endpoint): array {
            try {
                $rows = app(CippClient::class)->get($endpoint, ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }

            // Projection + prompt-fencing live in the shared contract, so the direct
            // path shapes CIPP's rows identically to the MCP relay (psa-d2hj).
            return app(CippToolContract::class)->shape($toolName, $rows, $input, $this->clientId);
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
        return $this->cippDispatch($toolName, $input, function (string $tenantDomain) use ($toolName, $input, $endpoint, $userParam): array {
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
                $rows = app(CippClient::class)->get($endpoint, $query);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }

            return app(CippToolContract::class)->shape($toolName, $rows, $input, $this->clientId);
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
            $endpoint = $userId ? 'api/ListUserSigninLogs' : 'api/ListSignIns';
            $params = ['TenantFilter' => $tenantDomain];

            if ($userId) {
                // ListUserSigninLogs filters Graph on the signIn `userId` property, an
                // Azure AD OBJECT ID; a UPN there matches zero rows and returns an empty
                // 200. cippDispatch() has ALREADY run this same guard before choosing a
                // transport, so an error here is unreachable in practice — honoured
                // rather than assumed away, because "the caller checked" is exactly the
                // assumption this series keeps being punished for.
                $resolved = CippToolContract::requireObjectId($userId, $this->clientId);
                if (isset($resolved['error'])) {
                    return ['error' => $resolved['error']];
                }

                $params['userId'] = $resolved['objectId'];
            } elseif ($days !== null) {
                // Invoke-ListSignIns windows SERVER-SIDE and defaults to $Days = 7, so a
                // 30-day request must forward the window or it silently sees 7 days while
                // reporting 30 (psa-536g). The user_id path resolves to
                // ListUserSigninLogs, which has no date filter at all ($top=50, newest
                // first), so Days applies only to the tenant-wide endpoint.
                $params['Days'] = $days;
            }

            try {
                $events = app(CippClient::class)->get($endpoint, $params);
            } catch (\Throwable $e) {
                return $this->cippFailure($endpoint, $e);
            }

            // The client-side day filter, projection and fencing live in the shared
            // contract (shapeEvents), so both transports produce the identical envelope.
            return app(CippToolContract::class)->shape('cipp_list_sign_ins', $events, $input, $this->clientId);
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
            $params = ['TenantFilter' => $tenantDomain, 'days' => CippToolContract::boundedDays($input['days'] ?? null, 2, 10)];
            foreach (['sender', 'recipient'] as $field) {
                if (! empty($input[$field])) {
                    $params[$field] = trim((string) $input[$field]);
                }
            }

            try {
                $messages = app(CippClient::class)->get('api/ListMessageTrace', $params);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListMessageTrace', $e);
            }

            // The client-side sender/recipient re-filter (Message Trace upstream
            // filtering is unreliable across CIPP versions), projection and fencing all
            // live in the shared contract, so both transports answer identically.
            return app(CippToolContract::class)->shape('cipp_list_message_trace', $messages, $input, $this->clientId);
        });
    }

    private function cippListMailQuarantine(array $input): array
    {
        return $this->cippDispatch('cipp_list_mail_quarantine', $input, function (string $tenantDomain) use ($input): array {
            try {
                $entries = app(CippClient::class)->get('api/ListMailQuarantine', ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListMailQuarantine', $e);
            }

            // Recipient re-filter, projection and fencing live in the shared contract.
            return app(CippToolContract::class)->shape('cipp_list_mail_quarantine', $entries, $input, $this->clientId);
        });
    }

    private function cippListUserMfaMethods(array $input): array
    {
        return $this->cippDispatch('cipp_list_user_mfa_methods', $input, function (string $tenantDomain) use ($input): array {
            // CIPP's ListMFAUsers returns the user-level MFA picture: registration
            // status, method types, and which enforcement mechanism (CA / Security
            // Defaults / per-user) currently covers them. ListPerUserMFA is the
            // legacy per-user-toggle list and doesn't reflect modern MFA at all.
            try {
                $rows = app(CippClient::class)->get('api/ListMFAUsers', ['TenantFilter' => $tenantDomain]);
            } catch (\Throwable $e) {
                return $this->cippFailure('api/ListMFAUsers', $e);
            }

            // Matching the requested user against the tenant's MFA rows, projecting the
            // row and deriving the enforcement summary all live in the shared contract.
            return app(CippToolContract::class)->shape('cipp_list_user_mfa_methods', $rows, $input, $this->clientId);
        });
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
