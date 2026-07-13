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
        $resolved = $userId !== null ? self::resolveUserId($userId, $clientId) : null;
        $upnNeedle = ($userId !== null && str_contains($userId, '@')) ? mb_strtolower($userId) : null;

        $events = [];
        foreach ($rows as $index => $row) {
            if ($cutoff !== null && ! $this->auditEventWithinCutoff($row, $cutoff)) {
                continue;
            }

            if ($resolved !== null && ! $this->auditRowMatchesUser($row, $resolved, $upnNeedle)) {
                continue;
            }

            $events[] = $projected[$index];
        }

        return [
            'count' => count($events),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];
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
            $raw = $this->auditRawData($row);

            foreach (self::AUDIT_USER_KEYS as $key) {
                if (is_string($raw[$key] ?? null) && $raw[$key] !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function auditRowMatchesUser(array $event, string $resolved, ?string $upnNeedle): bool
    {
        $raw = $this->auditRawData($event);
        $needle = mb_strtolower($resolved);

        foreach (self::AUDIT_USER_KEYS as $key) {
            $value = $raw[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $value = mb_strtolower($value);
            if ($value === $needle || ($upnNeedle !== null && $value === $upnNeedle)) {
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
