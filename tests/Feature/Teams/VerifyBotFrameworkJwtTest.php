<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\Setting;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * VerifyBotFrameworkJwt — the FAIL-CLOSED inbound Bot Framework JWT gate (E1).
 *
 * Validation is grounded in Microsoft's "Authenticate requests with the Bot
 * Connector API": iss == https://api.botframework.com, aud == the bot App ID,
 * RS256 signature against the Bot Framework JWKS, exp/nbf with 5-min skew. EVERY
 * failure path rejects (401). There is intentionally NO bypass: unconfigured,
 * missing header, bad signature, wrong audience, wrong issuer, and expiry all
 * reject. The JWKS is primed into the cache so no real HTTP call is made.
 */
class VerifyBotFrameworkJwtTest extends TestCase
{
    use RefreshDatabase;

    private const KID = 'test-key-1';

    private const BOT_FRAMEWORK_ISSUER = 'https://api.botframework.com';

    private string $appId = '11111111-1111-1111-1111-111111111111';

    /** Generate an RSA keypair and return [privatePem, publicJwk] for signing/JWKS. */
    private function keypair(string $kid = self::KID): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);

        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        $jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $b64url($details['rsa']['n']),
            'e' => $b64url($details['rsa']['e']),
        ];

        return [$privatePem, $jwk];
    }

    /** Prime the JWKS cache so the middleware validates against this public key, no HTTP. */
    private function primeJwks(array $jwk): void
    {
        Cache::put(VerifyBotFrameworkJwt::JWKS_CACHE_KEY, ['keys' => [$jwk]], now()->addDay());
    }

    private function configureBot(): void
    {
        Setting::setValue('teams_bot_app_id', $this->appId);
        Setting::setValue('teams_bot_tenant_id', '22222222-2222-2222-2222-222222222222');
        TeamsBotConfig::setClientSecret('secret');
    }

    private function sign(string $privatePem, array $overrides = [], string $kid = self::KID): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::BOT_FRAMEWORK_ISSUER,
            'aud' => $this->appId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            // Bot Framework emits this claim LOWERCASED — mirror the real token shape.
            'serviceurl' => 'https://smba.trafficmanager.net/teams/',
        ], $overrides);

        return JWT::encode($payload, $privatePem, 'RS256', $kid);
    }

    /** Run the middleware and return the resulting HTTP status (200 = passed to $next). */
    private function runMiddleware(?string $token): int
    {
        $request = Request::create('/api/teams/messages', 'POST');
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        $response = (new VerifyBotFrameworkJwt)->handle($request, fn () => response('ok', 200));

        return $response->getStatusCode();
    }

    // ── fail-closed paths ─────────────────────────────────────────────────────

    public function test_unconfigured_rejects_even_a_well_formed_token(): void
    {
        // No bot configured at all ⇒ fail closed, regardless of the token.
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        $this->assertSame(401, $this->runMiddleware($this->sign($priv)));
    }

    public function test_missing_authorization_header_rejects(): void
    {
        $this->configureBot();

        $this->assertSame(401, $this->runMiddleware(null));
    }

    public function test_bad_signature_rejects(): void
    {
        $this->configureBot();
        // JWKS publishes key A; the token is signed by a DIFFERENT key B (same kid).
        [, $jwkA] = $this->keypair();
        [$privB] = $this->keypair();
        $this->primeJwks($jwkA);

        $this->assertSame(401, $this->runMiddleware($this->sign($privB)));
    }

    public function test_wrong_audience_rejects(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // Valid signature, valid issuer, but aud is some OTHER app id.
        $token = $this->sign($priv, ['aud' => '99999999-9999-9999-9999-999999999999']);
        $this->assertSame(401, $this->runMiddleware($token));
    }

    public function test_wrong_issuer_rejects(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        $token = $this->sign($priv, ['iss' => 'https://evil.example.com']);
        $this->assertSame(401, $this->runMiddleware($token));
    }

    public function test_expired_token_rejects(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // Expired well beyond the 5-minute clock-skew leeway.
        $now = time();
        $token = $this->sign($priv, ['iat' => $now - 7200, 'nbf' => $now - 7200, 'exp' => $now - 3600]);
        $this->assertSame(401, $this->runMiddleware($token));
    }

    public function test_array_audience_without_our_app_id_rejects(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // aud is a JSON array (allowed by the JWT spec) but does NOT contain our App ID.
        $token = $this->sign($priv, ['aud' => ['99999999-9999-9999-9999-999999999999', 'another']]);
        $this->assertSame(401, $this->runMiddleware($token));
    }

    // ── the passing paths ─────────────────────────────────────────────────────

    public function test_valid_token_passes(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        $this->assertSame(200, $this->runMiddleware($this->sign($priv)), 'a correctly-signed channel token must pass');
    }

    public function test_lowercase_serviceurl_claim_is_surfaced_for_pinning(): void
    {
        // Bot Framework emits the destination claim as lowercase "serviceurl". The
        // middleware must surface it (not the camelCase name) or the controller's
        // pin sees null and the bot silently never replies — the live prod bug.
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        $token = $this->sign($priv, ['serviceurl' => 'https://smba.trafficmanager.net/amer/']);

        $captured = 'UNSET';
        $request = Request::create('/api/teams/messages', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$token);
        (new VerifyBotFrameworkJwt)->handle($request, function ($req) use (&$captured) {
            $captured = $req->attributes->get('teams_bot_service_url');

            return response('ok', 200);
        });

        $this->assertSame('https://smba.trafficmanager.net/amer/', $captured);
    }

    public function test_array_audience_containing_our_app_id_passes(): void
    {
        $this->configureBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // aud as an array that INCLUDES our App ID is valid per the JWT spec.
        $token = $this->sign($priv, ['aud' => [$this->appId, 'extra-audience']]);
        $this->assertSame(200, $this->runMiddleware($token));
    }
}
