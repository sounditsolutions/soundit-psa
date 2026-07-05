<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsIdentityResolver;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Teams AI-Staff Personas P1 — the signed-aud -> persona binding (bd psa-kh22, Task 2).
 *
 * With TeamsBotConfig::appIds() now a SET (legacy bot ∪ enabled personas — see Task 1),
 * JWT-audience membership alone no longer pins WHICH registered bot a token is for vs
 * which bot the (attacker-influenceable) activity body claims to address in
 * recipient.id. The load-bearing guarantee under test: TeamsIdentityResolver::resolve()
 * asserts the SIGNED validated aud equals the activity's recipient-derived App ID before
 * resolving anything, and a mismatch is a hard REJECT + AUDIT — never a fallback to
 * either value, and never a silent "pick one." The persona (or legacy-null) identity is
 * then resolved from the SIGNED claim, not the activity body.
 *
 * Two layers are covered:
 *  - The resolver layer, with an explicit $validatedAppId (as VerifyBotFrameworkJwt
 *    would supply via the `teams_bot_app_id` request attribute) — no real Bot
 *    Framework JWT is minted here; see TeamsIdentityResolverTest for the existing
 *    activity/user fixture conventions this file follows.
 *  - The middleware's aud -> attribute surfacing (`matchedAudience`, private), exercised
 *    via its observable behavior (signed test JWTs, real HTTP status + attribute),
 *    mirroring VerifyBotFrameworkJwtTest's harness.
 */
class InboundAudBindingTest extends TestCase
{
    use RefreshDatabase;

    // ── shared fixtures ──────────────────────────────────────────────────────

    /** A Bot Framework activity, following TeamsIdentityResolverTest's shape. */
    private function activity(string $recipientId, ?string $tenantId, ?string $aadObjectId): array
    {
        return [
            'recipient' => ['id' => $recipientId],
            'channelData' => ['tenant' => ['id' => $tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Sender'],
            'conversation' => ['id' => 'a:conv-123'],
            'serviceUrl' => 'https://smba.trafficmanager.net/teams/',
        ];
    }

    /** Mirrors TeamsPersonaRegistryTest's fixture shape. */
    private function makePersona(array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create(array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app',
            'tenant_id' => 'persona-tenant',
            'enabled' => true,
        ], $overrides));
    }

    // ── resolver layer: signed-aud -> persona binding ────────────────────────

    public function test_matching_aud_and_recipient_resolves_persona(): void
    {
        $this->makePersona();
        $user = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $activity = $this->activity('28:persona-app', 'persona-tenant', 'aad-charlie');

        $resolved = app(TeamsIdentityResolver::class)->resolve($activity, 'persona-app');

        $this->assertInstanceOf(ResolvedSender::class, $resolved);
        $this->assertSame('gus', $resolved->personaKey);
        $this->assertSame('persona-app', $resolved->appId);
        $this->assertSame($user->id, $resolved->user->id);
    }

