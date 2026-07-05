<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\McpToken;
use App\Models\OperatorInbox;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Agent\Escalation\OperatorDeliveryResult;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Services\EmailService;
use App\Services\Technician\Notify\TeamsNotifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Teams AI-Staff Personas P2 hardening (bd psa-7drx, Task 2 items 5 & 8).
 *
 * item 5 — null-conversation_refs safety: a freshly-registered or hand-seeded
 * persona (see TeamsPersonaScaffoldSeeder — ships with conversation_refs=null,
 * NOT []) must never produce an array-offset warning when the inbound
 * controller or OperatorBridgeToolExecutor reads its conversation/serviceUrl
 * targets, and must behave exactly as "no refs yet" (routedToPersona() false;
 * postToOperator() resolves null targets, which OperatorDelivery::send()'s own
 * guard turns into a safe drop).
 *
 * item 8 — conversation auto-capture: the FIRST inbound activity resolved to
 * an active (credential-complete, enabled) persona with no conversation_refs
 * yet, arriving over the aud-verified + serviceUrl-pinned pipe, WRITES
 * conversation_refs = ['conversation_id' => ..., 'service_url' => <the signed
 * serviceUrl claim>] — so the persona's operator lane self-establishes on
 * first contact without a provisioning wizard. An already-bound persona is
 * NEVER re-captured/overwritten by a later turn, even from a different
 * conversation — see PersonaReplyGateTest for the companion reply-gate
 * behavior once a persona is bound elsewhere.
 */
class PersonaConversationCaptureTest extends TestCase
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

    /** A plain (non-mention) inbound activity. */
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

    // ── item 8: auto-capture on first contact ────────────────────────────────

    public function test_first_inbound_turn_captures_conversation_refs_from_the_pinned_claim(): void
    {
        $persona = $this->makePersona(['conversation_refs' => null]); // seeded-Gus shape

        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        [$priv, $jwk] = $this->keypair('capture-kid-1');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, $persona->bot_app_id, $serviceUrl, 'capture-kid-1');

        $activity = $this->activity($persona->bot_app_id, $persona->tenant_id, 'aad-charlie', 'conv-first-contact', $serviceUrl);

        $this->sendActivity($jwt, $activity)->assertOk();

        $this->assertSame(
            ['conversation_id' => 'conv-first-contact', 'service_url' => $serviceUrl],
            $persona->fresh()->conversation_refs,
        );

        // The interaction with item 3: because capture runs BEFORE the
        // routedToPersona() check, this same first turn is now recognised as the
        // persona's own conversation and lands in its operator lane.
        $this->assertSame(1, OperatorInbox::count());
    }

    public function test_second_inbound_turn_does_not_overwrite_an_already_bound_persona(): void
    {
        $originalRefs = ['conversation_id' => 'conv-Y', 'service_url' => 'https://smba.trafficmanager.net/original/'];
        $persona = $this->makePersona(['conversation_refs' => $originalRefs]);

        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/'; // a DIFFERENT, but still validly pinned, serviceUrl
        [$priv, $jwk] = $this->keypair('capture-kid-2');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, $persona->bot_app_id, $serviceUrl, 'capture-kid-2');

        // A different conversation than the persona's existing binding.
        $activity = $this->activity($persona->bot_app_id, $persona->tenant_id, 'aad-charlie', 'conv-Z', $serviceUrl);

        $this->sendActivity($jwt, $activity)->assertOk();

        $this->assertSame($originalRefs, $persona->fresh()->conversation_refs, 'an existing binding must never be silently replaced by a later turn');
        $this->assertSame(0, OperatorInbox::count(), 'conv-Z is not the personas own conversation (conv-Y) — must not enqueue');
    }

    // ── item 5: null conversation_refs is safe, everywhere it is read ────────

    public function test_null_conversation_refs_is_not_routed_to_persona_and_raises_no_warning(): void
    {
        $persona = $this->makePersona(['conversation_refs' => null]); // seeded-Gus shape

        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // A DELIBERATELY unpinned serviceUrl: the signed claim will not match the
        // activity body, so item-8's auto-capture guard (serviceUrlPinned) never
        // fires. This isolates routedToPersona()'s null-safe read of
        // conversation_refs from item 8 rewriting it away from null mid-request.
        [$priv, $jwk] = $this->keypair('null-refs-kid');
        $this->primeJwks($jwk);
        $jwt = $this->sign($priv, $persona->bot_app_id, 'https://smba.trafficmanager.net/signed/', 'null-refs-kid');
        $activity = $this->activity($persona->bot_app_id, $persona->tenant_id, 'aad-charlie', 'conv-unbound', 'https://smba.trafficmanager.net/mismatched/');

        // No exception/warning aborts the request — a null-offset crash here would
        // surface as a 500, not a clean 200 ack.
        $this->sendActivity($jwt, $activity)->assertOk();

        $this->assertNull($persona->fresh()->conversation_refs, 'capture must not fire when the serviceUrl is not pinned');
        $this->assertSame(0, OperatorInbox::count(), 'null conversation_refs must behave as "no refs" (never a persona-lane match)');
    }

    public function test_post_to_operator_resolves_null_targets_and_drops_for_a_persona_with_null_conversation_refs(): void
    {
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $this->makePersona([
            'mcp_token_label' => 'gus-mcp',
            'conversation_refs' => null, // seeded-Gus shape, NOT [] — the stricter null case
        ]);

        // Neither the bot client nor the legacy webhook may be reached — an
        // enabled persona with unusable (null) targets must fail CLOSED (a drop),
        // exactly like the already-covered empty-array case in PerPersonaOutboundTest.
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        $result = app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello from gus'],
            'gus-mcp',
        );

        $this->assertFalse($result['posted'], 'a null-conversation_refs persona message must report posted=false, not crash or misroute');
    }

    public function test_post_to_operator_reads_null_conversation_refs_targets_without_a_warning(): void
    {
        // A direct, unmocked look at exactly the two lines item 5 hardens
        // (OperatorBridgeToolExecutor :144/:147) via the captured OperatorDelivery
        // arguments — both resolve to null, cleanly, for a null conversation_refs.
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $this->makePersona(['mcp_token_label' => 'gus-mcp', 'conversation_refs' => null]);

        $captured = null;
        $this->mock(OperatorDelivery::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sanitizeMessage')->andReturnUsing(fn (string $msg): string => $msg);
            $m->shouldReceive('send')->once()->andReturnUsing(function (...$args) use (&$captured): OperatorDeliveryResult {
                $captured = $args;

                return new OperatorDeliveryResult(posted: false, postedToChat: false, remoteMessageId: null);
            });
        });

        app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello from gus'],
            'gus-mcp',
        );

        $this->assertNotNull($captured);
        [, $conversationId, $serviceUrl] = $captured;
        $this->assertNull($conversationId);
        $this->assertNull($serviceUrl);
    }
}
