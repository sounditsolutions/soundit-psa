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

    /**
     * The two sentinel `Name` values Push-ListMailboxRulesQueue.ps1 writes into the
     * `cachembxrules` Rules column INSTEAD of a rule (verified against the vendor
     * source 2026-07-16, psa-4k6m). Frozen as literals here on purpose: they are the
     * vendor's strings, not ours, so they must be re-verified upstream rather than
     * reasoned about locally.
     *
     * The ERROR one is prefix-matched because the vendor appends the raw exception:
     *     "Could not connect to tenant $($_.Exception.message)"
     */
    private const MAILBOX_RULES_EMPTY_SENTINEL = 'No rules found';

    private const MAILBOX_RULES_ERROR_SENTINEL = 'Could not connect to tenant';

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
            // Quarantine policy names are admin-chosen display text; Message-ID
            // headers are arbitrary sender-controlled strings — fence both.
            'PolicyName',
            'MessageId',
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
            // An inbox rule's Identity and MailboxOwnerId (psa-4k6m, caught by the
            // psa-4k6m.2 security lane). They LOOK like identifiers and are not: Exchange
            // surfaces Identity as `<mailbox>\<rule id>` but also as display-name and
            // legacy-DN shapes, and the mailbox segment is a name a tenant admin chose —
            // so both are tenant-sourced text, on the same footing as the rule's name.
            // They reach the agent through the tenant-wide BEC sweep, i.e. precisely the
            // tool that runs against an already-compromised tenant, which is the worst
            // possible place to leave an unfenced text channel. Bare scalars, so unlike
            // forwardTo/redirectTo (arrays, fenced leaf-by-leaf by boundArray) nothing
            // else would have caught them.
            'identity',
            'Identity',
            'mailboxOwnerId',
            'MailboxOwnerId',
        ], true);
    }

    // ── Row shaping: projection + prompt-fencing, shared by both transports ──
    //
    // Everything below moved out of CippMcpToolRelay (psa-d2hj). HOW CIPP's rows are
    // projected to an allowlist and how untrusted tenant text is fenced are properties
    // of CIPP's response — identical no matter which transport fetched the row — so they
    // belong here beside unanswerable()/identityRefusal()/shapeAuditLogs(), and the
    // direct CippClient path (HandlesCippTools) is routed through shape() too. When this
    // lived in the relay alone, the direct path — the one auto-triage ALWAYS takes, and
    // the assistant/Chet fall back to whenever the MCP relay is off — returned CIPP's raw
    // rows straight through: unbounded vendor blobs, and attacker-controlled tenant text
    // (a malicious inbox-rule name, an OAuth/app display name, a quarantined subject)
    // reaching the agent UNFENCED.

    /**
     * The per-tool projection allowlists. Field names verified against CIPP-API source,
     * not guessed — casing is deliberately mixed because CIPP passes Graph through
     * camelCase but hands Exchange/PowerShell objects back PascalCase (psa-7lgo/psa-zw1j).
     *
     * @var array<string, array<int, string>>
     */
    private const DEFAULT_FIELDS = [
        // Graph assignedLicenses entries carry no skuPartNumber, so the license
        // summary can never surface friendly names — CIPP has already resolved
        // them into top-level LicJoined (comma-joined product names, psa-zw1j).
        'cipp_list_users' => ['id', 'displayName', 'userPrincipalName', 'accountEnabled', 'jobTitle', 'department', 'assignedLicenses', 'licJoined'],
        // No mailboxSizeBytes / itemCount here: CIPP's ListMailboxes runs
        // Get-Mailbox, which has no size or item-count properties at all (those
        // live on Get-MailboxStatistics), so they never resolved under ANY
        // casing and were dead advertised fields (psa-7lgo).
        'cipp_list_mailboxes' => ['id', 'displayName', 'userPrincipalName', 'primarySmtpAddress', 'recipientTypeDetails', 'forwardingSmtpAddress', 'deliverToMailboxAndForward', 'litigationHoldEnabled'],
        // Real ListLicenses shape (verified against CIPP-API Get-CIPPLicenseOverview,
        // psa-zw1j): hand-built rows, NOT raw Graph subscribedSkus. Seat counts are
        // CountUsed / CountAvailable / TotalLicenses (strings), the display name is
        // License; upstream skuPartNumber duplicates License (a pretty name, not a
        // real part number) so it is deliberately not projected. consumedLicenses /
        // assignedLicenses / prepaidUnits / capabilityStatus are never emitted.
        'cipp_list_licenses' => ['skuId', 'license', 'totalLicenses', 'countUsed', 'countAvailable', 'termInfo'],
        // Graph managedDevice has no displayName / isCompliant — their resolving
        // siblings deviceName / complianceState carry the same signal (psa-zw1j).
        'cipp_list_devices' => ['id', 'deviceName', 'userPrincipalName', 'operatingSystem', 'osVersion', 'complianceState', 'managementAgent', 'enrolledDateTime', 'lastSyncDateTime', 'serialNumber'],
        'cipp_list_groups' => ['id', 'displayName', 'mail', 'mailEnabled', 'securityEnabled', 'groupTypes', 'description'],
        // Real ListUserGroups shape (Invoke-ListUserGroups renames everything via
        // Select-Object, psa-zw1j): Mail / MailEnabled / SecurityGroup / GroupTypes
        // (a comma-joined STRING, not an array) plus camelCase groupType /
        // calculatedGroupType — the discriminators that tell a security group from
        // a distribution list from an M365 group. No description key is emitted
        // under any casing, so it is omitted here (unlike cipp_list_groups).
        'cipp_list_user_groups' => ['id', 'displayName', 'mail', 'mailEnabled', 'securityEnabled', 'groupTypes', 'groupType', 'calculatedGroupType', 'onPremisesSync', 'isAssignableToRole'],
        // Real ListmailboxPermissions shape (verified against CIPP-API
        // Invoke-ListmailboxPermissions.ps1, psa-3twu): CIPP collapses
        // Get-MailboxPermission / Get-RecipientPermission / GrantSendOnBehalfTo
        // into two-key {User, Permissions} rows server-side. Permissions is a
        // joined string on FullAccess rows but a raw accessRights ARRAY on
        // SendAs rows; SendOnBehalf rows carry display names in User.
        'cipp_list_mailbox_permissions' => ['user', 'permissions'],
        'cipp_list_mailbox_rules' => ['name', 'enabled', 'priority', 'description', 'from', 'sentTo', 'forwardTo', 'redirectTo', 'deleteMessage', 'moveToFolder'],
        // Same raw Get-InboxRule fields as the per-mailbox tool above, PLUS the two
        // that say WHOSE mailbox the rule sits on. A tenant-wide sweep without an
        // owner is not an actionable BEC finding — "someone in this tenant forwards
        // mail externally" cannot be triaged. Note the owner must come off the raw
        // Exchange object: the CACHE path we read carries no UserPrincipalName (only
        // the UseReportDB path adds one, Push-GetMailboxRulesBatch.ps1), so there is
        // no userPrincipalName to project here and inventing one would be a lie.
        // forwardAsAttachmentTo is included where the per-mailbox tool omits it: it
        // is a third exfil verb alongside forwardTo/redirectTo and a tenant sweep is
        // exactly where you hunt for it.
        'cipp_list_tenant_mailbox_rules' => ['identity', 'mailboxOwnerId', 'name', 'enabled', 'priority', 'description', 'from', 'sentTo', 'forwardTo', 'redirectTo', 'forwardAsAttachmentTo', 'deleteMessage', 'moveToFolder', 'stopProcessingRules', 'tenant'],
        // No cipp_list_defender_state entry: shape() routes that tool to
        // shapeDefenderState(), which bypasses projectRows() and names its own
        // keys. The DEFAULT_FIELDS list that used to sit here was never read
        // and described a shape CIPP does not emit (psa-7lgo).
        //
        // No cipp_list_conditional_access_policies entry either, for the same
        // reason: CIPP FLATTENS each policy, so the raw-Graph nested names
        // (conditions / grantControls / sessionControls) match nothing at all.
        // Hand-projected by shapeConditionalAccessPolicies() (psa-mybo).
        'cipp_list_user_conditional_access' => ['id', 'displayName', 'state', 'result', 'conditions', 'grantControls', 'sessionControls'],
        'cipp_list_sign_ins' => ['id', 'createdDateTime', 'userPrincipalName', 'appDisplayName', 'ipAddress', 'clientAppUsed', 'conditionalAccessStatus', 'status', 'location', 'riskDetail', 'riskLevelAggregated', 'deviceDetail'],
        // No cipp_list_audit_logs entry: its real fields are nested two levels
        // down (Data.RawData.*), which a flat field list cannot express, so it
        // is hand-projected by shapeAuditLogs() (psa-9d4l).
        // Get-MessageTrace responses carry PascalCase FromIP / ToIP — toIP is the
        // REQUEST-parameter casing and never appears as a row key (psa-zw1j).
        'cipp_list_message_trace' => ['MessageTraceId', 'messageTraceId', 'Received', 'received', 'SenderAddress', 'senderAddress', 'RecipientAddress', 'recipientAddress', 'Subject', 'subject', 'Status', 'status', 'FromIP', 'fromIP', 'ToIP', 'toIP'],
        // Get-QuarantineMessage rows carry the quarantine reason in Type and the
        // expiry in Expires; QuarantineTypes is a request parameter that never
        // appears as a row key (psa-zw1j). PolicyName / MessageId / Size ride along.
        'cipp_list_mail_quarantine' => ['Identity', 'identity', 'ReceivedTime', 'receivedTime', 'SenderAddress', 'senderAddress', 'RecipientAddress', 'recipientAddress', 'Subject', 'subject', 'Type', 'type', 'PolicyName', 'MessageId', 'Size', 'ReleaseStatus', 'Expires', 'expires'],
        // No cipp_list_oauth_apps entry: shape() routes that tool to
        // shapeOauthApps(), which bypasses projectRows() and names its own keys —
        // the same treatment as audit logs and Defender state, and for the same
        // reason. CIPP's real ListOAuthApps shape is described there (psa-dbrw).
    ];

    /** @var array<string, array<int, string>> */
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
        // CIPP-API Invoke-ListMailboxes.ps1 (psa-7lgo). Resolve the casing CIPP
        // actually emits first, and keep the other as a defensive fallback.
        'recipientTypeDetails' => ['recipientTypeDetails', 'RecipientTypeDetails'],
        'forwardingSmtpAddress' => ['ForwardingSmtpAddress', 'forwardingSmtpAddress'],
        'deliverToMailboxAndForward' => ['DeliverToMailboxAndForward', 'deliverToMailboxAndForward'],
        'litigationHoldEnabled' => ['LitigationHoldEnabled', 'litigationHoldEnabled', 'LitigationHold', 'litigationHold'],

        // ListMailboxRules. CIPP caches the RAW Get-InboxRule object
        // (Push-ListMailboxRulesQueue.ps1: `Rules = [string]($Rule |
        // ConvertTo-Json)`) and hands those rows straight back — so every
        // property keeps Exchange's PascalCase. Declaring these camel/lowercase
        // with no aliases made every key miss and every rule row project to `{}`:
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
        // Tenant-wide sweep additions (psa-4k6m). Same raw Get-InboxRule object, so
        // same PascalCase-first rule. Identity is guaranteed present on a real rule —
        // Push-ListMailboxRulesQueue.ps1 filters `Where-Object { $_.Identity }` — which
        // is what lets sentinelName() tell a hand-built sentinel row from a real rule.
        'identity' => ['Identity', 'identity'],
        'mailboxOwnerId' => ['MailboxOwnerId', 'mailboxOwnerId'],
        'forwardAsAttachmentTo' => ['ForwardAsAttachmentTo', 'forwardAsAttachmentTo'],
        'stopProcessingRules' => ['StopProcessingRules', 'stopProcessingRules'],
        // Added by Invoke-ListMailboxRules on read, not by Get-InboxRule.
        'tenant' => ['Tenant', 'tenant'],

        'skuPartNumber' => ['skuPartNumber', 'SkuPartNumber', 'sku', 'SKU'],
        'license' => ['License', 'license'],
        'totalLicenses' => ['totalLicenses', 'TotalLicenses', 'prepaidUnitsEnabled'],
        'countUsed' => ['CountUsed', 'countUsed'],
        'countAvailable' => ['CountAvailable', 'countAvailable', 'availableUnits'],
        'termInfo' => ['TermInfo', 'termInfo'],
        'assignedLicenses' => ['assignedLicenses', 'AssignedLicenses', 'licenses', 'Licenses'],
        'licJoined' => ['LicJoined', 'licJoined'],
        'mail' => ['mail', 'Mail'],
        'mailEnabled' => ['mailEnabled', 'MailEnabled'],
        // Invoke-ListUserGroups renames securityEnabled to SecurityGroup (psa-zw1j).
        'securityEnabled' => ['securityEnabled', 'SecurityEnabled', 'SecurityGroup'],
        'groupTypes' => ['groupTypes', 'GroupTypes'],
        'groupType' => ['groupType', 'GroupType'],
        'calculatedGroupType' => ['calculatedGroupType', 'CalculatedGroupType'],
        'onPremisesSync' => ['onPremisesSync', 'OnPremisesSync', 'onPremisesSyncEnabled'],
        'isAssignableToRole' => ['isAssignableToRole', 'IsAssignableToRole'],
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

    /** @var array<string, array<int, string>> */
    private const SIGN_IN_NESTED_FIELDS = [
        'status' => ['errorCode', 'failureReason', 'additionalDetails'],
        'location' => ['city', 'state', 'countryOrRegion'],
        'deviceDetail' => ['displayName', 'operatingSystem', 'browser'],
    ];

    /**
     * Real ListConditionalAccessPolicies shape (verified against CIPP-API
     * Invoke-ListConditionalAccessPolicies.ps1, psa-mybo): CIPP does NOT return
     * raw Graph policies — it flattens each policy into a wide row with GUIDs
     * resolved to display names. The raw Graph nested keys (conditions /
     * grantControls / sessionControls) never exist on these rows; session
     * controls survive only inside `rawjson`, which is the full policy as a
     * JSON blob — large untrusted tenant data, deliberately never projected.
     */
    private const CA_POLICY_SCALAR_FIELDS = ['id', 'state', 'createdDateTime', 'modifiedDateTime'];

    /** Comma-joined Graph enum/ID values — single-line, fixed vocabulary. */
    private const CA_POLICY_ENUM_FIELDS = [
        'clientAppTypes', 'includePlatforms', 'excludePlatforms',
        'grantControlsOperator', 'builtInControls', 'termsOfUse',
    ];

    /** Comma-joined resolved display names — untrusted tenant free text. */
    private const CA_POLICY_NAME_FIELDS = [
        'includeLocations', 'excludeLocations',
        'includeApplications', 'excludeApplications',
        'customAuthenticationFactors',
    ];

    /**
     * Built with PowerShell Out-String: newline-joined display names with a
     * trailing newline. Split into lists, trimmed, and fenced per item.
     */
    private const CA_POLICY_NAME_LIST_FIELDS = [
        'includeUsers', 'excludeUsers', 'includeGroups', 'excludeGroups',
        'includeRoles', 'excludeRoles', 'includeUserActions',
        'includeAuthenticationContextClassReferences',
    ];

    /**
     * The single row-shaping entry point, called by BOTH transports after each has
     * fetched raw rows over its own wire format. Normalizes, then routes every tool to
     * its projection: a bespoke shaper where CIPP's row needs one, the generic allowlist
     * projectRows() otherwise. Refusals and window/identity WIRE concerns are handled
     * upstream (unanswerable()/identityRefusal() at the choke point, window/user
     * parameters per transport); this owns only the shape of the answer.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int|string, mixed>
     */
    public function shape(string $toolName, array $rows, array $input, ?int $clientId): array
    {
        $rows = self::normalizeRows($rows);
        $totalReturned = count($rows);

        return match ($toolName) {
            'cipp_list_sign_ins' => $this->shapeEvents(
                $toolName,
                $rows,
                $input,
                ['createdDateTime'],
                [
                    // The upstream endpoint that answered — derived from input so it is
                    // the same on both transports (the REST route just adds an api/ prefix).
                    'endpoint' => empty($input['user_id']) ? 'ListSignIns' : 'ListUserSigninLogs',
                    'filtered_by_user' => self::optionalUserId($input),
                    'filtered_by_days' => self::windowDays($input['days'] ?? null),
                    'total_returned_by_cipp' => $totalReturned,
                ],
            ),
            'cipp_list_audit_logs' => $this->shapeAuditLogs($rows, $input, $clientId),
            'cipp_list_oauth_apps' => $this->shapeOauthApps($rows),
            'cipp_list_message_trace' => $this->shapeMessageTrace($rows, $input, $totalReturned),
            'cipp_list_mail_quarantine' => $this->shapeMailQuarantine($rows, $input, $totalReturned),
            'cipp_list_user_mfa_methods' => $this->shapeUserMfaMethods($rows, $input, $clientId),
            'cipp_list_defender_state' => $this->shapeDefenderState($rows),
            'cipp_list_mailbox_rules' => $this->shapeMailboxRules($rows, $input, $clientId),
            'cipp_list_tenant_mailbox_rules' => $this->shapeTenantMailboxRules($rows),
            'cipp_list_conditional_access_policies' => $this->shapeConditionalAccessPolicies($rows),
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
     * exactly that mistake silently. Only rules we can PROVE belong to another
     * mailbox are dropped; an owner we cannot compare is kept (dropping on
     * "cannot compare" fails CLOSED and hides the requested user's own rules).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * The tenant-wide inbox-rule sweep (psa-4k6m).
     *
     * *** THIS ENDPOINT ENCODES BOTH "NOTHING FOUND" AND "I COULD NOT LOOK" AS FAKE
     * RULE ROWS, AND THE SECOND ONE IS A FALSE ALL-CLEAR ON THE CANONICAL BEC
     * PERSISTENCE MECHANISM. *** Read the producer before touching this.
     *
     * Invoke-ListMailboxRules.ps1 does not call Exchange; it reads the `cachembxrules`
     * table and returns `$_.Rules | ConvertFrom-Json` verbatim. The true producer of
     * that column is Push-ListMailboxRulesQueue.ps1 (verified 2026-07-16), which writes
     * THREE shapes under it:
     *
     *   real rules  `Rules = [string]($Rule | ConvertTo-Json)`   raw Get-InboxRule
     *   no rules    `$Rules = @(@{ Name = 'No rules found' }) | ConvertTo-Json`
     *   UNREACHABLE `$Rules = @{ Name = "Could not connect to tenant $($_.Exception.message)" } | ConvertTo-Json`
     *
     * The third arrives with HTTP 200 as a single row that projects to an entirely
     * ordinary-looking inbox rule: no forwarding, no delete, nothing suspicious. An
     * agent asking "does this tenant have malicious inbox rules?" would read a tenant
     * it never reached as "one benign rule — all clear". It is worse than an empty
     * list, because `[]` at least looks like an absence of data whereas this looks
     * like a completed scan. So it MUST hard-error (psa-7lgo rule 3: a degraded read
     * screams, it does not return a clean result).
     *
     * The second is a genuine all-clear and must be returned as an ABSENCE — an empty
     * list — never as a phantom rule named "No rules found" that an agent can count,
     * quote, or reason about.
     *
     * The "still loading" case is handled upstream of here, by CippQueueGuard on both
     * clients, and — unlike every other queue path in this integration — it is NOT
     * AllTenants-gated and fires for a concrete tenant on a cold (>1h) cache.
     */
    private function shapeTenantMailboxRules(array $rules): array
    {
        foreach ($rules as $rule) {
            $name = $this->sentinelName($rule);

            if ($name !== null && str_starts_with($name, self::MAILBOX_RULES_ERROR_SENTINEL)) {
                // Deliberately surfaced with the upstream text attached: "could not
                // connect" plus its reason is the whole diagnostic, and an operator
                // seeing 401 vs timeout knows which lever to pull. Sanitised because
                // it carries an upstream exception message.
                return ['error' => 'CIPP could not read mailbox rules for this tenant, so this is NOT an all-clear — the tenant was never scanned. Upstream said: '.$this->textSanitizer->sanitize(
                    'CIPP mailbox rules error',
                    mb_substr($name, 0, 200),
                    200,
                )];
            }
        }

        // A clean tenant. Drop the phantom row rather than project it.
        $rules = array_values(array_filter(
            $rules,
            fn (array $rule): bool => $this->sentinelName($rule) !== self::MAILBOX_RULES_EMPTY_SENTINEL,
        ));

        return $this->projectRows('cipp_list_tenant_mailbox_rules', $rules);
    }

    /**
     * The `Name` of a row that carries nothing else — the shape both of this
     * endpoint's sentinels take. A real Get-InboxRule object always has an Identity
     * (Push-ListMailboxRulesQueue filters on `Where-Object { $_.Identity }`), and the
     * sentinels are built by hand with a Name and nothing more, so requiring the
     * absence of Identity keeps a genuine rule that merely happens to be *called*
     * "No rules found" from being swallowed.
     *
     * @param  array<string, mixed>  $rule
     */
    private function sentinelName(array $rule): ?string
    {
        if ($this->resolveKey($rule, 'identity') !== null) {
            return null;
        }

        $key = $this->resolveKey($rule, 'name');
        $name = $key === null ? null : $rule[$key] ?? null;

        return is_string($name) ? $name : null;
    }

    private function shapeMailboxRules(array $rules, array $input, ?int $clientId): array
    {
        $requested = self::requiredUserId($input);
        $dropped = 0;

        if ($requested !== null) {
            $needles = self::userIdentityNeedles($requested, $clientId);

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
            Log::warning('[CippTools] Dropped mailbox rules belonging to another mailbox — upstream returned rules outside the requested scope', [
                'tool' => 'cipp_list_mailbox_rules',
                'dropped' => $dropped,
            ]);
        }

        return $this->projectRows('cipp_list_mailbox_rules', $rules);
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
        $ownerIsGuid = self::looksLikeObjectId($owner);

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
                : self::looksLikeObjectId($needle)
        ));

        if ($comparable === []) {
            return false;
        }

        return ! in_array($owner, $comparable, true);
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
     * Real ListConditionalAccessPolicies shape (psa-mybo): the flattened rows
     * documented on the CA_POLICY_* consts. Projecting the raw Graph nested
     * names matched nothing — every policy shrank to id/name/state and the agent
     * was structurally blind to who a policy targets (especially excludeUsers)
     * and what it enforces, while the output still looked healthy. Targeting and
     * control fields are projected from CIPP's actual flattened keys instead.
     *
     * An empty upstream list is genuinely ambiguous: CIPP's error path is
     * overwritten before return, so a failed Graph query (e.g. permissions)
     * also comes back HTTP 200 with empty Results — hence the warning.
     *
     * @return array<string, mixed>
     */
    private function shapeConditionalAccessPolicies(array $rows): array
    {
        $toolName = 'cipp_list_conditional_access_policies';

        $policies = array_map(function (array $row) use ($toolName): array {
            $policy = [];

            foreach (self::CA_POLICY_SCALAR_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $policy[$field] = $row[$field];
                }
            }

            if (array_key_exists('displayName', $row)) {
                $policy['displayName'] = $this->sanitizeProjectedValue($toolName, 'displayName', $row['displayName']);
            }

            foreach (self::CA_POLICY_ENUM_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $policy[$field] = is_string($row[$field]) ? trim($row[$field]) : $row[$field];
                }
            }

            foreach (self::CA_POLICY_NAME_FIELDS as $field) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }
                $value = is_string($row[$field]) ? trim($row[$field]) : $row[$field];
                // '' stays '' — an explicit "none" beats a fence around nothing.
                $policy[$field] = (is_string($value) && $value !== '')
                    ? $this->textSanitizer->sanitize($this->fieldLabel($toolName, $field), $value, 1000)
                    : $value;
            }

            foreach (self::CA_POLICY_NAME_LIST_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $policy[$field] = $this->caNameList($toolName, $field, $row[$field]);
                }
            }

            return $policy;
        }, array_slice($rows, 0, 50));

        // Sharpened drift guard: scalar fields resolving while every flattened
        // targeting/control key is absent LOOKS healthy but leaves CA posture
        // invisible — the exact failure this shaper fixes. Row keys are schema
        // names and safe to log; row values are untrusted tenant data, never logged.
        $shapeFields = array_flip(array_merge(self::CA_POLICY_ENUM_FIELDS, self::CA_POLICY_NAME_FIELDS, self::CA_POLICY_NAME_LIST_FIELDS));
        $sawShapeField = false;
        foreach ($rows as $row) {
            if (array_intersect_key($row, $shapeFields) !== []) {
                $sawShapeField = true;
                break;
            }
        }
        if ($rows !== [] && ! $sawShapeField) {
            Log::warning('[CippTools] No ListConditionalAccessPolicies row carries any flattened targeting/control field — shape drift, CA posture would be invisible', [
                'tool' => $toolName,
                'row_count' => count($rows),
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);
        }

        $result = [
            'count' => count($policies),
            'total_returned_by_cipp' => count($rows),
            'note' => 'Session controls (sign-in frequency, persistent browser, app-enforced restrictions) are not included — CIPP does not surface them in this list.',
            'policies' => $policies,
        ];

        if ($policies === []) {
            $result['warning'] = 'CIPP returns an empty result BOTH when the tenant has no Conditional Access policies AND when the upstream Graph query fails (e.g. missing permissions) — failures still come back as HTTP 200 with empty Results. Treat this as "no data", not as proof that the tenant has no CA policies.';
        }

        return $result;
    }

    /**
     * Split one of CIPP's Out-String fields (newline-joined display names with
     * a trailing newline) into a bounded list of fenced names. Truncation is
     * explicit — silently dropping excludeUsers entries would recreate the
     * blind spot this shaper exists to fix.
     *
     * @return array<int, mixed>
     */
    private function caNameList(string $toolName, string $field, mixed $value): array
    {
        if (is_array($value)) {
            return $this->boundArray($toolName, $field, $value);
        }

        if (! is_scalar($value)) {
            return [];
        }

        $names = preg_split('/\R/', (string) $value) ?: [];
        $names = array_values(array_filter(array_map('trim', $names), fn (string $name): bool => $name !== ''));

        $shown = array_map(
            fn (string $name): string => $this->textSanitizer->sanitize($this->fieldLabel($toolName, $field), $name, 1000),
            array_slice($names, 0, 20),
        );

        if (count($names) > 20) {
            $shown[] = '(+'.(count($names) - 20).' more not shown)';
        }

        return $shown;
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
    private function shapeMessageTrace(array $messages, array $input, int $totalReturned): array
    {
        $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
        $days = self::boundedDays($input['days'] ?? null, 2, 10);

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
        $userId = self::requiredUserId($input);
        if ($userId === null) {
            return ['error' => 'user_id is required'];
        }

        $objectId = self::resolveUserId($userId, $clientId);
        $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

        foreach ($rows as $row) {
            $rowUpn = mb_strtolower((string) ($row['UPN'] ?? $row['userPrincipalName'] ?? ''));
            $rowId = (string) ($row['ID'] ?? $row['Id'] ?? $row['userId'] ?? '');
            if ($rowId === $objectId || ($upnNeedle && $rowUpn === $upnNeedle)) {
                return self::summarizeMfaRow($this->projectMfaRow($row));
            }
        }

        return [
            'error' => "No MFA record found for {$userId} in this tenant",
            'searched_user_id' => $userId,
            'resolved_object_id' => $objectId,
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
            Log::warning('[CippTools] Every row projected empty — DEFAULT_FIELDS out of sync with CIPP response shape', [
                'tool' => $toolName,
                'row_count' => count($rows),
                'first_row_keys' => array_slice(array_keys($rows[0]), 0, 12),
            ]);

            return;
        }

        // A PARTIAL drop is the invisible failure this guard exists for
        // (psa-7lgo): the row still projects id/displayName/UPN, so the
        // all-empty check above stays quiet while an individual field vanishes
        // because CIPP cases its key differently. Some tools still hedge by
        // declaring BOTH casings of a field as separate DEFAULT_FIELDS entries
        // (MessageTraceId *and* messageTraceId, Identity *and* identity). Exactly
        // one of those can ever resolve, so a healthy row would report its twin
        // as drift and the guard would cry wolf on every call — and a noisy guard
        // gets ignored. If a case-insensitive twin resolved, the concept IS
        // present and this is a hedge, not drift.
        $resolvedInsensitively = array_map(
            fn (string $field): string => mb_strtolower($field),
            array_keys(array_filter($keyResolved))
        );

        $missing = array_values(array_filter(
            array_keys(array_filter($keyResolved, fn (bool $resolved): bool => ! $resolved)),
            fn (string $field): bool => ! in_array(mb_strtolower($field), $resolvedInsensitively, true)
        ));

        if ($missing === []) {
            return;
        }

        Log::warning('[CippTools] Field(s) never resolved in any row — DEFAULT_FIELDS/FIELD_ALIASES out of sync with CIPP response shape', [
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
     * Compact the known sign-in sub-objects to their useful keys, then hand the
     * value to the shared fence. The compaction is a projection choice specific to
     * this tool; the fencing is identical on both transports.
     */
    private function sanitizeProjectedValue(string $toolName, string $field, mixed $value): mixed
    {
        if (is_array($value) && $toolName === 'cipp_list_sign_ins' && isset(self::SIGN_IN_NESTED_FIELDS[$field])) {
            $value = $this->compactArray($value, self::SIGN_IN_NESTED_FIELDS[$field]);
        }

        return $this->fence($toolName, $field, $value);
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
