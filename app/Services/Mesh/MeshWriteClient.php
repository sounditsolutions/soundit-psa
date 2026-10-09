<?php

namespace App\Services\Mesh;

use App\Support\MeshConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Log;

/**
 * The Mesh Email Security WRITE lane — deliberately a separate class from the
 * GET-only MeshClient, for one capability: creating and reaping a CUSTOMER-
 * SCOPED allow rule (#1018).
 *
 * Every fact encoded here was MEASURED against the production Partner Hub on
 * 2026-09-01 under Charlie's single-create authorisation (one rule, created
 * and then deleted, tenant returned to its pre-test baseline; #6219: its
 * vendor id and times are not recorded in source, G-9). The vendor's published OpenAPI document does NOT
 * describe this route at all — it is permission-filtered and, for the key we
 * hold, omits `/api/rule-allows-blocks/` entirely — so the schema is not an
 * available source of truth and the measurements are. Where the two differ,
 * the comment says which one is speaking.
 *
 * WHAT THIS CLIENT REFUSES BY CONSTRUCTION, not by allow-list:
 *  - `edge` is NEVER sent. It means "apply at connection level as well as
 *    content level", i.e. a wider bypass than the rule the operator approved.
 *  - `customers[]` is NEVER sent and `/api/global-allow-block-rules/` is
 *    NEVER bound. Those are the partner-wide (all tenants) forms.
 *  - `organization_level` is always sent false. See the normalisation note on
 *    createAllowRule(): the SERVER rewrites it, so the flag we sent proves
 *    nothing afterwards and is not what the caller asserts on.
 *  - `ab` is pinned to the ALLOW_RULE constant. There is no block lane here.
 * Callers pass a tenant id, a sender, a comment and an expiry (or null for a
 * permanent rule, which omits `date_expiry`); there is no code path by which
 * any other field reaches the HTTP body.
 *
 * CREDENTIAL: the same `API-KEY` the read client uses — measured reachable
 * for these routes with the existing production key, so this lane does NOT
 * take a separate credential the way HuntressWriteClient does. It carries a
 * HUMAN identity (`created_by` on every rule it writes resolves to the key
 * owner's mailbox, not a service account), which is why the executor states
 * the attributed identity in the approval text (#1018 criterion 6).
 */
class MeshWriteClient
{
    /**
     * The customer-scoped allow/block rule collection. NOT
     * `/api/global-allow-block-rules/` — that is the partner-wide route and
     * this client never binds it.
     */
    public const RULE_ENDPOINT = 'api/rule-allows-blocks/';

    /**
     * `ab` semantics are NOT documented; they were read off live data
     * (2026-09-01: the "Huntress SAT Phishing Server" allow entries carry
     * true, the "Block corporatefilingsusa.com" entries carry false). Named
     * here so no call site ever writes a bare boolean whose meaning has to be
     * remembered (#1018 criterion 10).
     */
    public const ALLOW_RULE = true;

    /**
     * Page size REQUESTED by the list read. The route paginates on _from/_size
     * and answers `{count, next, previous, results}`.
     *
     * 100 is the vendor's cap, not our choice (measured 2026-10-06 on the
     * production Partner Hub, read-only): asked for `_size=200`, the route
     * returned 100 rows, `count` 375 and a `next` link carrying
     * `_from=100&_size=100`. Requesting more is silently clamped, so this value
     * is never used to decide whether the walk is over — listCustomerRules()
     * reads that from the vendor's own `next` and `count`. (It used to stop on
     * "fewer rows than requested", which with a 200 request and a 100 cap ended
     * every walk after the first page.)
     */
    public const LIST_PAGE_SIZE = 100;

    /**
     * Hard ceiling on pages walked in one scoped read: 50 pages x 100 rows is
     * 5,000 rows. The partner-wide list measured 393 rows on 2026-09-01 and
     * 375 on 2026-10-06, so this is over an order of magnitude of headroom. A
     * ceiling exists at all because `customer_id` is IGNORED as a filter on
     * this route (measured: passing it returns other customers' rules), so a
     * scoped read has no choice but to page the whole list — and an unbounded
     * loop against a list we do not control is a hang inside a synchronous
     * approval request. Reaching it before the list ends is a FAILURE, never
     * a truncated answer: see listCustomerRules().
     */
    public const LIST_PAGE_CEILING = 50;

