<?php

namespace App\Services\Cipp;

use App\Models\Person;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The CIPP read contract — everything about these tools that is true regardless
 * of HOW we talk to CIPP.
 *
 * There are two transports to the same CIPP tools:
 *
 *   - the DIRECT one (HandlesCippTools → CippClient, plain REST), which
 *     TriageToolExecutor ALWAYS takes and AssistantToolExecutor (inline chat AND
 *     Chet's staff MCP surface) falls back to whenever the MCP relay is off or
 *     unconfigured; and
 *   - the MCP RELAY (CippMcpToolRelay → CippMcpClient).
 *
 * psa-dbrw / psa-idii / psa-9d4l fixed three CIPP security reads that answered a
 * confident "nothing found" — the worst possible failure for a security read,
 * because "no malicious OAuth consent" and "no Conditional Access gaps" are
 * exactly the answers an attacker would like the analyst to receive. Those fixes
 * were written in the relay ONLY, and the direct path kept every original bug.
 * That is the fourth time in this series that a fix landed at one call site while
 * another path to the same danger stayed open.
 *
 * So the rules live HERE, once, and BOTH transports are routed through them. A
 * transport chooses its own wire format (REST query params vs MCP arguments,
 * whose names are verified separately against CIPP-API source); it does NOT get
 * to decide which questions are answerable or what a row means.
 */
class CippToolContract
{
    private const MAX_NESTED_ARRAY_DEPTH = 8;

    /**
     * Real ListOAuthApps shape (verified against CIPP-API Invoke-ListOAuthApps.ps1
     * and the UseReportDB twin Get-CIPPOAuthAppsReport.ps1, which agree — psa-dbrw).
     *
     * CIPP does NOT return raw Graph here. It joins oauth2PermissionGrants with
     * servicePrincipals and hand-builds a PascalCase object emitting exactly
     * Name / ApplicationID / ObjectID / Scope / StartTime. `Scope` is a
     * COMMA-JOINED STRING, not an array — CIPP does `($_.scope -join ',')` — and it
     * is the field that makes an illicit-consent triage possible at all.
     *
     * @var array<string, array<int, string>>
     */
    /**
     * The user fields an audit row may carry, under Data.RawData. Named once because
     * two things read it — the per-user filter, and the check for whether the payload
     * can be attributed AT ALL — and if those two ever disagreed, the tool would go
     * back to answering a confident "this user did nothing" on rows it simply could
     * not read.
     *
     * @var array<int, string>
     */
    private const AUDIT_USER_KEYS = ['UserId', 'UserKey', 'CIPPUserKey', 'UserPrincipalName', 'userId'];

    /** The row is the requested user's. */
    private const ROW_MATCH = 'match';

    /** The row is provably somebody else's — a comparable identity that isn't theirs, or an actor no user is named by. */
    private const ROW_OTHER_ACTOR = 'other_actor';

    /** The row names an actor we could not compare to the requested user at all. Excluding it proves nothing. */
    private const ROW_UNATTRIBUTABLE = 'unattributable';

    private const OAUTH_APP_FIELDS = [
        'id' => ['ObjectID', 'ObjectId', 'id', 'Id'],
        'appId' => ['ApplicationID', 'ApplicationId', 'applicationId', 'appId'],
        'displayName' => ['Name', 'name', 'displayName', 'DisplayName'],
        'scopes' => ['Scope', 'scopes', 'scope', 'Scopes'],
        'startTime' => ['StartTime', 'startTime'],
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /**
     * Questions CIPP structurally CANNOT answer — refused BEFORE we spend an
     * upstream call, on every transport.
     *
     * Both of these previously returned a clean, confident, empty result. A tool
     * that cannot answer must say so out loud; the one thing it must never do is
     * answer "nothing found" (psa-dbrw, psa-idii).
     *
     * This is deliberately a pure function of (tool, input) with no I/O: it is the
     * gate every CIPP dispatch passes through, and it must be impossible to reach
     * CIPP without having passed it.
     */
    public static function unanswerable(string $toolName, array $input): ?string
    {
        // CIPP's ListUserConditionalAccessPolicies posts a stale payload to the
        // Graph beta CA-evaluate action (parameter and type names that no longer
        // exist in its metadata). Graph rejects it, CIPP swallows the throw, sets
        // $GraphRequest = @{} and returns HTTP 200 with Body = @($GraphRequest) —
        // so this answered "no CA policies apply to this user" for every user,
        // forever, with no error anywhere. CIPP's own source marks the endpoint
        // "# XXX - Unused endpoint?".
        if ($toolName === 'cipp_list_user_conditional_access') {
            return 'Per-user Conditional Access evaluation is UNAVAILABLE: the upstream CIPP endpoint '
                .'(ListUserConditionalAccessPolicies) is broken and returns no data. Do NOT interpret this as '
                .'"no policies apply to this user". Use cipp_list_conditional_access_policies for the tenant-wide '
                .'Conditional Access policy set and check its include/exclude membership yourself.';
        }

        // CIPP drops principalId and consentType from the raw grant, so consent
        // cannot be attributed to a user from this endpoint at all. The old filters
        // matched keys CIPP never emits, so every user_id call filtered out 100% of
        // rows and reported count: 0 — a false negative on illicit consent grant, a
        // top phishing/persistence vector.
        //
        // The advertised input schema no longer offers user_id, but the executors do
        // not enforce their schema at runtime before dispatch, so the refusal has to
        // live in the code path, not in the documentation.
        if ($toolName === 'cipp_list_oauth_apps' && ! empty($input['user_id'])) {
            return 'Per-user OAuth consent attribution is UNAVAILABLE: CIPP\'s ListOAuthApps drops principalId and '
                .'consentType, so a consent cannot be tied to a specific user. Do NOT interpret this as "this user '
                .'consented to no apps". Call cipp_list_oauth_apps WITHOUT user_id for the tenant-wide list of '
                .'consented applications and their granted scopes.';
        }

        return null;
    }

    // ── Identity: the questions we cannot ASK ──

    /**
     * Tools whose upstream endpoint can filter by an Azure AD OBJECT ID and nothing
     * else. Handed any other identity form, the endpoint does not fail — it MATCHES
     * NOTHING, and the tool reports a clean, confident zero.
     *
     * cipp_list_sign_ins routes a user-filtered request to CIPP's ListUserSigninLogs,
     * which builds a Microsoft Graph filter on the signIn resource's `userId` property
     * (verified against CIPP-API dev, Invoke-ListUserSigninLogs.ps1 lines 17-18):
     *
     *     $UserID = $Request.Query.UserID
     *     $URI = ".../auditLogs/signIns?`$filter=(userId eq '$UserID')&$top=$top..."
     *
     * On a signIn, `userId` (an object ID) and `userPrincipalName` (a UPN) are two
     * SEPARATE properties — CIPP's own Invoke-ListBasicAuth.ps1 selects
     * userPrincipalName explicitly, and its BEC runbook (Push-BECRun.ps1 line 59) feeds
     * this same filter the object ID it got from ExecDismissRiskyUser. So `userId eq
     * '<upn>'` is a perfectly valid string comparison that matches zero rows: HTTP 200,
     * empty body, no error anywhere.
     *
     * This is NOT a list of endpoints that merely PREFER an object ID. The sibling
     * user-scoped CIPP reads all tolerate a UPN and are deliberately absent:
     *
     *   - ListUserGroups addresses Graph as /users/{id | userPrincipalName}/memberOf,
     *     which accepts either form (Invoke-ListUserGroups.ps1 line 15);
     *   - ListmailboxPermissions passes the identity to Exchange's Get-Mailbox /
     *     Get-MailboxPermission / Get-RecipientPermission -Identity
     *     (Invoke-ListmailboxPermissions.ps1 lines 50-69);
     *   - ListUserMailboxRules passes it to Get-InboxRule -Mailbox
     *     (Invoke-ListUserMailboxRules.ps1 line 22).
     *
     * Exchange's -Identity/-Mailbox and Graph's user addressing both resolve a UPN
     * natively, and both THROW on an identity they cannot resolve — which surfaces as
     * an error, not as an empty result. Adding them here would hard-error paths that
     * work today, and a tool that refuses constantly is a tool that gets ignored.
     *
     * @var array<int, string>
     */
    private const OBJECT_ID_ONLY_TOOLS = ['cipp_list_sign_ins'];

    /**
     * The second gate, and the reason it is separate from unanswerable().
     *
     * unanswerable() is a pure function of (tool, input): it refuses questions CIPP
     * structurally cannot answer, for anyone, always. This one is about the questions
     * THIS CALLER cannot ask RIGHT NOW — it depends on whether the client's own synced
     * people can bridge the identity to the form the endpoint requires, so it needs the
     * client scope and it touches the database.
     *
     * Both transports pass through it before spending an upstream call, for the same
     * reason unanswerable() lives where it does: a guard written at one call site is a
     * guard the next call site does not have, which is the failure this whole series
     * keeps re-learning.
     */
    public static function identityRefusal(string $toolName, array $input, ?int $clientId): ?string
    {
        if (! in_array($toolName, self::OBJECT_ID_ONLY_TOOLS, true)) {
            return null;
        }

        // No user filter is a tenant-wide question — there is no identity to bridge, and
        // cipp_list_sign_ins routes it to a different endpoint (ListSignIns) entirely.
        $requested = self::optionalUserId($input);
        if ($requested === null) {
            return null;
        }

        $resolved = self::requireObjectId($requested, $clientId);
        if (! isset($resolved['error'])) {
            return null;
        }

        Log::warning('[CippTools] User-scoped CIPP read refused: the requested identity cannot be resolved to an Azure AD object ID', [
            'tool' => $toolName,
            'has_client_scope' => $clientId !== null,
            'requested_is_object_id' => self::looksLikeObjectId($requested),
        ]);

        return $resolved['error'];
    }

    /**
     * The Azure AD object ID for a user-scoped read that accepts nothing else — or an
     * explicit refusal, which the caller MUST return instead of calling CIPP.
     *
     * An input that already IS an object ID passes straight through: it needs no bridge,
     * no client scope and no lookup, and refusing it would be crying wolf on the agent's
     * normal path (cipp_list_users hands the agent `id`, an object ID). Anything else —
     * a UPN, a display name, a bare alias — must be bridged by a Person in the REQUESTING
     * CLIENT, via the one client-scoped resolver, or the question is refused.
     *
     * The bridge is frequently absent, and that is precisely the point: CIPP contact sync
     * is opt-in and OFF by default, so for most tenants no Person carries a cipp_user_id
     * at all. Before this guard, that meant the UPN went upstream unchanged, Graph matched
     * nothing, and the agent was told `count: 0` — "this user has no sign-ins" — while
     * investigating a possible account compromise.
     *
     * Note what is NOT done here: an UNSCOPED people lookup. people.cipp_user_id is
     * globally unique, which makes `where('cipp_user_id', $id)` look unambiguous while
     * letting it resolve into whatever client happens to hold that GUID — answering this
     * tenant's security question with another tenant's identity. That is a disclosure,
     * and strictly worse than the false negative it would be fixing.
     *
     * @return array{objectId?: string, error?: string}
     */
    public static function requireObjectId(string $requested, ?int $clientId): array
    {
        // The single client-scoped resolver. With no client id there is no bridge to
        // build, so this returns the input unchanged and an identity that is not already
        // an object ID falls through to the refusal below — which is the correct outcome,
        // not an accident.
        $resolved = self::resolveUserId($requested, $clientId);

        if (self::looksLikeObjectId($resolved)) {
            return ['objectId' => $resolved];
        }

        return [
            'error' => "Sign-in activity CANNOT be looked up for {$requested} right now: CIPP's per-user sign-in endpoint "
                .'(ListUserSigninLogs) filters Microsoft Graph on the Azure AD OBJECT ID, and no synced person in this client '
                ."maps {$requested} to one. Sent as-is it would match nothing and come back empty. Do NOT interpret this as "
                ."\"{$requested} has no sign-ins\" — the query was never run. Call cipp_list_users, take the `id` field for "
                .'this user (that is the object ID), and re-run cipp_list_sign_ins with it. To sweep the whole tenant instead, '
                .'call cipp_list_sign_ins WITHOUT user_id.',
        ];
    }

    // ── Audit logs ──

    /**
     * Real ListAuditLogs shape (verified against CIPP-API Invoke-ListAuditLogs.ps1,
     * psa-9d4l). CIPP reads its audit-log STORE — an Azure Table fed by its webhook
     * pipeline, not a live unified-audit-log search — and RENAMES on the way out:
     *
     *   Select-Object @{n='LogId';     exp={$_.RowKey}},
     *
     *                 @{n='Timestamp'; exp={$_.Data.RawData.CreationTime}},
     *                 Tenant, Title, Data
     *
     * so the top-level keys are LogId / Timestamp / Tenant / Title / Data, and the
     * actual audit fields (Operation, UserId, Workload, ResultStatus, ClientIP) sit
     * TWO levels down at Data.RawData.*.
     *
     * Both paths previously named the raw unified-audit-log keys at the TOP level,
     * so none of the fields resolved — and both filters read top-level too, while
     * the date filter drops a row when it finds no date key at all. So passing
     * `days` OR `user_id` dropped 100% of rows, and passing neither returned rows
     * that projected to `{}` (direct path: to a raw, unbounded `Data` blob). There
     * was no input combination that returned usable data, and the tool answered "no
     * audit events" to every question asked of it.
     *
     * @return array<string, mixed>
     */
    public function shapeAuditLogs(array $rows, array $input, ?int $clientId): array
    {
        $rows = self::normalizeRows($rows);
        $totalReturned = count($rows);

        $userId = self::optionalUserId($input);
        $days = self::windowDays($input['days'] ?? null);

        // A per-user question with no client scope is not answerable, and the two ways
        // of answering it anyway are both worse than saying so:
        //
        //   - look the identity up UNSCOPED. people.cipp_user_id is globally unique, so
        //     such a lookup LOOKS unambiguous — and that is the trap: it resolves to
        //     whichever client happens to hold that object ID, so it will happily answer
        //     THIS tenant's question with ANOTHER client's person. That is a
        //     cross-client disclosure AND false investigation context, strictly worse
        //     than the false negative it would be fixing; or
        //   - filter anyway with a half-built needle set — which is how this tool came
        //     to answer "this user did nothing" in the first place.
        //
        // Neither executor can reach this state (both derive the CIPP tenant filter
        // from the client, so a clientless call is refused before dispatch). It is
        // enforced here because this is the layer that would do the lookup, and a
        // guard that lives only at the call site is a guard that a future call site
        // does not have — the recurring failure this whole contract exists to end.
        if ($userId !== null && $clientId === null) {
            Log::warning('[CippTools] Audit user filter requested with no client scope — refusing rather than looking an identity up across tenants', [
                'tool' => 'cipp_list_audit_logs',
                'row_count' => $totalReturned,
            ]);

            return [
                'error' => 'Audit events CANNOT be filtered by user right now: this CIPP call carries no client scope, so '
                    ."{$userId} cannot be resolved to the user's other identity forms. A CIPP audit row names the actor by UPN "
                    .'OR by Azure AD object ID, and the mapping between the two lives on the requesting client\'s synced people '
                    ."— which cannot be read across tenants. Do NOT interpret this as \"{$userId} did nothing\". Re-run "
                    .'cipp_list_audit_logs WITHOUT user_id and read the actors yourself.',
                'total_returned_by_cipp' => $totalReturned,
                'filtered_by_user' => $userId,
            ];
        }

        // Project EVERY row before filtering, so the drift guard below sees the
        // whole upstream payload. Projecting only the survivors would go quiet in
        // exactly the case that matters: if CIPP's shape drifts, the date and user
        // filters (which read the same nested keys) drop every row first, leaving no
        // survivor left to look wrong — and the tool would go back to reporting a
        // confident count: 0 with nothing in the log to say why.
        $projected = array_map(fn (array $row): array => $this->projectAuditRow($row), $rows);

        if ($rows !== [] && array_filter($projected) === []) {
            // Row keys are schema names and safe to log; row values are untrusted
            // tenant data and are never logged.
            Log::warning('[CippTools] Every audit row projected empty — CIPP audit-log shape has drifted', [
                'tool' => 'cipp_list_audit_logs',
                'row_count' => $totalReturned,
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);
        }

        // "This user did nothing" and "I cannot tell who did any of this" are
        // different answers, and only one of them may be reported as count: 0.
        //
        // The drift guard above cannot catch this on its own: LogId / Timestamp /
        // Title live at the TOP level and keep resolving, so if CIPP's nested
        // Data.RawData block is ever moved or renamed, rows still project non-empty,
        // the guard stays quiet, and a user-filtered query matches nothing and
        // returns a clean, confident "no audit events for this user" — the exact
        // false negative this contract exists to prevent, back through the side door.
        //
        // So: if a user filter was asked for and NOT ONE row in a non-empty payload
        // carries a user key we know how to read, the filter is meaningless and its
        // zero is not evidence of absence. Fail loud instead.
        //
        // An empty payload is a different thing and stays a normal empty result —
        // CIPP genuinely returned nothing in the window, and saying so is honest.
        if ($userId !== null && $rows !== [] && ! $this->anyRowCarriesAUser($rows)) {
            Log::warning('[CippTools] Audit rows carry no readable user key — cannot attribute events to a user', [
                'tool' => 'cipp_list_audit_logs',
                'row_count' => $totalReturned,
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);

            return [
                'error' => "Audit events CANNOT be attributed to a user right now: CIPP returned {$totalReturned} event(s), "
                    .'but not one of them carries a readable user field (expected at Data.RawData.UserId), so filtering by '
                    ."user is meaningless. Do NOT interpret this as \"{$userId} did nothing\". Re-run "
                    .'cipp_list_audit_logs WITHOUT user_id to see the unattributed events, and treat the CIPP audit-log '
                    .'shape as suspect.',
                'total_returned_by_cipp' => $totalReturned,
                'filtered_by_user' => $userId,
            ];
        }

        $cutoff = $days !== null ? now()->subDays($days) : null;

        // The identity the caller asks WITH is not the identity CIPP answers WITH.
        //
        // The advertised schema says user_id may be a UPN or an Azure AD object ID,
        // and cipp_list_users hands the agent an object ID — so that is what it passes
        // here. But ListAuditLogs returns `Data` untouched from CIPP's audit-log store
        // and does NOT normalize Data.RawData.UserId to an object ID: for a user
        // action it carries whatever the M365 audit event carried, which is the UPN.
        //
        // Resolving to a SINGLE value handled only one direction of that (UPN → object
        // ID, plus the UPN itself when the input contained '@'). Handed an object ID
        // there was no reverse lookup, every UPN-keyed row was dropped, and the tool
        // answered a clean count: 0 — "this user did nothing" — on the normal agent
        // path. So: a needle SET, holding every identity form we know the user by.
        $needles = $userId !== null ? self::userIdentityNeedles($userId, $clientId) : [];

        $events = [];
        $unattributable = 0;

        foreach ($rows as $index => $row) {
            if ($cutoff !== null && ! $this->auditEventWithinCutoff($row, $cutoff)) {
                continue;
            }

            // Gated on the REQUEST, never on the needle set. Skipping the filter when
            // the needle set came back empty would hand the caller every actor's events
            // as if they were the requested user's — a fail-OPEN disclosure bolted onto
            // a fail-closed bug. An empty needle set means we can adjudicate nothing, so
            // every row lands as unattributable and the query fails loud below.
            if ($userId !== null) {
                $verdict = $this->classifyAuditRow($row, $needles);

                if ($verdict === self::ROW_UNATTRIBUTABLE) {
                    // NOT silently dropped: this row names an actor we could never
                    // have compared to the requested user, so excluding it is not a
                    // finding — it is a hole in the answer, and it gets counted out
                    // loud below.
                    $unattributable++;

                    continue;
                }

                if ($verdict === self::ROW_OTHER_ACTOR) {
                    continue;
                }
            }

            $events[] = $projected[$index];
        }

        // Nothing matched, and at least one event was unreadable to the filter: the
        // zero is an artefact of the filter, not a fact about the user. This is the
        // same false-clean as before, one layer deeper — it is what an object-ID
        // request against UPN-keyed rows degrades to when NO synced person in this
        // client maps the two (CIPP contact sync is opt-in and off by default, so
        // that is the common case, not an exotic one).
        if ($userId !== null && $events === [] && $unattributable > 0) {
            Log::warning('[CippTools] Audit user filter could not be compared to any event actor — a zero here would be an artefact of the filter', [
                'tool' => 'cipp_list_audit_logs',
                'row_count' => $totalReturned,
                'unattributable' => $unattributable,
                'requested_is_object_id' => self::looksLikeObjectId($userId),
            ]);

            return [
                'error' => "Audit events CANNOT be attributed to {$userId} right now: CIPP returned {$totalReturned} event(s), "
                    ."{$unattributable} of which name an actor that cannot be compared to {$userId} — the audit rows identify "
                    .'the actor in a different identity form (UPN vs Azure AD object ID) and no synced person in this client '
                    .'maps the two. NOTHING matched, so a zero here would be an artefact of the filter, NOT evidence that '
                    ."{$userId} did nothing. Re-run cipp_list_audit_logs WITHOUT user_id and read the actors yourself, or retry "
                    .'with the user\'s other identity form (the UPN if you passed an object ID, or the object ID if you passed a UPN).',
                'total_returned_by_cipp' => $totalReturned,
                'unattributable_events' => $unattributable,
                'filtered_by_user' => $userId,
            ];
        }

        $result = [
            'count' => count($events),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];

        // A partial answer must arrive labelled as one. "Here are Alice's 3 events"
        // reads as the whole picture, and the agent has no other way to learn that a
        // fourth event existed and could not be read.
        if ($unattributable > 0) {
            $result['unattributable_events'] = $unattributable;
            $result['warning'] = "{$unattributable} of the {$totalReturned} event(s) CIPP returned name an actor that could not be "
                ."compared to {$userId} (a different identity form, or no readable actor at all). They are NOT in the list above. "
                .'Re-run cipp_list_audit_logs WITHOUT user_id to read them before concluding anything about what this user did.';
        }

        return $result;
    }

    /**
     * An allowlist projection: the raw `Data` blob carries arbitrary nested tenant
     * content (AuditData command strings, target resources) that is both unbounded
     * and attacker-influenced, and must never reach the agent.
     *
     * @return array<string, mixed>
     */
    private function projectAuditRow(array $event): array
    {
        $raw = $this->auditRawData($event);

        $projected = array_filter([
            'logId' => $event['LogId'] ?? $event['logId'] ?? null,
            'timestamp' => $this->auditEventTimestamp($event),
            'title' => $event['Title'] ?? $event['title'] ?? null,
            'operation' => $raw['Operation'] ?? $raw['operation'] ?? null,
            'userId' => $raw['UserId'] ?? $raw['UserKey'] ?? $raw['CIPPUserKey'] ?? null,
            'workload' => $raw['Workload'] ?? $raw['workload'] ?? null,
            'resultStatus' => $raw['ResultStatus'] ?? $raw['resultStatus'] ?? null,
            'clientIp' => $raw['ClientIP'] ?? $raw['ClientIp'] ?? $raw['clientIp'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        foreach ($projected as $field => $value) {
            $projected[$field] = $this->fence('cipp_list_audit_logs', $field, $value);
        }

        return $projected;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRawData(array $event): array
    {
        $data = $event['Data'] ?? $event['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        $raw = $data['RawData'] ?? $data['rawData'] ?? null;

        return is_array($raw) ? $raw : [];
    }

    private function auditEventTimestamp(array $event): ?string
    {
        foreach (['Timestamp', 'timestamp'] as $key) {
            if (! empty($event[$key])) {
                return (string) $event[$key];
            }
        }

        $raw = $this->auditRawData($event);
        foreach (['CreationTime', 'creationTime'] as $key) {
            if (! empty($raw[$key])) {
                return (string) $raw[$key];
            }
        }

        return null;
    }

    /**
     * An event we cannot date is KEPT, not dropped. CIPP already windows this
     * endpoint server-side (RelativeTime), so the client-side cutoff is a secondary
     * guard — and silently discarding an undateable security event is precisely the
     * fail-closed behaviour that made this tool answer "nothing found".
     */
    private function auditEventWithinCutoff(array $event, Carbon $cutoff): bool
    {
        $timestamp = $this->auditEventTimestamp($event);
        if ($timestamp === null) {
            return true;
        }

        try {
            return Carbon::parse($timestamp)->gte($cutoff);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Does ANY row carry a user field we know how to read? One is enough: a payload
     * where some events are attributable and others are not is normal (system events
     * have no actor), and the filter works correctly on it. It is the payload where
     * NONE of them are that we cannot answer a per-user question from.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function anyRowCarriesAUser(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($this->auditRowActors($row) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every actor identity this row names, lowercased. The single place the audit
     * user keys are read: the per-user filter, the attributability check and the
     * shape-drift guard all come through here, so they cannot disagree about what
     * counts as "this row names a user".
     *
     * @return array<int, string>
     */
    private function auditRowActors(array $row): array
    {
        $raw = $this->auditRawData($row);
        $actors = [];

        foreach (self::AUDIT_USER_KEYS as $key) {
            $value = $raw[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $actors[] = mb_strtolower(trim($value));
            }
        }

        return array_values(array_unique($actors));
    }

    /**
     * Compare LIKE WITH LIKE, and never silently drop a row you could not compare.
     *
     * A row is only EXCLUDED when we can show it is somebody else — either its actor
     * is an identity of the same FORM as one of our needles and does not equal any of
     * them (a real, provable mismatch), or its actor is not a user identity at all
     * (a service principal, a SID, "Microsoft Substrate Management"), a form no human
     * user is ever named by, so the requested user cannot be hiding behind it.
     *
     * A row whose actor IS a user identity (a UPN or an object ID) but of a form we
     * hold no needle for is UNATTRIBUTABLE: we could never have matched it, so its
     * exclusion is not evidence of anything and must not be laundered into a count of
     * zero. That is the whole failure this method exists to prevent.
     *
     * @param  array<int, string>  $needles  lowercased, non-empty
     */
    private function classifyAuditRow(array $row, array $needles): string
    {
        $actors = $this->auditRowActors($row);
        if ($actors === []) {
            return self::ROW_UNATTRIBUTABLE;
        }

        $sawComparable = false;
        $sawUserIdentity = false;

        foreach ($actors as $actor) {
            if (in_array($actor, $needles, true)) {
                return self::ROW_MATCH;
            }

            $form = self::identityForm($actor);
            if ($form === null) {
                continue;
            }

            $sawUserIdentity = true;
            if (self::hasNeedleOfForm($needles, $form)) {
                $sawComparable = true;
            }
        }

        if ($sawComparable) {
            return self::ROW_OTHER_ACTOR;
        }

        return $sawUserIdentity ? self::ROW_UNATTRIBUTABLE : self::ROW_OTHER_ACTOR;
    }

    /**
     * The identity FORMS a user can be named by — the two the CIPP tool schema itself
     * advertises for user_id, and the two an audit row's actor can carry. Anything
     * else (a SID, a service string) names something that is not a user.
     */
    private static function identityForm(string $value): ?string
    {
        if (self::looksLikeObjectId($value)) {
            return 'objectId';
        }

        return str_contains($value, '@') ? 'upn' : null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function hasNeedleOfForm(array $needles, string $form): bool
    {
        foreach ($needles as $needle) {
            if (self::identityForm($needle) === $form) {
                return true;
            }
        }

        return false;
    }

    // ── OAuth apps ──

    /**
     * Tenant-wide only — a per-user call never reaches here, because unanswerable()
     * refuses it before any transport spends a call. There is deliberately no user
     * filter left in this method: the one it used to have matched principalId /
     * consentedBy / userId / userPrincipalName, four keys CIPP does not emit, so it
     * dropped every row and reported count: 0 (psa-dbrw).
     *
     * @return array<string, mixed>
     */
    public function shapeOauthApps(array $rows): array
    {
        $rows = self::normalizeRows($rows);
        $totalReturned = count($rows);

        $projected = array_map(function (array $row): array {
            $app = [];

            foreach (self::OAUTH_APP_FIELDS as $field => $candidates) {
                foreach ($candidates as $candidate) {
                    if (! array_key_exists($candidate, $row)) {
                        continue;
                    }

                    if ($row[$candidate] !== null) {
                        $app[$field] = $this->fence('cipp_list_oauth_apps', $field, $row[$candidate]);
                    }

                    break;
                }
            }

            return $app;
        }, $rows);

        if ($rows !== [] && array_filter($projected) === []) {
            Log::warning('[CippTools] Every OAuth app row projected empty — CIPP ListOAuthApps shape has drifted', [
                'tool' => 'cipp_list_oauth_apps',
                'row_count' => $totalReturned,
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);
        }

        return [
            'count' => count($projected),
            'total_returned_by_cipp' => $totalReturned,
            'apps' => array_slice($projected, 0, 50),
        ];
    }

    // ── Shared primitives ──

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeRows(array $rows): array
    {
        if (array_is_list($rows)) {
            return array_values(array_filter($rows, 'is_array'));
        }

        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (isset($rows[$key]) && is_array($rows[$key])) {
                return self::normalizeRows($rows[$key]);
            }
        }

        return [$rows];
    }

    /**
     * Translate a UPN (email) to the Azure AD object ID that CIPP's per-user
     * endpoints expect. Pass-through when the input already looks like a GUID, or
     * when no synced Person matches (the caller then sees CIPP's own answer).
     *
     * This resolves ONE WAY, because that is all a request parameter needs. Anything
     * that has to recognise the user in CIPP's ANSWER needs every form the user is
     * known by — userIdentityNeedles() below, not this.
     */
    public static function resolveUserId(string $input, ?int $clientId): string
    {
        if (self::looksLikeObjectId($input)) {
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

    /**
     * Every identity form the requested user is known by, lowercased.
     *
     * CIPP takes an object ID on the way IN and hands back a UPN on the way OUT (and
     * on other endpoints, the reverse), so anything that has to recognise a user in a
     * CIPP row must hold BOTH — plus whatever the caller actually asked with. Matching
     * on a single resolved value is how cipp_list_audit_logs came to answer "this user
     * did nothing" to an object-ID request, and how cipp_list_mailbox_rules could have
     * discarded the rules of the very mailbox it was asked about (psa-7lgo.1).
     *
     * The people lookup is ALWAYS scoped to the requesting client, and there is no
     * unscoped branch to fall into. A CIPP tool call must never cross a client boundary
     * (see TriageToolExecutor's constructor-bound clientId invariant), and the fact that
     * people.cipp_user_id is globally UNIQUE is a trap rather than a licence: it makes
     * an unscoped `where('cipp_user_id', $id)` look unambiguous while letting it resolve
     * into whatever client happens to hold that object ID — answering this client's
     * question with a stranger's identity. That is a disclosure, and worse than the
     * false negative it would be fixing. With no client id there is simply no bridge to
     * build, and the caller must decide what that means: for a filter over CIPP's
     * answers, it means fail loud (shapeAuditLogs); for a defence-in-depth scope check
     * over an already mailbox-scoped answer, it means keep the row (mailboxRuleIsForeign).
     *
     * Comparison is case-insensitive throughout — UPNs are case-insensitive, and the
     * object ID we hold and the one CIPP echoes back need not agree on hex casing.
     *
     * @return array<int, string>
     */
    public static function userIdentityNeedles(string $requested, ?int $clientId): array
    {
        $needles = [$requested, self::resolveUserId($requested, $clientId)];

        if ($clientId !== null) {
            $lowered = mb_strtolower(trim($requested));

            $person = Person::where('client_id', $clientId)
                ->where(function ($query) use ($lowered): void {
                    $query->whereRaw('LOWER(cipp_upn) = ?', [$lowered])
                        ->orWhereRaw('LOWER(cipp_user_id) = ?', [$lowered]);
                })
                ->first();

            if ($person !== null) {
                $needles[] = (string) $person->cipp_upn;
                $needles[] = (string) $person->cipp_user_id;
                $needles[] = (string) $person->email;
            }
        }

        $needles = array_map(fn (string $needle): string => mb_strtolower(trim($needle)), $needles);

        // An empty needle would match every row that carries an empty field — the
        // filter would stop filtering and start attributing other people's events to
        // this user.
        return array_values(array_unique(array_filter($needles, fn (string $needle): bool => $needle !== '')));
    }

    public static function looksLikeObjectId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public static function requiredUserId(array $input): ?string
    {
        $userId = $input['user_id'] ?? null;
        if (! is_string($userId) && ! is_numeric($userId)) {
            return null;
        }

        $userId = trim((string) $userId);

        return $userId !== '' ? $userId : null;
    }

    public static function optionalUserId(array $input): ?string
    {
        return ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
    }

    /**
     * The requested window, or null when the caller asked for none.
     *
     * Both ListAuditLogs and ListSignIns window SERVER-SIDE and default to the last
     * 7 days when handed no window, so a 30-day request silently saw 7 days of data
     * while the response still reported filtered_by_days: 30 — a lying metadata
     * field, which is worse than a missing one because it turns "we didn't look"
     * into "there was nothing to find" (psa-9d4l / psa-536g). Whatever this returns,
     * the transport MUST forward it upstream.
     */
    public static function windowDays(mixed $value, int $max = 30): ?int
    {
        return isset($value) && is_numeric($value)
            ? (int) min(max(1, $value), $max)
            : null;
    }

    public static function boundedDays(mixed $value, int $default, int $max): int
    {
        return isset($value) && is_numeric($value)
            ? (int) min(max(1, $value), $max)
            : $default;
    }

    /**
     * Fence a projected value before it reaches the agent: untrusted free text is
     * redacted, instruction-neutralized and wrapped as data; arrays are bounded and
     * their leaves fenced the same way.
     */
    public function fence(string $toolName, string $field, mixed $value): mixed
    {
        if (is_string($value) && self::isFreeTextField($field)) {
            return $this->textSanitizer->sanitize($this->fieldLabel($toolName, $field), $value, 1000);
        }

        if (is_array($value)) {
            return $this->boundArray($toolName, $field, $value);
        }

        return $value;
    }

    public function boundArray(string $toolName, string $field, array $value, int $depth = 0): array
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

    public function fieldLabel(string $toolName, string $field): string
    {
        $tool = str_replace('_', ' ', preg_replace('/^cipp_/', '', $toolName) ?? $toolName);

        return "CIPP {$tool} {$field}";
    }

    public static function isFreeTextField(string $field): bool
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
            // An inbox rule's target folder is named by the mailbox owner (or by
            // whoever planted the rule), so it is attacker-controlled text on the
            // same footing as the rule's name and description. Unlike the rule's
            // recipient lists — arrays, already fenced item-by-item by boundArray()
            // — this one is a bare scalar and would otherwise reach the agent raw.
            'moveToFolder',
            'MoveToFolder',
            'Subject',
            'subject',
            // An OAuth application's display name is chosen by whoever registered
            // it, and a sign-in row names the app it was made against — so both are
            // attacker-controlled in exactly the scenarios these tools exist for
            // (illicit consent, malicious app sign-in).
            'appDisplayName',
            'publisherName',
            'Operation',
            'operation',
            // Audit-log titles embed user- and attacker-supplied strings (rule
            // names, subjects), so they are untrusted free text like any other.
            'title',
            'Title',
        ], true);
    }
}
