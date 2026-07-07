<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\McpAuditLog;
use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Support\TeamsPersonaConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Admin "reset conversation binding" (unbind) action for a Teams persona
 * (bd psa-3vr5). Clears a persona's write-once conversation_refs so the next
 * allowlist-gated inbound re-captures it — the ONLY sanctioned rebind path
 * (Mayor constraint 3). Confirm-gated + audited, on Settings > Integrations.
 *
 * The inverse of PersonaConversationCaptureTest: that binds, this unbinds.
 */
class PersonaConversationUnbindTest extends TestCase
{
    use RefreshDatabase;

    /** A credential-complete, enabled persona (surfaces through active()/byKey()); pass null refs for unbound. */
    private function makePersona(?array $refs, array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create(array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app',
            'tenant_id' => 'persona-tenant',
            'bot_client_secret' => 'persona-secret',
            'conversation_refs' => $refs,
            'enabled' => true,
        ], $overrides));
    }

    public function test_unbind_clears_conversation_refs_and_flushes_the_registry_memo(): void
    {
        $user = User::factory()->create();
        $persona = $this->makePersona([
            'conversation_id' => 'conv-bound',
            'service_url' => 'https://smba.trafficmanager.net/amer/',
        ]);

        // Prime the per-request memo so a stale snapshot exists — the unbind must
        // bust it, or a same-request reader would still see the old binding.
        $this->assertSame(
            'conv-bound',
            TeamsPersonaConfig::byKey('gus')?->conversation_refs['conversation_id'] ?? null,
            'precondition: the registry serves the binding before unbind',
        );

        $this->actingAs($user)
            ->delete(route('settings.integrations.persona.unbind-conversation', $persona))
            ->assertRedirect(route('settings.integrations'));

        $this->assertNull($persona->fresh()->conversation_refs, 'the binding must be cleared in the DB');
        $this->assertNull(
            TeamsPersonaConfig::byKey('gus')?->conversation_refs,
            'the registry memo must be flushed so no same-request reader sees the stale binding',
        );
    }

    public function test_unbind_writes_an_audit_record_with_actor_and_old_conversation_id(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $persona = $this->makePersona([
            'conversation_id' => 'conv-was-bound',
            'service_url' => 'https://smba.trafficmanager.net/amer/',
        ]);

        $this->actingAs($user)
            ->delete(route('settings.integrations.persona.unbind-conversation', $persona));

        $this->assertSame(1, McpAuditLog::where('method', 'persona/unbind_conversation')->count());

        $audit = McpAuditLog::where('method', 'persona/unbind_conversation')->sole();
        $this->assertSame('web:admin@example.test', $audit->actor_label, 'WHO — the acting staff user');
        $this->assertSame('gus', $audit->tool_name, 'the persona acted on');
        $this->assertSame('conv-was-bound', $audit->arguments['old_conversation_id'] ?? null, 'old->new: the binding that was cleared');
        $this->assertSame('success', $audit->status);
    }

    public function test_unbind_on_an_already_unbound_persona_is_a_safe_noop_with_no_audit(): void
    {
        $user = User::factory()->create();
        $persona = $this->makePersona(null); // already unbound

        $this->actingAs($user)
            ->delete(route('settings.integrations.persona.unbind-conversation', $persona))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'no operator conversation binding'));

        $this->assertNull($persona->fresh()->conversation_refs);
        $this->assertSame(
            0,
            McpAuditLog::where('method', 'persona/unbind_conversation')->count(),
            'nothing changed — a no-op must not write an audit row',
        );
    }

    public function test_unbind_requires_authentication(): void
    {
        $refs = ['conversation_id' => 'conv-x', 'service_url' => 'https://smba.trafficmanager.net/amer/'];
        $persona = $this->makePersona($refs);

        // No actingAs(): a guest must be bounced by the settings `auth` group.
        $this->delete(route('settings.integrations.persona.unbind-conversation', $persona))
            ->assertRedirect(route('login'));

        $this->assertSame($refs, $persona->fresh()->conversation_refs, 'a guest must not be able to unbind a persona');
        $this->assertSame(0, McpAuditLog::where('method', 'persona/unbind_conversation')->count());
    }

    /**
     * End-to-end proof of the whole loop and the Mayor's core requirement:
     * unbind is the ONLY sanctioned path to a re-capture. Bind → admin unbind →
     * a real allowlist-gated inbound turn re-establishes the binding to the NEW
     * conversation. The write-once `whereNull` capture guard matches again only
     * BECAUSE the unbind returned conversation_refs to null.
     */
    public function test_after_unbind_the_next_allowlisted_inbound_recaptures(): void
    {
        $staff = User::factory()->create();
        $operator = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);
        Setting::setValue('teams_operator_allowlist_user_ids', json_encode([$operator->id]));

        $persona = $this->makePersona([
            'conversation_id' => 'conv-OLD',
            'service_url' => 'https://smba.trafficmanager.net/old/',
        ]);

        // 1) Admin unbind.
        $this->actingAs($staff)
            ->delete(route('settings.integrations.persona.unbind-conversation', $persona))
            ->assertRedirect(route('settings.integrations'));
        $this->assertNull($persona->fresh()->conversation_refs, 'unbind must clear the old binding');

        // 2) The allowlisted operator DMs the persona's bot — a real aud-verified,
        //    serviceUrl-pinned first-contact turn over /api/teams/messages.
        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        [$priv, $jwk] = $this->keypair('recapture-kid');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, $persona->bot_app_id, $serviceUrl, 'recapture-kid');
        $activity = $this->activity($persona->bot_app_id, $persona->tenant_id, 'aad-charlie', 'conv-NEW', $serviceUrl);

        $this->sendActivity($jwt, $activity)->assertOk();

        // 3) Re-captured to the NEW conversation — the rebind completed with zero
        //    manual ref entry, exactly as the write-once design intends.
        $this->assertSame(
            ['conversation_id' => 'conv-NEW', 'service_url' => $serviceUrl],
            $persona->fresh()->conversation_refs,
            'after an unbind, the next allowlisted contact must re-establish the operator lane',
        );
        $this->assertSame(1, OperatorInbox::count(), 'the re-capturing turn lands in the persona operator lane');
    }

    public function test_roster_shows_reset_control_and_binding_only_for_bound_personas(): void
    {
        $user = User::factory()->create();

        $bound = $this->makePersona(
            ['conversation_id' => 'conv-visible', 'service_url' => 'https://smba.trafficmanager.net/amer/'],
            ['persona_key' => 'gus', 'display_name' => 'Gus', 'bot_app_id' => 'gus-app'],
        );
        $unbound = $this->makePersona(null, [
            'persona_key' => 'dormant-one', 'display_name' => 'Dormant One', 'bot_app_id' => 'dormant-app',
        ]);

        $response = $this->actingAs($user)->get(route('settings.integrations'));
        $response->assertOk();

        // Bound persona → the reset control (a form posting to ITS unbind route) is shown.
        $response->assertSee(route('settings.integrations.persona.unbind-conversation', $bound), false);
        $response->assertSee('Reset conversation binding');

        // Unbound persona → shown as "Not bound", with NO reset control targeting it.
        $response->assertSee('Not bound');
        $response->assertDontSee(route('settings.integrations.persona.unbind-conversation', $unbound), false);

        // The roster must still never leak the client secret.
        $response->assertDontSee('persona-secret');
    }

    // ── Bot Framework inbound machinery (mirrors PersonaConversationCaptureTest) ──

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

    /** @return array<string, mixed> */
    private function activity(string $appId, ?string $tenantId, string $aadObjectId, string $conversationId, string $serviceUrl, string $text = 'hello'): array
    {
        return [
            'type' => 'message',
            'text' => $text,
            'recipient' => ['id' => $appId],
            'channelData' => ['tenant' => ['id' => $tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Charlie'],
            'conversation' => ['id' => $conversationId],
            'serviceUrl' => $serviceUrl,
        ];
    }

    private function sendActivity(string $jwt, array $activity)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$jwt])
            ->postJson('/api/teams/messages', $activity);
    }
}