    /**
     * curl errnos decided BEFORE any request byte reaches Mesh: 5/6 proxy and
     * host resolution, 7 connection refused, 35/51/60 TLS handshake and
     * certificate verification. A failure carrying one of these is a
     * DETERMINATE "nothing was sent" and is reported as such, because the
     * create path's may-have-committed question keys on that phrase and a PSA
     * row for a rule that never existed is a phantom the reaper can never
     * retire.
     *
     * The EXCEPTION CLASS does not decide this — the errno does. Guzzle's
     * CurlFactory promotes only its own $connectionErrors set (28, 6, 7, 35,
     * 52) to ConnectException; 5, 51 and 60 arrive as a plain RequestException
     * with a NULL response. Keying on ConnectException alone would therefore
     * have covered half this list and let an expired or untrusted certificate
     * write the phantom row anyway. request() reads the errno out of the
     * handler context of either shape.
     *
     * CURLE_OPERATION_TIMEDOUT (28) and CURLE_GOT_NOTHING (52) are deliberately
     * NOT here: those can follow a request Mesh already committed, so they must
     * stay unknown and fail closed.
     *
     * @var array<int, int>
     */
    private const NEVER_SENT_CURL_ERRNOS = [5, 6, 7, 35, 51, 60];

    /** Null when PSR-7 rejected the base URL; request() then refuses (#5991). */
    private ?Client $http;

    /**
     * #5991: a base_url PSR-7 cannot parse makes new Client() throw a
     * MalformedUriException whose message is the raw URL (user-info
     * included). It is not thrown here: every call then fails in request()
     * with a status-only, client-detected MeshClientException. #6214,
     * #6221: $config holds the API key, so it is #[\SensitiveParameter]: a
     * throw from inside this constructor (a base_url that is not a string
     * makes rtrim() throw a TypeError, not caught here) shows a
     * SensitiveParameterValue in this frame (MeshTraceFrameHardeningTest).
     *
     * @param  array{api_key?: string|null, base_url?: string|null}  $config
     * @param  Client|null  $http  Injectable transport (test seam).
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $config,
        ?Client $http = null,
    ) {
        try {
            $this->http = $http ?? new Client([
                'base_uri' => rtrim($this->config['base_url'] ?? 'https://hub-us.emailsecurity.app', '/').'/',
                'timeout' => 30,
                // #6105: a redirect is never followed (request() also sets
                // it per request, so an injected transport cannot either).
                'allow_redirects' => false,
            ]);
        } catch (\InvalidArgumentException) {
            $this->http = null;
        }
    }

    /**
     * #6161, #6162: MeshConfig::isSendableKey(), the predicate
     * MeshConfig::isConfigured() uses: a scalar (#6208: an array, even [],
     * is not configured), not missing (null, false, '' or only spaces and
     * tabs) and not a stored value the PSA does not send ('0', 0, 0.0,
     * true). The header rule is preflight()'s. assertConfigured() words a refusal with
     * MeshClient::apiKeyRefusal(), which tells the two apart.
     */
    public function isConfigured(): bool
    {
        return MeshConfig::isSendableKey($this->config['api_key'] ?? null);
    }

