<?php

namespace Tests\Feature\Teams;

use App\Models\AssistantConversation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Teams\ChimeInGate;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsAmbientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TeamsAmbientService (E2b) — orchestrates the unprompted chime-in decision: the
 * double-dormancy flag, the per-conversation cooldown (anti-spam), the recent
 * transcript, and the Haiku gate. Returns true ONLY when the bot should speak now.
 */
class TeamsAmbientServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function sender(): ResolvedSender
    {
        $user = User::factory()->create();

        return new ResolvedSender(
            user: $user, appId: 'app', tenantId: 'tenant',
            conversationId: 'a:conv-1', serviceUrl: 'https://smba.trafficmanager.net/amer/', aadObjectId: 'aad-1',
        );
    }

    private function service(bool $gateSpeaks): TeamsAmbientService
    {
        $gate = $this->mock(ChimeInGate::class, fn (MockInterface $m) => $m->shouldReceive('shouldSpeak')->andReturn($gateSpeaks));

        return new TeamsAmbientService($gate);
    }

    public function test_silent_when_ambient_is_disabled(): void
    {
        // Double-dormant: even if the gate would speak, ambient off ⇒ never chime.
        $gate = $this->mock(ChimeInGate::class, fn (MockInterface $m) => $m->shouldReceive('shouldSpeak')->never());

        $this->assertFalse((new TeamsAmbientService($gate))->shouldChimeIn($this->sender(), 'something useful?'));
    }

    public function test_chimes_when_enabled_and_the_gate_says_yes(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');

        $this->assertTrue($this->service(gateSpeaks: true)->shouldChimeIn($this->sender(), 'the cert expires tomorrow'));
    }

    public function test_silent_when_the_gate_says_no(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');

        $this->assertFalse($this->service(gateSpeaks: false)->shouldChimeIn($this->sender(), 'lol'));
    }

    public function test_cooldown_suppresses_a_second_chime_within_the_window(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');
        Setting::setValue('teams_ambient_cooldown_seconds', '60');
        $sender = $this->sender();
        $service = $this->service(gateSpeaks: true);

        $this->assertTrue($service->shouldChimeIn($sender, 'first useful thing'));
        // A second message in the same conversation, within the cooldown, is suppressed
        // even though the gate would say yes — anti-spam, "don't dominate the chat".
        $this->assertFalse($service->shouldChimeIn($sender, 'another useful thing'));
    }

    public function test_a_conversation_with_no_id_does_not_chime(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');
        $user = User::factory()->create();
        $sender = new ResolvedSender($user, 'app', 'tenant', null, 'https://smba.trafficmanager.net/amer/', 'aad-1');

        $this->assertFalse($this->service(gateSpeaks: true)->shouldChimeIn($sender, 'x'));
    }

    public function test_recent_transcript_is_passed_to_the_gate(): void
    {
        Setting::setValue('teams_ambient_enabled', '1');
        $sender = $this->sender();

        // A prior transcript turn exists for this conversation.
        $conv = AssistantConversation::create(['external_key' => 'teams:a:conv-1', 'context_type' => 'teams_chat', 'user_id' => $sender->user->id]);
        $conv->messages()->create(['role' => 'user', 'content' => 'Justin: the backup failed last night']);

        $captured = null;
        $gate = $this->mock(ChimeInGate::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('shouldSpeak')->andReturnUsing(function ($recent, $new) use (&$captured) {
                $captured = $recent;

                return false;
            });
        });

        (new TeamsAmbientService($gate))->shouldChimeIn($sender, 'is it fixed?');

        $this->assertNotNull($captured);
        $this->assertSame('Justin: the backup failed last night', $captured[0]['content'] ?? null);
    }

    // ── Observability (psa-22gq): one '[Teams Ambient]' INFO line per evaluation, so
    // we can verify RSC delivery of non-@mention messages AND tune the gate live. ──

    public function test_a_chime_is_logged_with_the_decision(): void
    {
        Log::spy();
        Setting::setValue('teams_ambient_enabled', '1');
        Setting::setValue('teams_ambient_eagerness', 'high');

        $this->assertTrue($this->service(gateSpeaks: true)->shouldChimeIn($this->sender(), 'the cert expires tomorrow'));

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === '[Teams Ambient] chimed'
                && ($context['outcome'] ?? null) === 'chimed'
                && ($context['eagerness'] ?? null) === 'high'
                && ($context['conversation_id'] ?? null) === 'a:conv-1'
                && str_contains((string) ($context['snippet'] ?? ''), 'the cert expires tomorrow');
        })->once();
    }

    public function test_a_silent_evaluation_is_logged(): void
    {
        Log::spy();
        Setting::setValue('teams_ambient_enabled', '1');

        // The conservative gate stayed silent — still a delivered non-@mention message,
        // so it is logged (this is the common case that confirms RSC delivery).
        $this->assertFalse($this->service(gateSpeaks: false)->shouldChimeIn($this->sender(), 'lol just kidding'));

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === '[Teams Ambient] silent'
                && ($context['outcome'] ?? null) === 'silent'
                && ($context['conversation_id'] ?? null) === 'a:conv-1';
        })->once();
    }

    public function test_cooldown_suppression_is_logged_distinctly(): void
    {
        Log::spy();
        Setting::setValue('teams_ambient_enabled', '1');
        Setting::setValue('teams_ambient_cooldown_seconds', '60');
        $sender = $this->sender();
        $service = $this->service(gateSpeaks: true);

        $this->assertTrue($service->shouldChimeIn($sender, 'first useful thing'));
        $this->assertFalse($service->shouldChimeIn($sender, 'second thing inside the window'));

        // Two distinct lines: the first chimed, the second was cooldown-suppressed.
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context) => $message === '[Teams Ambient] chimed'
        )->once();
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            return $message === '[Teams Ambient] cooldown_suppressed'
                && ($context['outcome'] ?? null) === 'cooldown_suppressed';
        })->once();
    }

    public function test_dormant_evaluation_logs_nothing(): void
    {
        Log::spy();
        // ambient disabled (no teams_ambient_enabled setting) ⇒ the feature is off, the
        // gate is never run, and we emit no per-message noise.
        $this->assertFalse($this->service(gateSpeaks: true)->shouldChimeIn($this->sender(), 'anything at all'));

        Log::shouldNotHaveReceived('info');
    }

    public function test_the_logged_snippet_is_truncated_and_whitespace_collapsed(): void
    {
        Log::spy();
        Setting::setValue('teams_ambient_enabled', '1');

        $long = "first line\nsecond line that keeps going ".str_repeat('x', 200);
        $this->service(gateSpeaks: false)->shouldChimeIn($this->sender(), $long);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context) {
            $snippet = (string) ($context['snippet'] ?? '');

            return ! str_contains($snippet, "\n")   // newlines collapsed to spaces
                && mb_strlen($snippet) <= 81         // truncated to a short snippet (80 + ellipsis)
                && str_ends_with($snippet, '…');     // truncation marker present
        })->once();
    }
}
