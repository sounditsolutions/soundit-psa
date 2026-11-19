<?php

namespace App\Services\Huntress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * The Huntress WRITE lane — deliberately a separate class from the GET-only
 * HuntressClient. It is built from HuntressConfig::writeCredentials()
 * (`api_key` / `api_secret`): the same pair the reads use, or an earlier
 * user pair while both of its halves are still stored. A user API key carries
 * its Huntress user's permissions, so Huntress itself refuses the resolve when
 * that user may not resolve escalations. Without a pair it sends nothing.
 *
 * Unwanted access rules: create, update and delete as named methods with fixed
 * paths and an allow-listed body (RULE_CREATE_KEYS / RULE_UPDATE_KEYS); a key
 * outside the list refuses before any HTTP. The org fence lives in
 * StaffHuntressRuleToolExecutor.
 *
 * Escalations: POST /v1/escalations/{id}/resolution with the LITERAL empty JSON
 * object `{}`. The parameterised body (`determination`, `scope`,
 * `revoke_and_disable_identities` — boolean default TRUE, `expiration_date`)
 * can revoke sessions, disable identities, and create account-wide access
 * rules; this client refuses the whole body by construction — there is no
 * code path by which caller input reaches the HTTP body. The endpoint's body
 * parameter is required:true in the spec, so the empty object is what an
 * id-only call ships.
 */
class HuntressWriteClient
{
    /**
     * The exact bytes this client sends as the resolution body. Public so the
     * executor's docs/tests and the client's own tests pin the same literal.
     */
    public const EMPTY_RESOLUTION_BODY = '{}';

    /**
     * Hard ceiling on any single 429 back-off sleep. Retry-After is an
     * UPSTREAM-CONTROLLED value and this client runs inside the synchronous
     * cockpit approve request — an unclamped `Retry-After: 3600` would park
     * the PHP worker (and the run's execution claim) for an hour. Two retries
     * at the ceiling bound the added wall time at 20 s.
     */
    public const RETRY_AFTER_CEILING_SECONDS = 10;

    /**
     * MIRROR of StaffHuntressActionToolExecutor::SAFE_RESOLUTION_METHODS (which
     * is private to the executor): the resolution methods the executor's
     * post-condition can clear. Anything outside this set — `rule` above all —
     * means server-side state WAS created and must reach the executor to fault
     * on, whichever position in the body reported it.
     */
    private const SAFE_RESOLUTION_METHODS = ['direct', 'dismiss'];

    private Client $http;

    /**
     * @param  Client|null  $http  Injectable transport (test seam). When null the
     *                             default Basic-auth Guzzle client is built from
     *                             the `api_key` / `api_secret` config pair.
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.huntress.io/v1/',
            'timeout' => 30,
            'auth' => [
                $this->config['api_key'] ?? '',
                $this->config['api_secret'] ?? '',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key'])
            && ! empty($this->config['api_secret']);
    }

    /**
     * Resolve ONE escalation by id, sending the literal `{}` body.
     *
     * Returns the decoded 201 response — the `EscalationResolution` object,
     * defensively unwrapped — whose required `resolution_method` field the
     * CALLER must assert on ({direct, dismiss} pass; `rule` means attribute
     * rules WERE created and is a fault). The post-condition lives in the
     * executor so the audit and the operator-facing fault are written where
     * the approval context is.
     *
     * @return array<string, mixed>
     *
     * @throws HuntressWriteScopeException when the write credential is missing
     * @throws HuntressEscalationAlreadyResolvedException upstream 409
     * @throws HuntressEscalationNotApiResolvableException upstream 422
     * @throws HuntressClientException any other upstream failure
     */
    public function resolveEscalation(int $escalationId): array
    {
        if (! $this->isConfigured()) {
            throw new HuntressWriteScopeException(
                'Huntress API key pair is not configured; nothing was sent. Save the pair in '.\App\Support\HuntressConfig::WRITE_CREDENTIAL_LOCATION.'.'
            );
        }

        if ($escalationId <= 0) {
            throw new HuntressWriteScopeException('escalation_id must be a positive integer; nothing was sent.');
        }

        $endpoint = "escalations/{$escalationId}/resolution";

        // Bounded 429 retry mirroring HuntressClient (60 req/min account
        // limit). Retrying this POST is safe: a resolve that already landed
        // answers the retry with 409, which maps to already-resolved above us.
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->request('POST', $endpoint, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    // The literal empty object — never a serialized caller
                    // structure. json_encode([]) would emit `[]`, so the body
                    // is pinned as a string constant.
                    'body' => self::EMPTY_RESOLUTION_BODY,
                ]);
                break;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                if ($status === 409) {
                    throw new HuntressEscalationAlreadyResolvedException(
                        "Escalation {$escalationId} has already been resolved upstream.", 409, $e
                    );
                }