    /**
     * Create ONE customer-scoped ALLOW rule and return the decoded 201 body.
     *
     * The 201 is `{"detail":"Allow/Block Rules added","added_for":["<uuid>"]}`
     * and carries NO rule id — recovering the id needs a re-read
     * (findRuleByComment()), which is why the caller must pass a comment it
     * can match on later.
     *
     * SERVER NORMALISATION, measured and load-bearing: the row this writes
     * PERSISTS as `organization_level: true`, `customer_id: null`,
     * `set_by_partner: true` — none of which we sent, and all 393 pre-existing
     * rules read the same way, so it is the route's normal representation and
     * not something our call did. The binding is nevertheless correct: the
     * POST body's `customer_id` is what drives `added_for`, and the nested
     * `customer.id` on the stored row is the intended tenant. Consequence for
     * every caller: assert scope on `added_for` from THIS response, never on a
     * read-back of `organization_level`/`customer_id` (#1018 criterion 1).
     *
     * @param  string  $customerId  Mesh tenant uuid (clients.mesh_customer_id).
     * @param  string  $sender  Sender address or sending domain to allow.
     * @param  string  $comment  Plain-text label. Mesh regex-validates this field
     *                           and rejects `#` and other punctuation; generate it,
     *                           never pass caller text through.
     * @param  string  $dateExpiry  ISO-8601 expiry. DISPLAY ONLY upstream — measured
     *                              2026-09-01: a rule whose date_expiry had passed was
     *                              still `active: true`, unmodified, 9m14s later. Sent
     *                              so the portal's "Expires" column agrees with the
     *                              PSA-enforced lifetime; it is NOT the control.
     * @return array<string, mixed>
     *
     * @throws MeshWriteRejectedException upstream 400 (sender or comment validation)
     * @throws MeshClientException credential missing, or any other upstream failure
     */
    /**
     * @param  string|null  $dateExpiry  The expiry to DISPLAY upstream, or null
     *                                   for a rule the PSA will never reap
     *                                   (#1133). Null omits `date_expiry` from
     *                                   the body entirely rather than sending
     *                                   an empty string or a sentinel date:
     *                                   the field is display-only (measured
     *                                   2026-09-01), and a portal showing no
     *                                   expiry for a rule that has none is the
     *                                   honest reading. It is NOT what makes
     *                                   the rule permanent — the absent
     *                                   mesh_allow_rules expiry is.
     */
    public function createAllowRule(#[\SensitiveParameter] string $customerId, #[\SensitiveParameter] string $sender, #[\SensitiveParameter] string $comment, ?string $dateExpiry): array
    {
        $this->assertConfigured();

        if (trim($customerId) === '') {
            throw new MeshClientException('Mesh customer id is required; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        // The body is assembled here in full and from these four arguments
        // only. No caller-supplied array is merged in, so `edge`,
        // `customers[]`, `ab: false` and every field the vendor adds later are
        // unreachable from any call site.
        $body = [
            'users' => [],
            'domains' => [],
            'active' => true,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => self::ALLOW_RULE,
            'customer_id' => $customerId,
            'organization_level' => false,
        ];

        if ($dateExpiry !== null) {
            $body['date_expiry'] = $dateExpiry;
        }

        return $this->request('POST', self::RULE_ENDPOINT, ['json' => $body]);
    }

    /**
     * Every rule on ONE tenant, already filtered — the raw partner-wide list
     * never leaves this method (#1018 criterion 7).
     *
     * `customer_id` is ignored as a query filter on this route (measured), so
     * scoping is done here, client-side, over the paged partner-wide list. The
     * return value is only the matching tenant's rows; no caller, no log line
     * and no error body ever receives the unfiltered list.
     *
     * COMPLETENESS is the other half of the contract, because every caller
     * reads "not in this list" as "not on this tenant". The walk continues
     * while the vendor says there is more — a non-empty `next` link, or fewer
     * rows seen than its `count` — and advances `_from` by the rows actually
     * returned, never by the size requested (the vendor clamps that; see
     * LIST_PAGE_SIZE). It ends on an empty page or when neither signal asks
     * for more. A walk that cannot be shown complete THROWS rather than
     * returning what it has: reaching LIST_PAGE_CEILING with the vendor still
     * asking for more, ending with fewer rows seen than `count`, or any page
     * whose body carries no `results` list (another envelope, or a body that
     * is not JSON, which request() decodes to an empty array).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MeshClientException credential missing, upstream failure, or a
     *                             list read that could not be completed
     */
    public function listCustomerRules(#[\SensitiveParameter] string $customerId): array
    {
        $this->assertConfigured();

        if (trim($customerId) === '') {
            throw new MeshClientException('Mesh customer id is required; no list read was made.', clientDetected: true, nothingSent: true);
        }

        $matching = [];
        $from = 0;
        $seen = 0;
        $count = null;
        $complete = false;

        for ($page = 0; $page < self::LIST_PAGE_CEILING; $page++) {
            $response = $this->request('GET', self::RULE_ENDPOINT, [
                'query' => ['_from' => $from, '_size' => self::LIST_PAGE_SIZE],
            ]);

            if (is_int($response['count'] ?? null)) {
                $count = $response['count'];
            }

            $results = $response['results'] ?? null;
            if (! is_array($results)) {
                throw new MeshClientException(
                    "Mesh rule list read got a page without a results list after {$seen} rows; the list was not used.",
                    clientDetected: true,
                );
            }

            if ($results === []) {
                $complete = true;
                break;
            }

            foreach ($results as $row) {
                if (is_array($row) && self::rowBelongsTo($row, $customerId)) {
                    $matching[] = $row;
                }
            }

            $seen += count($results);
            $from += count($results);

            $next = $response['next'] ?? null;
            $moreByNext = is_string($next) && trim($next) !== '';
            $moreByCount = $count !== null && $seen < $count;

            if (! $moreByNext && ! $moreByCount) {
                $complete = true;
                break;
            }
        }

        if (! $complete) {
            throw new MeshClientException(
                'Mesh rule list read reached its page ceiling ('.self::LIST_PAGE_CEILING.' pages, '.$seen
                .' rows) while Mesh still reported more rows; the incomplete list was not used.',
                clientDetected: true,
            );
        }

        if ($count !== null && $seen < $count) {
            throw new MeshClientException(
                "Mesh rule list read ended after {$seen} rows but Mesh reported {$count}; the incomplete list was not used.",
                clientDetected: true,
            );
        }

        return $matching;
    }

    /**
     * The tenant's rule carrying exactly this comment, or null.
     *
     * The comment is the PSA's own generated label and carries a random
     * reference token, so it identifies one rule; `sender` is checked as well
     * so a token collision cannot resolve to a rule for a different sender.
     * Both are compared case-insensitively — Mesh lower-cases sender values it
     * stores.
     *
     * @return array<string, mixed>|null
     */
    public function findRuleByComment(#[\SensitiveParameter] string $customerId, #[\SensitiveParameter] string $sender, #[\SensitiveParameter] string $comment): ?array
    {
        foreach ($this->listCustomerRules($customerId) as $row) {
            if (self::rowMatchesComment($row, $sender, $comment)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * EVERY rule on the tenant carrying this sender and comment, in list
     * order: the same match findRuleByComment() makes, without stopping at
     * the first one.
     *
     * For a caller that must refuse to guess. A comment carries a random
     * reference token, so more than one match should not happen, but if it
     * does, the first match is not evidence that it is the rule the row
     * recorded. The reaper's settle pass records an id only when exactly one
     * rule matches (MeshAllowRuleReaper::settleUnexpired()).
     *
     * Same read and same failure behaviour as findRuleByComment(): an
     * incomplete list read throws MeshClientException and never returns a
     * partial list.
     *
     * @return list<array<string, mixed>>
     */
    public function findRulesByComment(#[\SensitiveParameter] string $customerId, #[\SensitiveParameter] string $sender, #[\SensitiveParameter] string $comment): array
    {
        $matches = [];

        foreach ($this->listCustomerRules($customerId) as $row) {
            if (self::rowMatchesComment($row, $sender, $comment)) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * The identity both comment lookups resolve on: trimmed, case-insensitive
     * comment AND sender.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowMatchesComment(array $row, string $sender, string $comment): bool
    {
        $rowComment = is_scalar($row['comment'] ?? null) ? trim((string) $row['comment']) : '';
        $rowSender = is_scalar($row['sender'] ?? null) ? trim((string) $row['sender']) : '';

        return strcasecmp($rowComment, $comment) === 0 && strcasecmp($rowSender, $sender) === 0;
    }

    /**
     * ONE rule on ONE tenant, by upstream rule id — or null if this tenant has
     * no such rule (#1134).
     *
     * The scope check is the WHOLE point of this method, and it is why the
     * remove verb does not simply GET `RULE_ENDPOINT/{id}/`. That detail read
     * is not tenant-scoped: it answers for any rule id the key can see, across
     * every tenant in the partnership, so a caller who mistyped an id — or who
     * pasted one from another customer's ticket — would get a readable row and
     * a successful delete on somebody else's mail filtering. Resolving through
     * listCustomerRules() means the id can only ever match a row already
     * proved to belong to $customerId; a foreign id is simply absent.
     *
     * The cost is a paged partner-wide read per lookup (LIST_PAGE_SIZE x up to
     * LIST_PAGE_CEILING), which is the same cost findRuleByComment() already
     * pays and is unavoidable while `customer_id` is ignored as a query filter
     * on this route (measured 2026-09-01).
     *
     * Comparison is a trimmed string compare, NOT case-insensitive: rule ids
     * are uuids the vendor generates and echoes back verbatim, and loosening
     * the match here would be widening an identity check on a delete lane for
     * no measured need.
     *
     * @return array<string, mixed>|null
     */
    public function findRuleById(#[\SensitiveParameter] string $customerId, #[\SensitiveParameter] string $ruleId): ?array
    {
        $ruleId = trim($ruleId);
        if ($ruleId === '') {
            return null;
        }

        foreach ($this->listCustomerRules($customerId) as $row) {
            $rowId = is_scalar($row['id'] ?? null) ? trim((string) $row['id']) : '';

            if ($rowId !== '' && $rowId === $ruleId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The ONLY fields this client will ever send in an update body (#1135).
     *
     * The detail route's own `actions.PUT` schema (measured 2026-09-02 via
     * `OPTIONS api/rule-allows-blocks/{id}/`, which answered
     * `Allow: GET, PUT, PATCH, DELETE, HEAD, OPTIONS`) advertises `ab`,
     * `organization_level`, `customer_id`, `partner_id`, `global_id`, `edge`
     * and `active` as writable as well. Every one of those either widens the
     * rule's scope, moves it to another tenant, or converts it to the
     * partner-wide lane — i.e. each is a change the approver of an "edit the
     * expiry" card did not agree to. They are refused HERE, in the client,
     * rather than only in the executor, so that a future call site cannot
     * reach them by constructing its own field array (defence in depth,
     * #1135 build-shape item 2).
     *
     * `sender` is absent deliberately and is not an oversight: changing the
     * sender IS a scope change, and the product ruling is that it happens as
     * remove (#1134) + add (#1018), never under a verb named "edit". Its
     * absence is also why this lane is PATCH and never PUT — the schema marks
     * `sender` required on PUT, so a PUT would have to reintroduce exactly the
     * field the verb refuses.
     *
     * `comment` is absent too, and for a different reason: it is not a label
     * but the reaper's fallback IDENTITY for a rule. When a row's
     * `mesh_rule_id` is missing or stale, MeshAllowRuleReaper::resolveRuleId()
     * recovers it through findRuleByComment(); an edited comment would leave a
     * live allow rule that the only queue able to close it can no longer find.
     *
     * @var array<int, string>
     */
    private const PATCHABLE_FIELDS = ['date_expiry'];

    /**
     * Update ONE rule in place by id, with a body restricted to
     * PATCHABLE_FIELDS, and return the decoded response body.
     *
     * SCOPE IS NOT PROVED HERE. The detail route answers for any rule id the
     * key can see across the whole partnership — the same property documented
     * on findRuleById() — so this method is unsafe to call on a caller-supplied
     * id that has not already been resolved through listCustomerRules(). The
     * executor resolves it that way at staging AND again at approval; this
     * method deliberately does not re-implement that check, because a scope
     * proof made from an unscoped read would be a false one.
     *
     * `date_expiry` semantics differ from createAllowRule() and the difference
     * is load-bearing: on the create path a null expiry OMITS the field, but
     * on a partial update an omitted field means "leave unchanged", so
     * clearing an expiry requires sending an explicit null. Passing
     * `['date_expiry' => null]` therefore sends `{"date_expiry": null}` and is
     * the only way to express "make this rule permanent" upstream. UNMEASURED
     * as of 2026-09-02: whether Mesh accepts a null there, or answers 400. The
     * caller must treat a MeshWriteRejectedException on that body as the
     * vendor declining to clear the display expiry, NOT as the PSA-side
     * lifetime failing to change — the PSA row is what actually governs
     * reaping (measured 2026-09-01: date_expiry is display-only upstream).
     *
     * Also unmeasured, and the reason every caller must re-read afterwards:
     * whether a PATCH preserves the rule id. The `OPTIONS` schema marks `id`
     * read-only, which is the API asserting it, not us observing it. Callers
     * assert on a scoped re-read, never on this return value.
     *
     * @param  array<string, mixed>  $fields  Subset of PATCHABLE_FIELDS. May carry
     *                                        a null `date_expiry`; may not be empty.
     * @return array<string, mixed>
     *
     * @throws MeshClientException credential missing, empty or unknown field set,
     *                             or any non-400 upstream failure
     * @throws MeshWriteRejectedException upstream 400 (status-only message, #6106)
     */
    public function patchRule(#[\SensitiveParameter] string $ruleId, #[\SensitiveParameter] array $fields): array
    {
        $this->assertConfigured();

        if (trim($ruleId) === '') {
            throw new MeshClientException('Mesh rule id is required; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        // Refuse the whole call on ANY unknown key rather than filtering it
        // out silently. A silent filter would let a call site believe it had
        // changed `active` or `ab` and read a success back; a refusal is the
        // only outcome that cannot be mistaken for the write the caller asked
        // for. Same reasoning as refusing the whole body on the Huntress
        // resolution lane rather than allow-listing params one at a time.
        $unknown = array_values(array_diff(array_keys($fields), self::PATCHABLE_FIELDS));
        if ($unknown !== []) {
            throw new MeshClientException(
                'Mesh rule update refused: field(s) '.implode(', ', $unknown)
                .' are not updatable by this client (only '.implode(', ', self::PATCHABLE_FIELDS)
                .' are); nothing was sent.',
                clientDetected: true,
                nothingSent: true,
            );
        }

        if ($fields === []) {
            throw new MeshClientException('Mesh rule update needs at least one field; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        return $this->request('PATCH', self::RULE_ENDPOINT.rawurlencode($ruleId).'/', ['json' => $fields]);
    }

    /**
     * DELETE one rule by id. Returns nothing useful — the 200 body is not
     * evidence, which is why the reaper follows it with ruleAbsent().
     *
     * @throws MeshClientException
     */
    public function deleteRule(#[\SensitiveParameter] string $ruleId): void
    {
        $this->assertConfigured();

        if (trim($ruleId) === '') {
            throw new MeshClientException('Mesh rule id is required; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        $this->request('DELETE', self::RULE_ENDPOINT.rawurlencode($ruleId).'/');
    }

    /**
     * The reap post-condition: GET the rule detail and require a 404.
     *
     * true  = proved absent (404).
     * false = still readable — the delete did not take.
     * null  = could not be measured (transport failure, any other status),
     *         which is NOT a pass. An unmeasurable post-condition fails
     *         closed: the caller must not mark a row reaped on null.
     */
    public function ruleAbsent(#[\SensitiveParameter] string $ruleId): ?bool
    {
        if (! $this->isConfigured() || trim($ruleId) === '') {
            return null;
        }

        try {
            $this->request('GET', self::RULE_ENDPOINT.rawurlencode($ruleId).'/');
        } catch (MeshWriteRejectedException) {
            return null;
        } catch (MeshClientException $e) {
            return $e->getCode() === 404 ? true : null;
        }

        return false;
    }

    /**
     * Does this list row belong to the given tenant?
     *
     * Checks the nested `customer.id` (the representation measured on live
     * rows) and, defensively, a flat `customer_id` — but ONLY when it is a
     * non-empty string. The stored `customer_id` is normally null on this
     * route, and a null-to-null comparison would match a row to a caller who
     * passed an empty tenant id. The empty-tenant case is refused before we
     * get here; this keeps the predicate honest anyway.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowBelongsTo(array $row, string $customerId): bool
    {
        $nested = $row['customer']['id'] ?? null;
        if (is_scalar($nested) && (string) $nested !== '' && (string) $nested === $customerId) {
            return true;
        }

        $flat = $row['customer_id'] ?? null;

        return is_scalar($flat) && (string) $flat !== '' && (string) $flat === $customerId;
    }

    /**
     * The public methods' first refusal. #6161: worded by
     * MeshClient::apiKeyRefusal(), so only a key that is really absent is
     * called 'not configured'; a stored '0', 0 or true says a value is
     * set. #6160: so preflight()'s key arm (MeshClient::apiKeyRefusal(),
     * one arm for missing, unusable and the header rule) is reached
     * through the public methods only for a key this check passes, that
     * is for the header rule (ruleAbsent() returns null first). It stays
     * as the guard for a method that skips this call. #6206:
     * MeshKeyDefinitionTest drives preflight() directly with a missing key
     * and with each unusable key ('0', ' 0', 0, 0.0, true), and asserts
     * each refusal's text and that nothing reached the handler.
     */
    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            $refusal = MeshClient::apiKeyRefusal($this->config['api_key'] ?? null);
            throw new MeshClientException("Mesh API key {$refusal}; nothing was sent.", clientDetected: true, nothingSent: true);
        }
    }

    /**
     * Authenticated request. The API-KEY header is added here and is never
     * logged. A failure is logged by method, endpoint path (no query), HTTP
     * status and exception class only: Guzzle's message quotes the request
     * URI, the host and a summary of the vendor's response body (C-56), so it
     * goes into neither the log line nor anything a caller reports — the
     * thrown exception is upstream by construction (MeshClientException) and
     * callers report it through statusPhrase(). The Guzzle exception is not
     * chained (#5978): the console renderer and (string) $e, which a queue
     * worker stores in failed_jobs, print every previous message. Before
     * Guzzle is called, a base URL or endpoint PSR-7 cannot parse and an API
     * key an HTTP header cannot carry are refused with nothingSent (#5984,
     * #5985, #5991); PSR-7's own refusals quote the URI or the key.
     * #6154: $options holds the API-KEY header (and a write's json body or
     * a read's query) once set, so it is #[\SensitiveParameter] like
     * $endpoint: with zend.exception_ignore_args Off this frame shows a
     * SensitiveParameterValue for both (MeshTraceArgumentRedactionTest). A
     * status-less failure off the never-sent arm names the cURL errno when
     * the handler recorded one (#6050: the number only), so a timeout (28),
     * an empty reply (52) and a receive failure (56) read differently.
     * #6214: any other throwable from the HTTP client (a RuntimeException,
     * TypeError or Error from a handler or middleware) is not caught and
     * leaves this method as thrown; this frame shows $options as a
     * SensitiveParameterValue (MeshTraceFrameHardeningTest), but Guzzle's
     * own frames below it are not marked and, read in the vendored Guzzle,
     * not driven, take the options array with the API-KEY header and the
     * json body.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws MeshWriteRejectedException on 400, with a status-only message (#6106)
     * @throws MeshClientException on anything else, with the HTTP status as the code
     */
    private function request(string $method, #[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $options = []): array
    {
        $this->preflight($method, $endpoint);

        $options['headers'] = [
            'API-KEY' => $this->config['api_key'],
            'Accept' => 'application/json',
        ];
        // #6105: never follow a redirect. RedirectMiddleware would carry the
        // API-KEY header to whatever host Location names, re-send a 307/308
        // body there, and turn a 301/302/303 write into a GET whose answer
        // would be read as this write's. Per request, so a transport built
        // elsewhere (the test seam) cannot follow one either. With no hop
        // after the first, the never-sent errno arm below can only describe
        // the one request this method made.
        $options['allow_redirects'] = false;

        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            $httpResponse = $e instanceof RequestException ? $e->getResponse() : null;
            $status = $httpResponse?->getStatusCode() ?? 0;

            // A connect-PHASE failure never put the request on the wire, so it
            // cannot be sitting on top of a committed rule — and saying so is
            // load-bearing: otherwise it arrives at the caller as bare status 0,
            // the same code a mid-flight timeout carries, and is reconciled into
            // an UNRESOLVED mesh_allow_rules row for a rule that does not exist.
            //
            // The test is the ERRNO, not the exception class. Guzzle promotes
            // only its own $connectionErrors set (28, 6, 7, 35, 52) to
            // ConnectException and wraps every other errno — including proxy
            // resolution (5) and certificate verification (51, 60) — in a plain
            // RequestException whose getResponse() is null. Both shapes carry
            // the errno in the handler context, so both are read here; the null
            // response is what keeps a server that actually ANSWERED out.
            // Only the errnos measured to be decided before the request bytes
            // are sent qualify (see NEVER_SENT_CURL_ERRNOS); everything else
            // stays unknown and fails closed.
            $neverSentErrno = $e instanceof ConnectException || $e instanceof RequestException
                ? (int) ($e->getHandlerContext()['errno'] ?? 0)
                : 0;

            if ($httpResponse === null
                && in_array($neverSentErrno, self::NEVER_SENT_CURL_ERRNOS, true)) {
                // #5982: the set holds name-resolution (5, 6), connect (7)
                // and TLS (35, 51, 60) errnos, so the text names all three
                // stages, not 'could not connect'; the errno says which.
                $failure = "{$method} ".self::logPath($endpoint).' failed at the resolve, connect or TLS stage (cURL errno '.$neverSentErrno.', '.$e::class.'); nothing was sent';
                Log::error("[MeshWriteClient] {$failure}");

                // Upstream by construction (#5271): the client-detected flag
                // is NOT set, so statusPhrase() never returns this message.
                // nothingSent is what callers branch on. #5884: built like the
                // log line, never from Guzzle's message; #5978: not chained.
                throw new MeshClientException("Mesh API unreachable: {$failure}.", 0, nothingSent: true);
            }

            if ($status === 400) {
                $vendorBody = json_decode((string) $httpResponse?->getBody(), true);
                $vendorBody = is_array($vendorBody) ? $vendorBody : [];

                Log::warning("[MeshWriteClient] {$method} ".self::logPath($endpoint).' refused by Mesh (400)');

                // #6106: no vendor text in the message (C-56): the status,
                // and only the names of fields this client sends that the
                // answer was keyed on (refusalFields()).
                $fields = self::refusalFields($vendorBody);

                throw new MeshWriteRejectedException(
                    'Mesh refused the request (HTTP 400)'
                    .($fields !== [] ? '; its answer named the field(s) '.implode(', ', $fields) : '').'.',
                );
            }

            // #5884: status and logPath() only; Guzzle's message quotes the
            // request URI and a vendor body summary. #5978: not chained, for
            // the same reason. #6050: with no status, the errno as a number.
            $failure = "{$method} ".self::logPath($endpoint).' failed with '
                .($status > 0 ? "HTTP {$status}" : 'no HTTP status').' ('.MeshClient::errnoPrefix($e, $status).$e::class.')';
            Log::error("[MeshWriteClient] {$failure}");

            throw new MeshClientException("Mesh API error: {$failure}", $status);
        } catch (\InvalidArgumentException $e) {
            // #5985: preflight() took the endpoint and the key, so this is
            // something else the HTTP client threw. #6061 measured one such
            // route after a send: RedirectMiddleware parsing an unparseable
            // Location. #6105 turned redirects off, so that route now ends
            // in the 3xx arm below with its status (#6165;
            // MeshRequestPreflightTest's 3xx row asserts that arm, not this
            // one). Whether any other route can follow a send is not
            // measured; MeshAllowRuleConsumerRefusalTest drives this arm
            // with a handler-thrown exception. Its message may quote a URI
            // or a header value: class only, unchained, and no claim about
            // what was sent. nothingSent stays unset, so a create stays
            // may-have-committed and fails closed.
            $failure = "{$method} ".self::logPath($endpoint).' failed in the HTTP client with no Mesh status recorded ('.$e::class.')';
            Log::error("[MeshWriteClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}", noStatusRecorded: true);
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            // #6105: a 3xx, not followed. The configured host (Mesh or
            // something in front of it) answered, so for a write the
            // request may have been acted on: never nothingSent. The
            // status is the code, so the executor's
            // createMayHaveCommitted() reconciles a 3xx on a create, and
            // ruleAbsent() reads it as unmeasured, never as absent. The
            // Location is neither followed, logged nor quoted. #6158:
            // '(redirect not followed)' only for a redirect status that
            // carries a non-empty Location (MeshClient::redirectNote()).
            $failure = "{$method} ".self::logPath($endpoint)." failed with HTTP {$status}".MeshClient::redirectNote($response);
            Log::error("[MeshWriteClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}", $status);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Refusals decided before Guzzle is called, so nothing was sent (#5984,
     * #5985, #5991). The endpoint is parsed FIRST and its refusal logs the
     * fixed '[unparseable endpoint]' (#6048); the base-URL and key arms log
     * logPath(), which logs only the rule collection's own path shape. All
     * three are client-detected (#6060). None quotes the base URL, the
     * endpoint or the key.
     */
    private function preflight(string $method, #[\SensitiveParameter] string $endpoint): void
    {
        try {
            // The same parse Guzzle's Utils::uriFor() does first; its
            // message is the raw endpoint, so it is neither logged nor chained.
            // Every public method builds its endpoint from a constant and a
            // rawurlencode()d id, so this is a guard, not a measured path.
            new Uri($endpoint);
        } catch (\InvalidArgumentException $e) {
            Log::error("[MeshWriteClient] {$method} ".MeshClient::UNPARSEABLE_ENDPOINT.' refused: the endpoint could not be parsed ('.$e::class.'); nothing was sent');
            throw new MeshClientException('The Mesh request endpoint could not be parsed; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        if ($this->http === null) {
            Log::error("[MeshWriteClient] {$method} ".self::logPath($endpoint).' refused: the Mesh base URL could not be parsed; nothing was sent');
            throw new MeshClientException('The Mesh base URL could not be parsed; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        $keyRefusal = MeshClient::apiKeyRefusal($this->config['api_key'] ?? null);
        if ($keyRefusal !== null) {
            Log::error("[MeshWriteClient] {$method} ".self::logPath($endpoint)." refused: the Mesh API key {$keyRefusal}; nothing was sent");
            throw new MeshClientException("The Mesh API key {$keyRefusal}; nothing was sent.", clientDetected: true, nothingSent: true);
        }
    }

    /**
     * #6157, #6218: what logPath() logs for an endpoint that is not written
     * as the rule route (RULE_ENDPOINT, relative, as every public method
     * builds it). It says how the endpoint is written, not where it
     * resolves: '/api/rule-allows-blocks/x/' or an absolute URL may resolve
     * onto the rule route and still get this label. It is not
     * MeshClient::UNPARSEABLE_ENDPOINT (#6217): every call site of
     * logPath() is in preflight() after its endpoint parse (the base-URL
     * and key refusals, where nothing was sent) or in request() after
     * preflight() (the never-sent errno arm and the arms after a send), and
     * an unparseable endpoint is refused by that parse and logged as
     * '[unparseable endpoint]'. logPath() itself also returns
     * '[unparseable endpoint]' for an endpoint PSR-7 cannot parse, so that
     * label does not depend on the call order (#6217).
     */
    public const OTHER_ENDPOINT = '[endpoint not written as the rule route]';

    /**
     * The endpoint as a log may carry it (#6048, #6107, #6118). The query
     * and fragment are cut first. Then an allowlist, not a search for bad
     * bytes: the rule collection path RULE_ENDPOINT is kept as it is; a
     * path under it (a rule id, encoded or not, and anything after it) is
     * logged as RULE_ENDPOINT.'<rule>/', as MeshClient::logPath() logs a
     * customer id as <customer>; anything else (a host, a scheme, user-info,
     * another route) is the fixed OTHER_ENDPOINT (#6157). So a vendor rule
     * id never reaches a log line, and neither does a host, whatever bytes
     * encode it. #6217: an endpoint PSR-7 cannot parse is the fixed
     * MeshClient::UNPARSEABLE_ENDPOINT here too, whoever calls this.
     */
    private static function logPath(string $endpoint): string
    {
        try {
            new Uri($endpoint);
        } catch (\InvalidArgumentException) {
            return MeshClient::UNPARSEABLE_ENDPOINT;
        }

        $path = substr($endpoint, 0, strcspn($endpoint, '?#'));

        if ($path === self::RULE_ENDPOINT) {
            return $path;
        }

        return str_starts_with($path, self::RULE_ENDPOINT)
            ? self::RULE_ENDPOINT.'<rule>/'
            : self::OTHER_ENDPOINT;
    }

    /**
     * The request fields this client sends (createAllowRule() and
     * PATCHABLE_FIELDS). Only these may be named from a 400 answer.
     *
     * @var array<int, string>
     */
    private const SENT_FIELDS = ['users', 'domains', 'active', 'sender', 'comment', 'ab', 'customer_id', 'organization_level', 'date_expiry'];

    /**
     * #6106: the top-level keys of a 400 answer that name a field this
     * client sends, in SENT_FIELDS order. Never a value: the measured
     * sender refusal echoes the sender mailbox in its text, and Mesh's
     * answer is vendor text (C-56). A key outside SENT_FIELDS is dropped.
     *
     * @param  array<int|string, mixed>  $body
     * @return list<string>
     */
    private static function refusalFields(array $body): array
    {
        $keys = array_map('strval', array_keys($body));

        return array_values(array_filter(self::SENT_FIELDS, static fn (string $f): bool => in_array($f, $keys, true)));
    }
}
