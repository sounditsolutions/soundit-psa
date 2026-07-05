<?php

namespace App\Http\Middleware;

use App\Support\TeamsBotConfig;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAIL-CLOSED verification of an inbound Bot Framework (Teams channel) JWT.
 *
 * Grounded in Microsoft's "Authenticate requests with the Bot Connector API"
 * (Connector → Bot). The bot MUST verify, with NO bypass:
 *   1. a Bearer token is present in the Authorization header;
 *   2. its RS256 signature is valid against a key in the Bot Framework JWKS
 *      (OpenID metadata → jwks_uri, cached ≥ 24h, refreshed when keys rotate);
 *   3. iss == https://api.botframework.com;
 *   4. aud == the bot's App ID (validated against the registered SET — the
 *      multi-MSP / multi-persona seam — not a hardcoded literal), matching
 *      EXACTLY ONE registered bot (an aud intersecting more than one is
 *      anomalous and also rejects — see matchedAudience());
 *   5. the token is within exp/nbf with industry-standard 5-minute clock skew.
 *
 * Any failure — including an UNCONFIGURED bot (mirrors VerifyTacticalWebhookKey)
 * or an unreachable JWKS — rejects with 401. This deliberately does NOT accept
 * the Bot Framework Emulator issuer: a production endpoint accepts only the real
 * channel authority (strictly more fail-closed). There is intentionally no path
 * that disables validation.
 *
 * Teams AI-Staff Personas P1: the SINGLE matched App ID is surfaced as the
 * `teams_bot_app_id` request attribute so TeamsIdentityResolver can bind persona
 * resolution to this SIGNED claim rather than the activity body's
 * (attacker-influenceable) recipient.id — see TeamsIdentityResolver::resolve().
 */
class VerifyBotFrameworkJwt
{
    /** The required issuer for tokens minted by the Azure Bot Connector service. */
    private const ISSUER = 'https://api.botframework.com';

    /** Static OpenID metadata document for the Bot Connector service. */
    private const OPENID_CONFIG_URL = 'https://login.botframework.com/v1/.well-known/openidconfiguration';

    /** Industry-standard clock skew (Microsoft guidance: 5 minutes). */
    private const LEEWAY_SECONDS = 300;

    public const JWKS_CACHE_KEY = 'teams_bot_bf_jwks';

    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed: an unconfigured bot has no audience to validate against.
        $appIds = TeamsBotConfig::appIds();
        if ($appIds === []) {
            return $this->reject($request, 'bot not configured');
        }

        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->reject($request, 'missing bearer token');
        }

        // Resolve the signing keys (cached). If they are unreachable we reject —
        // never fail open.
        try {
            $jwks = $this->jwks();
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $e) {
            return $this->reject($request, 'jwks unavailable: '.$e->getMessage());
        }

        // Verify signature + exp/nbf. firebase/php-jwt binds each key to its alg by
        // kid, so an alg-confusion / "alg: none" downgrade cannot pass.
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $claims = JWT::decode($token, $keys);
        } catch (\Throwable $e) {
            return $this->reject($request, 'invalid token: '.$e->getMessage());
        }

        if (($claims->iss ?? null) !== self::ISSUER) {
            return $this->reject($request, 'bad issuer');
        }

        $matchedAppId = $this->matchedAudience($claims->aud ?? null, $appIds);
        if ($matchedAppId === null) {
            return $this->reject($request, 'bad audience');
        }

        // Surface the VALIDATED serviceUrl claim so the receiver can pin the outbound
        // reply destination to it (E2). The activity body's serviceUrl is attacker-
        // influenceable; this signed claim is not. The controller asserts
        // claim === activity.serviceUrl before replying (requirement 7 of the Bot
        // Connector spec) — defence in depth alongside TeamsBotClient's host allowlist.
        // Bot Framework emits this claim LOWERCASED as "serviceurl"; tolerate either
        // case (camelCase kept as a fallback) so the pin doesn't silently fail closed.
        $serviceUrlClaim = $claims->serviceurl ?? $claims->serviceUrl ?? null;
        $request->attributes->set('teams_bot_service_url', is_string($serviceUrlClaim) ? $serviceUrlClaim : null);

        // Surface the SINGLE matched App ID (Teams AI-Staff Personas P1 — the
        // multi-bot seam). With appIds() now a SET, this is the only trustworthy
        // signal for WHICH registered bot the token is for; TeamsIdentityResolver
        // binds persona/routing resolution to this value rather than the activity
        // body's (attacker-influenceable) recipient.id.
        $request->attributes->set('teams_bot_app_id', $matchedAppId);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * aud may be a string (Bot Framework) or, per the JWT spec, an array. Returns the
     * SINGLE matched App ID from the registered SET, or null when there is no match OR
     * the match is ambiguous. An array aud intersecting MORE THAN ONE registered bot is
     * anomalous — a legitimate Bot Connector token is audienced for exactly one App
     * ID — so that case is also a rejection, never a "pick one" heuristic (P1's
     * multi-bot seam is exactly why an ambiguous match here would be dangerous: it
     * would leave WHICH bot the token is for undetermined).
     */
    private function matchedAudience(mixed $aud, array $appIds): ?string
    {
        if (is_string($aud)) {
            return in_array($aud, $appIds, true) ? $aud : null;
        }

        if (is_array($aud)) {
            // array_unique BEFORE counting: array_intersect() preserves duplicates
            // from its first argument, so an aud that names the SAME registered bot
            // twice (e.g. ['persona-app', 'persona-app']) would otherwise over-reject
            // as "2 distinct hits" even though it is unambiguous. Two DISTINCT
            // registered app_ids still produce 2 unique entries here and still reject.
            $intersect = array_values(array_unique(array_intersect(array_map('strval', $aud), $appIds)));

            return count($intersect) === 1 ? $intersect[0] : null;
        }

        return null;
    }

    /**
     * The Bot Framework JWKS, cached. Fetches the OpenID metadata for the
     * jwks_uri, then the keys. Cached 24h; the key list is stable but new keys may
     * be added, so it refreshes daily (Microsoft guidance).
     *
     * @return array{keys: array<int, array<string, mixed>>}
     */
    private function jwks(): array
    {
        return Cache::remember(self::JWKS_CACHE_KEY, now()->addHours(24), function (): array {
            $meta = Http::timeout(10)->get(self::OPENID_CONFIG_URL)->throw()->json();
            $jwksUri = is_array($meta) ? ($meta['jwks_uri'] ?? null) : null;
            if (! is_string($jwksUri) || $jwksUri === '') {
                throw new \RuntimeException('OpenID metadata missing jwks_uri');
            }

            $jwks = Http::timeout(10)->get($jwksUri)->throw()->json();
            if (! is_array($jwks) || empty($jwks['keys'])) {
                throw new \RuntimeException('JWKS document empty');
            }

            return $jwks;
        });
    }

    private function reject(Request $request, string $reason): Response
    {
        Log::warning('[Teams Bot] Inbound JWT rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
