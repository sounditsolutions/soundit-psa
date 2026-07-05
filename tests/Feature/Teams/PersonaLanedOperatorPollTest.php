<?php

namespace Tests\Feature\Teams;

use App\Http\Middleware\VerifyBotFrameworkJwt;
use App\Models\McpToken;
use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Support\TeamsBotConfig;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Teams AI-Staff Personas P1 — persona-laned, token-scoped operator inbox
 * (bd psa-kh22, Task 4 — "the two-panel blocker").
 *
 * THE central invariant under test everywhere here: the persona LANE
 * (operator_inbox.persona) is ALWAYS derived server-side from a trusted
 * source — never a caller/tool/brain-supplied argument. Two trusted sources
 * only: the authenticated McpStaffToken->label (poll side, resolved via
 * TeamsPersonaConfig::byTokenLabel()) and the signed-aud-bound
 * ResolvedSender->personaKey (enqueue side, Task 2). A token must never see
 * or ack another persona's rows, on SELECT or on ACK. No persona (legacy)
 * must remain byte-identical to the pre-P1 single-lane behavior.
 */
class PersonaLanedOperatorPollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── shared fixtures ──────────────────────────────────────────────────────

    /** Mirrors PerPersonaOutboundTest/InboundAudBindingTest's fixture shape. */
    private function makePersona(array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create(array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app-id',
            'tenant_id' => 'persona-tenant-id',
            'enabled' => true,
        ], $overrides));
    }

    private function tokenFor(string $label): McpToken
    {
        return McpToken::create(['label' => $label, 'token_hash' => hash('sha256', $label.'-secret')]);
    }

    private function seedRow(array $overrides = []): OperatorInbox
    {
        return OperatorInbox::create(array_merge([
            'conversation_id' => 'shared-conv',
            'sender_user_id' => null,
            'text' => 'msg',
            'ts' => now(),
            'direct_mention' => false,
            'authorized_steer' => false,
            'delivered_at' => null,
            'persona' => null,
            'kind' => 'human',
            'sender_persona' => null,
        ], $overrides));
    }

    /** Direct executor call — the load-bearing unit for the poll-side lane scoping. */
    private function poll(array $input, ?string $tokenLabel): array
    {
        return app(OperatorBridgeToolExecutor::class)->execute('poll_operator_messages', $input, $tokenLabel);
    }

    // ── Step 1: fail-closed on empty/absent label ────────────────────────────

    public function test_poll_is_token_scoped_and_fails_closed_on_empty_label(): void
    {
        $this->seedRow();

        $withNull = $this->poll([], null);
        $this->assertSame(['error' => 'poll_operator_messages requires a scoped token'], $withNull);

        $withEmpty = $this->poll([], '');
        $this->assertSame(['error' => 'poll_operator_messages requires a scoped token'], $withEmpty);

        // Fail-closed also means nothing was drained or acked.
        $this->assertSame(1, OperatorInbox::whereNull('delivered_at')->count());
    }

    // ── THE blocker test: cross-persona isolation on BOTH SELECT and ACK ─────

    public function test_cross_persona_isolation_select_and_ack(): void
    {
        $this->tokenFor('persona-a-mcp');
        $this->tokenFor('persona-b-mcp');
        $this->makePersona(['persona_key' => 'A', 'bot_app_id' => 'app-a', 'mcp_token_label' => 'persona-a-mcp']);
        $this->makePersona(['persona_key' => 'B', 'bot_app_id' => 'app-b', 'mcp_token_label' => 'persona-b-mcp']);

        // Interleaved ids on purpose: a2's id sits BETWEEN b1's and b2's, so an
        // ack that forgot to lane on `id <= cursor` would sweep b1 up too.
        $a1 = $this->seedRow(['persona' => 'A', 'text' => 'a1']);   // id 1
        $b1 = $this->seedRow(['persona' => 'B', 'text' => 'b1']);   // id 2
        $a2 = $this->seedRow(['persona' => 'A', 'text' => 'a2']);   // id 3
        $b2 = $this->seedRow(['persona' => 'B', 'text' => 'b2']);   // id 4

        // SELECT isolation: A's token sees ONLY A's rows.
        $out = $this->poll([], 'persona-a-mcp');
        $this->assertSame([$a1->id, $a2->id], array_column($out['messages'], 'id'));
        $this->assertSame((string) $a2->id, $out['next_cursor']);

        // ACK isolation: acking with A's own next_cursor (3) must never touch
        // b1 (id 2, id <= 3) even though it shares the same conversation_id.
        $this->poll(['cursor' => $out['next_cursor']], 'persona-a-mcp');

        $this->assertNotNull($a1->fresh()->delivered_at);
        $this->assertNotNull($a2->fresh()->delivered_at);
        $this->assertNull($b1->fresh()->delivered_at, 'a same-conversation cross-persona ack must never mark another persona\'s row delivered');
        $this->assertNull($b2->fresh()->delivered_at);

        // B's own poll still sees its full, un-acked batch — untouched by A's activity.
        $bOut = $this->poll([], 'persona-b-mcp');
        $this->assertSame([$b1->id, $b2->id], array_column($bOut['messages'], 'id'));
    }

    // ── enqueue-side: persona/kind/sender_persona stamped from ResolvedSender ─

    private string $legacyPrivatePem = '';

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
    private function activity(string $recipientAppId, ?string $tenantId, string $aadObjectId, string $conversationId, string $serviceUrl, string $text = 'hello'): array
    {
        return [
            'type' => 'message',
            'text' => $text,
            'recipient' => ['id' => $recipientAppId],
            'channelData' => ['tenant' => ['id' => $tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Charlie'],
            'conversation' => ['id' => $conversationId],
            'serviceUrl' => $serviceUrl,
            'timestamp' => '2026-07-05T10:00:00Z',
        ];
    }

    private function sendActivity(string $jwt, array $activity)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$jwt])
            ->postJson('/api/teams/messages', $activity);
    }

    public function test_enqueue_stamps_persona_kind_sender_persona(): void
    {
        [$priv, $jwk] = $this->keypair('persona-kid');
        $this->primeJwks($jwk);

        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app-id',
            'tenant_id' => 'persona-tenant-id',
            'conversation_refs' => [
                'conversation_id' => 'persona-conv-1',
                'service_url' => 'https://smba.trafficmanager.net/persona/',
            ],
        ]);
        $charlie = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        $jwt = $this->sign($priv, 'persona-app-id', $serviceUrl, 'persona-kid');
        $activity = $this->activity('persona-app-id', 'persona-tenant-id', 'aad-charlie', 'persona-conv-1', $serviceUrl, 'hello gus');

        $this->sendActivity($jwt, $activity)->assertOk();

        $row = OperatorInbox::first();
        $this->assertNotNull($row, 'the persona-routed turn must enqueue an operator_inbox row');
        $this->assertSame('gus', $row->persona);
        $this->assertSame('human', $row->kind);
        $this->assertNull($row->sender_persona);
        $this->assertSame('persona-conv-1', $row->conversation_id);
        $this->assertSame($charlie->id, $row->sender_user_id);
        $this->assertNull($row->delivered_at);

        // The persona-aware enabled-gate: an ENABLED persona routes even though
        // the GLOBAL teams_bot_enabled flag was never turned on.
        $this->assertFalse(TeamsBotConfig::enabled());
    }

    public function test_routed_to_persona_only_matches_the_personas_own_conversation(): void
    {
        [$priv, $jwk] = $this->keypair('persona-kid-2');
        $this->primeJwks($jwk);

        $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app-id',
            'tenant_id' => 'persona-tenant-id',
            'conversation_refs' => [
                'conversation_id' => 'persona-conv-1',
                'service_url' => 'https://smba.trafficmanager.net/persona/',
            ],
        ]);
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        $jwt = $this->sign($priv, 'persona-app-id', $serviceUrl, 'persona-kid-2');

        // Same persona/bot, but a DIFFERENT conversation than its own — must not enqueue.
        $elsewhere = $this->activity('persona-app-id', 'persona-tenant-id', 'aad-charlie', 'some-other-conv', $serviceUrl, 'not for the operator lane');
        $this->sendActivity($jwt, $elsewhere)->assertOk();

        $this->assertSame(0, OperatorInbox::count(), 'a persona activity outside its own conversation_refs conversation must not enqueue');
    }

    // ── legacy lane regression: byte-identical single-lane behavior preserved ─

    public function test_legacy_lane_regression(): void
    {
        [$priv, $jwk] = $this->keypair('legacy-kid');
        $this->primeJwks($jwk);

        Setting::setValue('teams_bot_app_id', 'legacy-app-id');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant-id');
        TeamsBotConfig::setClientSecret('legacy-secret');
        Setting::setValue('teams_bot_enabled', '1');
        Setting::setValue('teams_chet_routing_enabled', '1');
        Setting::setValue('teams_chet_conversation_id', 'legacy-conv-1');
        $charlie = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $serviceUrl = 'https://smba.trafficmanager.net/amer/';
        $jwt = $this->sign($priv, 'legacy-app-id', $serviceUrl, 'legacy-kid');
        $activity = $this->activity('legacy-app-id', 'legacy-tenant-id', 'aad-charlie', 'legacy-conv-1', $serviceUrl, 'legacy chatter');

        $this->sendActivity($jwt, $activity)->assertOk();

        $legacyRow = OperatorInbox::first();
        $this->assertNotNull($legacyRow);
        $this->assertNull($legacyRow->persona, 'a legacy (no-persona) enqueue must write persona=null');
        $this->assertSame('human', $legacyRow->kind);
        $this->assertNull($legacyRow->sender_persona);
        $this->assertSame($charlie->id, $legacyRow->sender_user_id);

        // An unrelated persona-laned row already sitting in the SAME conversation_id —
        // the legacy lane must be scoped by `persona IS NULL`, never by conversation_id.
        $personaRow = $this->seedRow(['persona' => 'gus', 'conversation_id' => 'legacy-conv-1', 'text' => 'persona chatter']);

        // A valid staff token whose label matches NO enabled persona resolves the
        // legacy (null) lane and must drain ONLY the legacy row.
        $out = $this->poll([], 'some-legacy-ops-console-label');
        $this->assertSame([$legacyRow->id], array_column($out['messages'], 'id'));

        // Ack it — this is the write side of the same lane scope.
        $this->poll(['cursor' => $out['next_cursor']], 'some-legacy-ops-console-label');

        $this->assertNotNull($legacyRow->fresh()->delivered_at);
        $this->assertNull($personaRow->fresh()->delivered_at, 'the legacy lane must never drain or ack a persona-laned row');
    }
}
