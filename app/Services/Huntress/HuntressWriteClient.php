<?php

namespace App\Services\Huntress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * The Huntress WRITE lane — deliberately a separate class from the GET-only
 * HuntressClient, on a separate credential class: the spec documents the
 * default account API key as read-only, and escalation resolution requires a
 * user-based API key (`huntress_user_api_key` / `huntress_user_api_secret`).
 * This client NEVER falls back to the read pair — an instance holding only
 * read credentials fails closed rather than discovering at the vendor that
 * the account key happened to be over-scoped.
 *
 * One verb: POST /v1/escalations/{id}/resolution with the LITERAL empty JSON
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

    private Client $http;

    /**
     * @param  Client|null  $http  Injectable transport (test seam). When null the
     *                             default Basic-auth Guzzle client is built from
     *                             the USER-key config pair.
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.huntress.io/v1/',
            'timeout' => 30,
            'auth' => [
                $this->config['user_api_key'] ?? '',
                $this->config['user_api_secret'] ?? '',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['user_api_key'])
            && ! empty($this->config['user_api_secret']);
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
                'Huntress write credential (user-based API key) is not configured; nothing was sent.'
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
                    $retryAfter = 2 ** $attempt;
                    if ($e instanceof RequestException && $e->getResponse() !== null) {
                        $header = $e->getResponse()->getHeaderLine('Retry-After');
                        if (is_numeric($header)) {
                            $retryAfter = (int) $header;
                        }
                    }
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

        // Defensive unwrap, mirroring HuntressClient::getEscalation().
        $resolution = $body['escalation_resolution'] ?? $body['resolution'] ?? $body;

        return is_array($resolution) ? $resolution : [];
    }
}