    public function test_aud_recipient_mismatch_is_rejected_and_audited(): void
    {
        Log::spy();
        $this->makePersona();
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // The signed aud validates this token for "persona-app", but the activity body
        // (attacker-influenceable) claims to address a DIFFERENT bot. This is exactly
        // the routing-spoof shape P1 must reject — never resolve, never fall back.
        $activity = $this->activity('28:other-app', 'persona-tenant', 'aad-charlie');

        $resolved = app(TeamsIdentityResolver::class)->resolve($activity, 'persona-app');

        $this->assertNull($resolved);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === '[Teams Bot] Unresolved sender — refusing to act'
                && ($context['reason'] ?? null) === 'aud/recipient mismatch'
                && ($context['validated_aud'] ?? null) === 'persona-app';
        });
    }

    public function test_legacy_app_resolves_persona_null(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant');
        $user = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $activity = $this->activity('28:legacy-app', 'legacy-tenant', 'aad-charlie');

        $resolved = app(TeamsIdentityResolver::class)->resolve($activity, 'legacy-app');

        $this->assertInstanceOf(ResolvedSender::class, $resolved);
        $this->assertNull($resolved->personaKey);
        $this->assertSame('legacy-app', $resolved->appId);
        $this->assertSame($user->id, $resolved->user->id);
    }

    public function test_null_validated_app_id_keeps_legacy_recipient_path(): void
    {
        // A caller not behind the JWT middleware (validatedAppId === null) must keep
        // the pre-P1 recipient-derived path verbatim: no cross-check performed, and a
        // persona still resolves correctly from the activity body alone. This is the
        // regression lock for every pre-existing TeamsIdentityResolverTest call site,
        // which all call resolve() with a single argument.
        $this->makePersona();
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $activity = $this->activity('28:persona-app', 'persona-tenant', 'aad-charlie');

        $resolved = app(TeamsIdentityResolver::class)->resolve($activity, null);

        $this->assertInstanceOf(ResolvedSender::class, $resolved);
        $this->assertSame('gus', $resolved->personaKey);
        $this->assertSame('persona-app', $resolved->appId);
    }

    // ── middleware layer: matchedAudience (private) via observable behavior ──

    private const KID = 'aud-binding-test-key-1';

    private const BOT_FRAMEWORK_ISSUER = 'https://api.botframework.com';

    /** @return array{0: string, 1: array<string, mixed>} [privatePem, publicJwk] */
    private function keypair(string $kid = self::KID): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);

        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        $jwk = [
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => $b64url($details['rsa']['n']), 'e' => $b64url($details['rsa']['e']),
        ];

        return [$privatePem, $jwk];
    }

    private function primeJwks(array $jwk): void
    {
        Cache::put(VerifyBotFrameworkJwt::JWKS_CACHE_KEY, ['keys' => [$jwk]], now()->addDay());
    }

    private function sign(string $privatePem, mixed $aud, string $kid = self::KID): string
    {
        $now = time();

        return JWT::encode([
            'iss' => self::BOT_FRAMEWORK_ISSUER,
            'aud' => $aud,
            'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
            'serviceurl' => 'https://smba.trafficmanager.net/teams/',
        ], $privatePem, 'RS256', $kid);
    }

    /** Runs the middleware and returns [statusCode, captured teams_bot_app_id attribute]. */
    private function runMiddleware(string $token): array
    {
        $request = Request::create('/api/teams/messages', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $captured = 'UNSET';
        $response = (new VerifyBotFrameworkJwt)->handle($request, function ($req) use (&$captured) {
            $captured = $req->attributes->get('teams_bot_app_id');

            return response('ok', 200);
        });

        return [$response->getStatusCode(), $captured];
    }

    private function configureLegacyBot(string $appId = 'legacy-app'): void
    {
        Setting::setValue('teams_bot_app_id', $appId);
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant');
        TeamsBotConfig::setClientSecret('secret');
    }

    public function test_matched_string_audience_is_surfaced_as_the_teams_bot_app_id_attribute(): void
    {
        $this->configureLegacyBot();
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        [$status, $attr] = $this->runMiddleware($this->sign($priv, 'legacy-app'));

        $this->assertSame(200, $status);
        $this->assertSame('legacy-app', $attr);
    }

    public function test_single_intersecting_array_audience_is_surfaced_as_the_teams_bot_app_id_attribute(): void
    {
        $this->configureLegacyBot();
        $this->makePersona(['bot_app_id' => 'persona-app']); // a SECOND registered bot
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // aud (array, per the JWT spec) intersects our registered set in EXACTLY one
        // place — unambiguous, so it passes and the SINGLE match is surfaced.
        [$status, $attr] = $this->runMiddleware($this->sign($priv, ['persona-app', 'unrelated-audience']));

        $this->assertSame(200, $status);
        $this->assertSame('persona-app', $attr);
    }

    public function test_array_audience_intersecting_two_registered_bots_is_rejected(): void
    {
        Log::spy();
        $this->configureLegacyBot();
        $this->makePersona(['bot_app_id' => 'persona-app']); // a SECOND registered bot
        [$priv, $jwk] = $this->keypair();
        $this->primeJwks($jwk);

        // A token audienced for BOTH of our registered bots is anomalous — a real Bot
        // Connector token is audienced for exactly one App ID. Reject, don't guess.
        [$status, $attr] = $this->runMiddleware($this->sign($priv, ['legacy-app', 'persona-app']));

        $this->assertSame(401, $status);
        $this->assertSame('UNSET', $attr, 'the reject path must never reach $next, so the attribute is never captured');
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === '[Teams Bot] Inbound JWT rejected' && ($context['reason'] ?? null) === 'bad audience';
        });
    }
}
