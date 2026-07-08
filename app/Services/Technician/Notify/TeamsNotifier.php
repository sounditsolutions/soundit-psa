<?php

namespace App\Services\Technician\Notify;

use App\Services\Tactical\TacticalClient;
use App\Support\TechnicianConfig;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;

/**
 * Posts a notification card to the operator's configured Teams webhook URL
 * (incoming-webhook / Power Automate Workflow). App-only Graph chat-posting is a
 * Microsoft Protected API, so a webhook the operator provisions once is the
 * realistic PSA-native path. Fail-soft: a missing/failing webhook never throws.
 *
 * SSRF hardening (psa-ncl1 / CO-7): the webhook URL is operator-set and thus
 * attacker-influenceable. Defence-in-depth:
 *   - save-time SafeWebhookUrl rule (https-only, no private/reserved literal or
 *     DNS target) on IntegrationsController::updateTechnician; and
 *   - this request-time peer-IP PIN, which resolves the host, validates EVERY
 *     resolved address via SafeUrlInspector::ipIsSafe(), and pins the connection
 *     to the validated address(es) via CURLOPT_RESOLVE — the SAME proven control
 *     the Tactical client uses (TacticalClient::ssrfPinMiddleware). Validate and
 *     pin are atomic, closing the DNS-rebind TOCTOU the save-time check cannot: a
 *     host that passed save-time validation can never rebind to a private/
 *     metadata address at connect time. A host that resolves to NOTHING (e.g.
 *     IPv6-only under the IPv4 resolver) or to ANY private address is REFUSED —
 *     the middleware throws, this method's catch swallows it, and post() returns
 *     false with NOTHING sent (fail-closed for security, fail-soft for the app).
 */
class TeamsNotifier
{
    private Client $http;

    /**
     * @param  \GuzzleHttp\Client|null  $http  When null (the production /
     *                                         container-autowired path), a Guzzle client is built with the SSRF pin
     *                                         middleware installed (10s timeout, redirects disabled so a 30x can't
     *                                         escape the pinned IP). When provided (the test seam), it is used AS-IS.
     * @param  callable|null  $resolver  Host-resolution seam for the request-time
     *                                   SSRF pin: host => string[]|false. Defaults
     *                                   to gethostbynamel in production; injected
     *                                   by tests for determinism. Only consulted on
     *                                   the default-client path.
     */
    public function __construct(?Client $http = null, ?callable $resolver = null)
    {
        if ($http !== null) {
            $this->http = $http;

            return;
        }

        $stack = HandlerStack::create();
        $stack->push(self::ssrfPinMiddleware($resolver ?? 'gethostbynamel'), 'technician_webhook_ssrf_pin');

        $this->http = new Client([
            'handler' => $stack,
            'timeout' => 10,
            'allow_redirects' => false,
        ]);
    }

    /**
     * Guzzle middleware for the request-time SSRF pin on the operator webhook.
     *
     * Reuses the shared, proven control shipped for the Tactical client
     * (psa-rkf6): it resolves the target host through $resolver, validates that
     * EVERY resolved address is public/routable via SafeUrlInspector::ipIsSafe(),
     * and pins the connection to the validated address(es) with CURLOPT_RESOLVE so
     * curl performs no second DNS lookup. Fails CLOSED — an unsafe or unresolvable
     * host throws before connect. Exposed here as the notifier's own stable seam
     * (tests inject a resolver against a recording transport without coupling to
     * the Tactical class); production wires it via the constructor above.
     */
    public static function ssrfPinMiddleware(callable $resolver): callable
    {
        return TacticalClient::ssrfPinMiddleware($resolver);
    }

    public function post(string $title, string $body): bool
    {
        $url = TechnicianConfig::teamsWebhookUrl();
        if ($url === null) {
            return false;
        }

        try {
            // The pin middleware throws (fail-closed) before connect if the host
            // resolves to a private/reserved/link-local address or to nothing.
            $response = $this->http->request('POST', $url, [
                'json' => [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => $title,
                    'themeColor' => '0F6CBD',
                    'title' => $title,
                    'text' => $body,
                ],
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Throwable $e) {
            Log::warning('[Technician] Teams webhook post failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
