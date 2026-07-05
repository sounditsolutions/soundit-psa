<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Teams\TeamsReplyService;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Teams AI-Staff Personas P2 hardening (bd psa-7drx, Task 2 item 3) —
 * "persona is its own gate" for the @mention REPLY path, mirroring the
 * already-shipped Cluster B rule in OperatorDelivery::send() (P1 Task 3):
 * `($persona !== null || TeamsBotConfig::enabled())`. Before this task, the
 * reply gate at TeamsMessagesController::handle() checked ONLY
 * TeamsBotConfig::enabled() — an active, credential-complete persona could
 * be resolved and @mentioned yet never get a reply while the shared legacy
 * teams_bot_enabled toggle was off. A resolved persona is enabled()=true by
 * construction (TeamsPersonaConfig::active() already filters to that), so
 * its mere presence is its own dormancy gate; the legacy toggle continues to
 * govern the legacy (no-persona) single-bot path exactly as before.
 *
 * The persona in every "active persona" case here is pre-bound to conv-A via
 * conversation_refs, and the @mention arrives in a DIFFERENT conversation
 * (conv-B). This is REQUIRED to isolate item 3 from item 8 (conversation
 * auto-capture, see PersonaConversationCaptureTest): auto-capture only ever
 * writes conversation_refs when they are UNSET, and capture runs BEFORE the
 * routedToPersona() check — so a persona whose refs are already set (a) never
 * gets re-captured/overwritten and (b) is correctly NOT routed to its operator
 * lane when @mentioned from a conversation that isn't its own, which is
 * exactly the shape needed to exercise the reply-gate path under test here.
 */
class PersonaReplyGateTest extends TestCase
{
    use RefreshDatabase;

    /** Mirrors InboundAudBindingTest/PersonaLanedOperatorPollTest's fixture shape. */
    private function makePersona(array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create(array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app',
            'tenant_id' => 'persona-tenant',
            'bot_client_secret' => 'persona-secret',
            'enabled' => true,
        ], $overrides));
    }

    /** @return array{0: string, 1: array<string, mixed>} [privatePem, publicJwk] */
    private function keypair(string $kid): array
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

    private function sign(string $privatePem, string $aud, string $serviceUrl, string $kid): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://api.botframework.com',
            'aud' => $aud,
            'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
            'serviceurl' => $serviceUrl,
        ], $privatePem, 'RS256', $kid);
    }

    /** An @mention activity, following TeamsReplyEndpointTest's shape. */
    private function mentionActivity(string $appId, ?string $tenantId, string $aadObjectId, string $conversationId, string $serviceUrl): array
    {
        return [
            'type' => 'message',
            'text' => '<at>Bot</at> what is the status?',
            'recipient' => ['id' => $appId],
            'channelData' => ['tenant' => ['id' => $tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Charlie'],
            'conversation' => ['id' => $conversationId],
            'serviceUrl' => $serviceUrl,
            'entities' => [['type' => 'mention', 'mentioned' => ['id' => $appId, 'name' => 'Bot']]],
        ];
    }

    private function sendActivity(string $jwt, array $activity)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$jwt])
            ->postJson('/api/teams/messages', $activity);
    }

    public function test_active_persona_replies_even_when_the_legacy_toggle_is_off(): void
    {
        Setting::setValue('teams_bot_enabled', '0'); // legacy toggle OFF — the persona is its own gate.

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        $persona = $this->makePersona([
            'conversation_refs' => ['conversation_id' => 'conv-A', 'service_url' => $serviceUrl],
        ]);
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        [$priv, $jwk] = $this->keypair('persona-kid');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, $persona->bot_app_id, $serviceUrl, 'persona-kid');

        // @mention arrives in conv-B, NOT the persona's own conv-A — routedToPersona()
        // is false (not the operator lane) and item-8 auto-capture does not fire
        // (conversation_refs are already set), isolating the reply-gate path.
        $activity = $this->mentionActivity($persona->bot_app_id, $persona->tenant_id, 'aad-charlie', 'conv-B', $serviceUrl);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->once());

        $this->sendActivity($jwt, $activity)->assertOk();

        $this->assertSame(0, OperatorInbox::count(), 'conv-B is not the personas own conversation — must not enqueue to the operator lane');
        $this->assertSame('conv-A', $persona->fresh()->conversation_refs['conversation_id'] ?? null, 'the pre-existing binding must be untouched');
    }

    public function test_legacy_sender_does_not_reply_when_the_toggle_is_off(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant');
        TeamsBotConfig::setClientSecret('legacy-secret');
        Setting::setValue('teams_bot_enabled', '0');

        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        [$priv, $jwk] = $this->keypair('legacy-kid-off');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, 'legacy-app', $serviceUrl, 'legacy-kid-off');
        $activity = $this->mentionActivity('legacy-app', 'legacy-tenant', 'aad-charlie', 'a:conv-1', $serviceUrl);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        $this->sendActivity($jwt, $activity)->assertOk();
    }

    public function test_legacy_sender_replies_when_the_toggle_is_on(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant');
        TeamsBotConfig::setClientSecret('legacy-secret');
        Setting::setValue('teams_bot_enabled', '1');

        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        [$priv, $jwk] = $this->keypair('legacy-kid-on');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, 'legacy-app', $serviceUrl, 'legacy-kid-on');
        $activity = $this->mentionActivity('legacy-app', 'legacy-tenant', 'aad-charlie', 'a:conv-1', $serviceUrl);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->once());

        $this->sendActivity($jwt, $activity)->assertOk();
    }
}