                if ($status === 422) {
                    throw new HuntressEscalationNotApiResolvableException(
                        "Escalation {$escalationId} cannot be resolved through the API.", 422, $e
                    );
                }

                if ($status === 429 && $attempt < $maxAttempts) {
                    $header = $e instanceof RequestException && $e->getResponse() !== null
                        ? $e->getResponse()->getHeaderLine('Retry-After')
                        : '';
                    $retryAfter = self::retryDelaySeconds($header, $attempt);
                    Log::info("[HuntressWriteClient] Rate limited on {$endpoint}, retrying in {$retryAfter}s");
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }

                    continue;
                }

                Log::error("[HuntressWriteClient] POST {$endpoint} failed: {$e->getMessage()}");
                throw new HuntressClientException(
                    "Huntress API error: {$e->getMessage()}", $e->getCode(), $e
                );
            }
        }

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            $body = [];
        }

        return self::unwrapResolution($body);
    }

    /**
     * The body keys each unwanted-access-rule verb may send, from the vendor's
     * UnwantedAccessRuleCreationParameters / UnwantedAccessRuleUpdateParameters
     * (Huntress OpenAPI, /v1/unwanted_access_rules). Create is the standard-rule
     * subset only: identity_id, logic and category are never sent, and
     * organization_id is REQUIRED, so an account-scope rule (no scope id) cannot
     * be expressed through this client.
     *
     * @var array<int, string>
     */
    public const RULE_CREATE_KEYS = ['organization_id', 'country_code', 'vpn', 'ip_address', 'type', 'starts_at', 'expires_at', 'notes'];

    /** @var array<int, string> PATCH may change only the schedule and the note. */
    public const RULE_UPDATE_KEYS = ['starts_at', 'expires_at', 'notes'];

    /**
     * POST /v1/unwanted_access_rules with the given body (201
     * `{unwanted_access_rule: …}`). Refuses before any HTTP unless every key is
     * in RULE_CREATE_KEYS and organization_id is a positive integer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the decoded response
     */
    public function createUnwantedAccessRule(array $body): array
    {
        $this->assertRuleBody($body, self::RULE_CREATE_KEYS);
        if (! is_int($body['organization_id'] ?? null) || $body['organization_id'] <= 0) {
            throw new HuntressWriteScopeException('organization_id must be a positive integer; nothing was sent.');
        }

        return $this->send('POST', 'unwanted_access_rules', $body);
    }

    /**
     * PATCH /v1/unwanted_access_rules/{id} (200 `{unwanted_access_rule: …}`).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateUnwantedAccessRule(int $ruleId, array $body): array
    {
        $this->assertRuleBody($body, self::RULE_UPDATE_KEYS);
        if ($body === []) {
            throw new HuntressWriteScopeException('An update needs at least one field; nothing was sent.');
        }

        return $this->send('PATCH', 'unwanted_access_rules/'.$this->ruleIdOrFail($ruleId), $body);
    }

    /**
     * DELETE /v1/unwanted_access_rules/{id} (202 `{unwanted_access_rule: …}`). No body.
     *
     * @return array<string, mixed>
     */
    public function deleteUnwantedAccessRule(int $ruleId): array
    {
        $this->assertConfigured();

        return $this->send('DELETE', 'unwanted_access_rules/'.$this->ruleIdOrFail($ruleId), null);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new HuntressWriteScopeException(
                'Huntress API key pair is not configured; nothing was sent. Save the pair in '.\App\Support\HuntressConfig::WRITE_CREDENTIAL_LOCATION.'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $allowed
     */
    private function assertRuleBody(array $body, array $allowed): void
    {
        $this->assertConfigured();
        if (array_is_list($body) && $body !== []) {
            throw new HuntressWriteScopeException('The rule body must be an object; nothing was sent.');
        }
        $unknown = array_diff(array_map('strval', array_keys($body)), $allowed);
        if ($unknown !== []) {
            throw new HuntressWriteScopeException('Rule body key(s) not allowed: '.implode(', ', $unknown).'; nothing was sent.');
        }
    }

    private function ruleIdOrFail(int $ruleId): int
    {
        if ($ruleId <= 0) {
            throw new HuntressWriteScopeException('rule id must be a positive integer; nothing was sent.');
        }

        return $ruleId;
    }

    /**
     * One rule request with the same bounded 429 retry as the resolve: at most
     * three attempts, and only a 429 is retried. Any other failure throws
     * HuntressClientException carrying the HTTP status as its code (0 when
     * there was no response).
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $endpoint, ?array $body): array
    {
        $options = ['headers' => ['Accept' => 'application/json']];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->http->request($method, $endpoint, $options);
                break;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : 0;
                if ($status === 429 && $attempt < 3) {
                    $retryAfter = self::retryDelaySeconds($e->getResponse()->getHeaderLine('Retry-After'), $attempt);
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }

                    continue;
                }

                Log::error("[HuntressWriteClient] {$method} {$endpoint} failed", ['status' => $status, 'exception' => $e::class]);
                throw new HuntressClientException('Huntress API error: '.$e::class, $status, $e);
            }
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The 429 back-off for one attempt: the upstream Retry-After when it is a
     * sane numeric value, else exponential (2s, 4s) — ALWAYS clamped to
     * RETRY_AFTER_CEILING_SECONDS, because the header is upstream-controlled
     * and this sleep runs inside a synchronous approval request. Negative and
     * non-numeric headers fall back to the exponential default.
     */
    public static function retryDelaySeconds(string $retryAfterHeader, int $attempt): int
    {
        $delay = 2 ** max(1, $attempt);
        if (is_numeric($retryAfterHeader) && (int) $retryAfterHeader >= 0) {
            $delay = (int) $retryAfterHeader;
        }

        return min($delay, self::RETRY_AFTER_CEILING_SECONDS);
    }

    /**
     * Defensive unwrap, mirroring HuntressClient::getEscalation().
     *
     * The arm is selected by CONTENT, not by structure. `resolution` is only
     * a wrapper when the thing it holds is the resolution object — i.e. when
     * it carries the required `resolution_method`. A structural `is_array()`
     * test would also hijack a non-wrapper sibling (a nested detail object
     * `{"notes":…,"analyst":…}`, a list of notes, an empty array) exactly as a
     * scalar sibling (a note, a state string) would, discarding a body whose
     * own top-level `resolution_method` is perfectly valid. The executor's
     * post-condition reads a missing method as a HARD FAULT, so either
     * misreading turns every clean resolve into a recorded security fault
     * that pages a human. Hence: a wrapper key is taken FIRST, and only when
     * its array carries the field; otherwise the body — which may report the
     * method itself — falls through unchanged.
     *
     * Precedence is SEVERITY-AWARE, not positional. Every content-verified
     * candidate — each wrapper that carries the field, and the body itself
     * when it does — is considered together, and the first whose
     * `resolution_method` falls OUTSIDE the executor's safe set
     * (SAFE_RESOLUTION_METHODS, mirrored from the executor) wins; only when
     * every candidate is safe does the first in wrapper-then-body order stand.
     * Any FIXED positional rule — wrapper-first or body-first — lets a `rule`
     * resolution in the losing position (attribute rules WERE created) be
     * laundered into a clean `executed` with nobody paged; severity-first
     * selection closes that hole in both directions. Every misread in this
     * function must fail toward the loud false fault, never toward the silent
     * false success. Defensive code must never be able to turn a valid body
     * into a worse one than no unwrapping at all.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function unwrapResolution(array $body): array
    {
        // Candidates are collected on CONTENT, in wrapper-then-body order: an
        // inner object that carries the field IS a resolution object, and the
        // body itself is one when it reports the method at top level.
        $candidates = [];
        foreach (['escalation_resolution', 'resolution'] as $wrapperKey) {
            $candidate = $body[$wrapperKey] ?? null;
            if (is_array($candidate) && is_scalar($candidate['resolution_method'] ?? null)) {
                $candidates[] = $candidate;
            }
        }
        if (is_scalar($body['resolution_method'] ?? null)) {
            $candidates[] = $body;
        }

        // Severity first: an unsafe method anywhere is the one the executor's
        // post-condition must judge, whatever position reported it.
        foreach ($candidates as $candidate) {
            if (! in_array($candidate['resolution_method'], self::SAFE_RESOLUTION_METHODS, true)) {
                return $candidate;
            }
        }

        // All candidates safe → the first stands. No candidate at all → any
        // same-named sibling is a detail field whatever its shape, and the
        // body falls through unchanged for the executor to fault on.
        return $candidates[0] ?? $body;
    }
}
