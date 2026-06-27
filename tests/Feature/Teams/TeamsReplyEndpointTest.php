<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\Setting;
use App\Models\User;
use App\Services\Teams\TeamsAmbientService;
use App\Services\Teams\TeamsReplyService;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * POST /api/teams/messages reply path (E2a). When the bot is @mentioned by a
 * resolved staff user AND the bridge is enabled AND the serviceUrl is pinned to
 * the validated JWT claim, the conversational loop runs and replies. Every other
 * case (dormant, non-mention, unresolved, serviceUrl mismatch) just acks 200 with
 * NO reply.
 */
class TeamsReplyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $appId = '11111111-1111-1111-1111-111111111111';

    private string $tenantId = '22222222-2222-2222-2222-222222222222';

    private string $serviceUrl = 'https://smba.trafficmanager.net/amer/';

    private string $privatePem = '';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::setValue('teams_bot_app_id', $this->appId);
        Setting::setValue('teams_bot_tenant_id', $this->tenantId);
        TeamsBotConfig::setClientSecret('secret');

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $this->privatePem);
        $d = openssl_pkey_get_details($res);
        $b64 = fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        Cache::put(VerifyBotFrameworkJwt::JWKS_CACHE_KEY, ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1',
            'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e']),
        ]]], now()->addDay());
    }

    private function token(?string $serviceUrl = null): string
    {
        $now = time();

        return JWT::encode([
            'iss' => 'https://api.botframework.com',
            'aud' => $this->appId,
            'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600,
            // Bot Framework emits this claim LOWERCASED — mirror the real token shape.
            'serviceurl' => $serviceUrl ?? $this->serviceUrl,
        ], $this->privatePem, 'RS256', 'k1');
    }

    private function activity(string $aadObjectId, bool $mention, ?string $serviceUrl = null): array
    {
        $a = [
            'type' => 'message',
            'text' => ($mention ? '<at>PSA Bot</at> ' : '').'what tickets are open?',
            'recipient' => ['id' => $this->appId],
            'channelData' => ['tenant' => ['id' => $this->tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Charlie'],
            'conversation' => ['id' => 'a:conv-1'],
            'serviceUrl' => $serviceUrl ?? $this->serviceUrl,
        ];
        if ($mention) {
            $a['entities'] = [['type' => 'mention', 'mentioned' => ['id' => $this->appId, 'name' => 'PSA Bot']]];
        }

        return $a;
    }

    private function sendActivity(string $token, array $activity)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/teams/messages', $activity);
    }

    public function test_mention_from_a_resolved_user_when_enabled_runs_the_reply_loop(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsReplyService::class, function (MockInterface $m) {
            $m->shouldReceive('reply')
                ->once()
                ->withArgs(fn ($sender, $text, $msp) => $sender->user->microsoft_id === 'aad-charlie'
                    && ! str_contains($text, '<at>')          // the mention was stripped
                    && str_contains($text, 'what tickets are open?'));
        });

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: true))->assertOk();
    }

    public function test_a_non_mention_message_does_not_reply_when_ambient_is_off(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        // teams_ambient_enabled unset ⇒ the real TeamsAmbientService returns false.
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: false))->assertOk();
    }

    // ── E2b ambient chiming-in ───────────────────────────────────────────────

    public function test_a_non_mention_chimes_in_when_the_ambient_gate_says_yes(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsAmbientService::class, fn (MockInterface $m) => $m->shouldReceive('shouldChimeIn')->once()->andReturnTrue());
        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->once());

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: false))->assertOk();
    }

    public function test_a_non_mention_stays_silent_when_the_ambient_gate_says_no(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsAmbientService::class, fn (MockInterface $m) => $m->shouldReceive('shouldChimeIn')->once()->andReturnFalse());
        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: false))->assertOk();
    }

    public function test_a_mention_replies_without_consulting_the_ambient_gate(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // The @mention path always replies (E2a) and must NOT run the ambient gate.
        $this->mock(TeamsAmbientService::class, fn (MockInterface $m) => $m->shouldReceive('shouldChimeIn')->never());
        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->once());

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: true))->assertOk();
    }

    public function test_dormant_when_the_flag_is_off_does_not_reply(): void
    {
        // teams_bot_enabled unset ⇒ dormant.
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        $this->sendActivity($this->token(), $this->activity('aad-charlie', mention: true))->assertOk();
    }

    public function test_an_unresolved_sender_does_not_reply(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        // No PSA user for this aadObjectId.

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        $this->sendActivity($this->token(), $this->activity('aad-stranger', mention: true))->assertOk();
    }

    public function test_a_mention_with_no_text_after_stripping_does_not_reply(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        // Just the mention markup, no actual words — nothing to answer.
        $activity = $this->activity('aad-charlie', mention: true);
        $activity['text'] = '<at>PSA Bot</at>   ';

        $this->sendActivity($this->token(), $activity)->assertOk();
    }

    public function test_service_url_claim_mismatch_does_not_reply(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->never());

        // The JWT was signed for one serviceUrl, but the activity body claims another
        // (both are trusted hosts) — the pin to the signed claim must fail closed.
        $token = $this->token('https://smba.trafficmanager.net/amer/');
        $activity = $this->activity('aad-charlie', mention: true, serviceUrl: 'https://smba.trafficmanager.net/emea/');

        $this->sendActivity($token, $activity)->assertOk();
    }

    public function test_service_url_trailing_slash_difference_still_pins_and_replies(): void
    {
        Setting::setValue('teams_bot_enabled', '1');
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // An inconsequential trailing-slash difference between the signed claim and
        // the activity body (same host + path) must NOT fail the pin closed.
        $this->mock(TeamsReplyService::class, fn (MockInterface $m) => $m->shouldReceive('reply')->once());

        $token = $this->token('https://smba.trafficmanager.net/amer/');
        $activity = $this->activity('aad-charlie', mention: true, serviceUrl: 'https://smba.trafficmanager.net/amer');

        $this->sendActivity($token, $activity)->assertOk();
    }
}
