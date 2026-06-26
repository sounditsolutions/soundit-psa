<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\Setting;
use App\Models\User;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * POST /api/teams/messages (E1) — the inbound pipe end-to-end: the route is
 * JWT-protected (fail-closed) and, once authenticated, identifies the sender and
 * returns a benign 200 ack. E1 ships DORMANT: it never replies conversationally
 * (that is E2) — enabled or not, an authenticated turn just acks.
 */
class TeamsMessagesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $appId = '11111111-1111-1111-1111-111111111111';

    private string $tenantId = '22222222-2222-2222-2222-222222222222';

    private string $privatePem = '';

    private function configureBot(): void
    {
        Setting::setValue('teams_bot_app_id', $this->appId);
        Setting::setValue('teams_bot_tenant_id', $this->tenantId);
        TeamsBotConfig::setClientSecret('secret');

        // Prime the JWKS cache with a test public key and keep the private key to sign.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $this->privatePem);
        $d = openssl_pkey_get_details($res);
        $b64 = fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        Cache::put(VerifyBotFrameworkJwt::JWKS_CACHE_KEY, ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1',
            'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e']),
        ]]], now()->addDay());
    }

    private function token(): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://api.botframework.com',
            'aud' => $this->appId,
            'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
            'serviceUrl' => 'https://smba.trafficmanager.net/teams/',
        ], $this->privatePem, 'RS256', 'k1');
    }

    private function activity(string $aadObjectId): array
    {
        return [
            'type' => 'message',
            'text' => 'hello',
            'recipient' => ['id' => $this->appId],
            'channelData' => ['tenant' => ['id' => $this->tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Charlie'],
            'conversation' => ['id' => 'a:conv-1'],
            'serviceUrl' => 'https://smba.trafficmanager.net/teams/',
        ];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->configureBot();

        // No Authorization header ⇒ the middleware rejects fail-closed.
        $this->postJson('/api/teams/messages', $this->activity('aad-charlie'))->assertStatus(401);
    }

    public function test_authenticated_turn_from_a_known_sender_acks_200(): void
    {
        $this->configureBot();
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/teams/messages', $this->activity('aad-charlie'))
            ->assertOk();
    }

    public function test_authenticated_turn_from_an_unknown_sender_still_acks_200(): void
    {
        // Authenticated by the channel, but no PSA user — E1 acks (so the channel does
        // not retry) while the resolver audits and refuses to act.
        $this->configureBot();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/teams/messages', $this->activity('aad-stranger'))
            ->assertOk();
    }

    public function test_dormant_when_the_flag_is_off_acks_without_action(): void
    {
        $this->configureBot();
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);
        // teams_bot_enabled is unset ⇒ dormant. E1 still authenticates + acks.
        $this->assertFalse(TeamsBotConfig::enabled());

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/api/teams/messages', $this->activity('aad-charlie'))
            ->assertOk();
    }
}
