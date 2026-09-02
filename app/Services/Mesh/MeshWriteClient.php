<?php

namespace App\Services\Mesh;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * The Mesh Email Security WRITE lane — deliberately a separate class from the
 * GET-only MeshClient, for one capability: creating and reaping a CUSTOMER-
 * SCOPED allow rule (#1018).
 *
 * Every fact encoded here was MEASURED against the production Partner Hub on
 * 2026-09-01 under Charlie's single-create authorisation (rule
 * dafddde3-…, created 21:46:22Z, deleted 21:59:09Z, tenant returned to its
 * pre-test baseline). The vendor's published OpenAPI document does NOT
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

    /** Page size for the list read. The route paginates on _from/_size. */
    public const LIST_PAGE_SIZE = 200;

    /**
     * Hard ceiling on pages walked in one scoped read. The partner-wide list
     * was 393 rows when measured; 50 pages x 200 is two orders of magnitude of
     * headroom. A ceiling exists at all because `customer_id` is IGNORED as a
     * filter on this route (measured: passing it returns other customers'
     * rules), so a scoped read has no choice but to page the whole list — and
     * an unbounded loop against a list we do not control is a hang inside a
     * synchronous approval request.
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

    private Client $http;

    /**
     * @param  array{api_key?: string|null, base_url?: string|null}  $config
     * @param  Client|null  $http  Injectable transport (test seam).
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? 'https://hub-us.emailsecurity.app', '/').'/',
            'timeout' => 30,
        ]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
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
    public function createAllowRule(string $customerId, string $sender, string $comment, ?string $dateExpiry): array
    {
        $this->assertConfigured();

        if (trim($customerId) === '') {
            throw new MeshClientException('Mesh customer id is required; nothing was sent.');
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
     * @return array<int, array<string, mixed>>
     */
    public function listCustomerRules(string $customerId): array
    {
        $this->assertConfigured();

        if (trim($customerId) === '') {
            throw new MeshClientException('Mesh customer id is required; no list read was made.');
        }

        $matching = [];
        $from = 0;

        for ($page = 0; $page < self::LIST_PAGE_CEILING; $page++) {
            $response = $this->request('GET', self::RULE_ENDPOINT, [
                'query' => ['_from' => $from, '_size' => self::LIST_PAGE_SIZE],
            ]);

            $results = $response['results'] ?? null;
            if (! is_array($results) || $results === []) {
                break;
            }

            foreach ($results as $row) {
                if (is_array($row) && self::rowBelongsTo($row, $customerId)) {
                    $matching[] = $row;
                }
            }

            if (count($results) < self::LIST_PAGE_SIZE) {
                break;
            }

            $from += self::LIST_PAGE_SIZE;
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
    public function findRuleByComment(string $customerId, string $sender, string $comment): ?array
    {
        foreach ($this->listCustomerRules($customerId) as $row) {
            $rowComment = is_scalar($row['comment'] ?? null) ? trim((string) $row['comment']) : '';
            $rowSender = is_scalar($row['sender'] ?? null) ? trim((string) $row['sender']) : '';

            if (strcasecmp($rowComment, $comment) === 0 && strcasecmp($rowSender, $sender) === 0) {
                return $row;
            }
        }

        return null;
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
    public function findRuleById(string $customerId, string $ruleId): ?array
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
     * @var array<int, string>
     */
    private const PATCHABLE_FIELDS = ['date_expiry', 'comment'];

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
     * @throws MeshWriteRejectedException upstream 400, carrying the vendor's own text
     */
    public function patchRule(string $ruleId, array $fields): array
    {
        $this->assertConfigured();

        if (trim($ruleId) === '') {
            throw new MeshClientException('Mesh rule id is required; nothing was sent.');
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
                .' are); nothing was sent.'
            );
        }

        if ($fields === []) {
            throw new MeshClientException('Mesh rule update needs at least one field; nothing was sent.');
        }

        return $this->request('PATCH', self::RULE_ENDPOINT.rawurlencode($ruleId).'/', ['json' => $fields]);
    }

    /**
     * DELETE one rule by id. Returns nothing useful — the 200 body is not
     * evidence, which is why the reaper follows it with ruleAbsent().
     *
     * @throws MeshClientException
     */
    public function deleteRule(string $ruleId): void
    {
        $this->assertConfigured();

        if (trim($ruleId) === '') {
            throw new MeshClientException('Mesh rule id is required; nothing was sent.');
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
    public function ruleAbsent(string $ruleId): ?bool
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

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new MeshClientException('Mesh API key is not configured; nothing was sent.');
        }
    }

    /**
     * Authenticated request. The API-KEY header is added here and is never
     * logged — the failure log below carries method, endpoint and message
     * only, and the endpoint never contains the key.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws MeshWriteRejectedException on 400, carrying the vendor's own body
     * @throws MeshClientException on anything else, with the HTTP status as the code
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = [
            'API-KEY' => $this->config['api_key'] ?? '',
            'Accept' => 'application/json',
        ];

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
                Log::error("[MeshWriteClient] {$method} {$endpoint} could not connect: {$e->getMessage()}");

                throw new MeshClientException("Mesh API unreachable: {$e->getMessage()}; nothing was sent.", 0);
            }

            if ($status === 400) {
                $vendorBody = json_decode((string) $httpResponse?->getBody(), true);
                $vendorBody = is_array($vendorBody) ? $vendorBody : [];

                Log::warning("[MeshWriteClient] {$method} {$endpoint} refused by Mesh (400)");

                throw new MeshWriteRejectedException(
                    self::refusalText($vendorBody) ?? 'Mesh refused the request (400) without a readable reason.',
                    $vendorBody,
                );
            }

            Log::error("[MeshWriteClient] {$method} {$endpoint} failed: {$e->getMessage()}");

            throw new MeshClientException("Mesh API error: {$e->getMessage()}", $status);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The vendor's refusal, flattened to one line.
     *
     * Both measured 400 shapes are covered: the sender validator answers
     * `{"detail":…,"errors":[…]}` and the comment validator answers a bare
     * field map `{"comment":["String invalid"]}`. Anything unrecognised
     * falls back to a compact JSON dump rather than being dropped — the whole
     * point of this passthrough is that the caller sees why Mesh said no
     * (#1018 criterion 9), and a shape we did not anticipate is exactly the
     * case where masking it would cost the most.
     *
     * @param  array<string, mixed>  $body
     */
    private static function refusalText(array $body): ?string
    {
        $parts = [];

        if (is_scalar($body['detail'] ?? null)) {
            $parts[] = trim((string) $body['detail']);
        }

        foreach ($body as $key => $value) {
            if ($key === 'detail') {
                continue;
            }

            $flat = is_array($value)
                ? implode('; ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v), $value))
                : (is_scalar($value) ? (string) $value : json_encode($value));

            if (trim($flat) !== '') {
                $parts[] = $key === 'errors' ? $flat : "{$key}: {$flat}";
            }
        }

        $text = trim(implode(' — ', array_filter($parts, static fn (string $p): bool => trim($p) !== '')));

        return $text !== '' ? mb_substr($text, 0, 500) : null;
    }
}
