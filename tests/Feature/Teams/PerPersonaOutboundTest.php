<?php

namespace Tests\Feature\Teams;

use App\Models\AssistantConversation;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Agent\Escalation\OperatorDeliveryResult;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Services\EmailService;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsBotClient;
use App\Services\Teams\TeamsReplyService;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Teams AI-Staff Personas P1 — per-persona OUTBOUND (bd psa-kh22, Task 3).
 *
 * Task 1 gave us the `teams_personas` registry; Task 2 bound a persona to an
 * INBOUND signed-aud. This file covers the four OUTBOUND coupling clusters: the
 * bot token client (A), OperatorDelivery's bot send (B), the Chet
 * post_to_operator bridge tool (C), and the Teams chat reply loop (D).
 *
 * THE central invariant under test everywhere here: persona is ALWAYS derived
 * SERVER-SIDE from a trusted source (a signed-aud-derived ResolvedSender-
 * >personaKey, or an authenticated McpStaffToken->label) — NEVER from caller-
 * supplied tool input — and a null/absent/unresolvable persona must be
 * byte-identical to the pre-P1 single-bot behavior.
 */
class PerPersonaOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::setValue('teams_bot_app_id', 'legacy-app-id');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant-id');
        TeamsBotConfig::setClientSecret('legacy-secret');
    }

    /** Mirrors TeamsPersonaRegistryTest's fixture shape. */
    private function makePersona(array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create(array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app-id',
            'tenant_id' => 'persona-tenant-id',
            'bot_client_secret' => 'persona-secret',
            'enabled' => true,
        ], $overrides));
    }

    private function fakeAzure(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'BF-TOKEN', 'expires_in' => 3600], 200),
            'smba.trafficmanager.net/*' => Http::response(['id' => 'sent-activity-1'], 201),
        ]);
    }

    // ── Cluster A: TeamsBotClient::forPersona ────────────────────────────────

    public function test_persona_scoped_client_acquires_token_with_persona_credentials(): void
    {
        $this->fakeAzure();
        $persona = $this->makePersona();

        $sent = app(TeamsBotClient::class)->forPersona($persona)
            ->sendMessage('https://smba.trafficmanager.net/amer/', 'conv-1', 'hi from gus');

        $this->assertTrue($sent);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/persona-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'persona-app-id'
            && $req['client_secret'] === 'persona-secret'
            && $req['scope'] === 'https://api.botframework.com/.default');
    }

    public function test_null_persona_client_uses_legacy_credentials_unchanged(): void
    {
        $this->fakeAzure();

        $sent = app(TeamsBotClient::class)->forPersona(null)
            ->sendMessage('https://smba.trafficmanager.net/amer/', 'conv-1', 'hi legacy');

        $this->assertTrue($sent);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/legacy-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'legacy-app-id'
            && $req['client_secret'] === 'legacy-secret');
    }

    public function test_for_persona_does_not_mutate_the_shared_client(): void
    {
        $this->fakeAzure();
        $persona = $this->makePersona();

        $shared = app(TeamsBotClient::class);
        $clone = $shared->forPersona($persona);

        $this->assertNotSame($shared, $clone, 'forPersona() must return a fresh instance, never mutate the shared one.');

        // The clone acquires the PERSONA's token...
        $clone->sendMessage('https://smba.trafficmanager.net/amer/', 'conv-1', 'from gus');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/persona-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'persona-app-id');

        // ...while the ORIGINAL shared (container) instance still resolves LEGACY creds.
        $shared->sendMessage('https://smba.trafficmanager.net/amer/', 'conv-2', 'still legacy');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/legacy-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'legacy-app-id');
    }

    public function test_persona_with_no_secret_is_dormant_safe(): void
    {
        $this->fakeAzure();
        $persona = $this->makePersona(['bot_client_secret' => null]);

        $sent = app(TeamsBotClient::class)->forPersona($persona)
            ->sendMessage('https://smba.trafficmanager.net/amer/', 'conv-1', 'hi');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    // ── Cluster B: OperatorDelivery::send(..., $persona) ─────────────────────

    public function test_operator_delivery_send_routes_through_the_persona_client_even_when_globally_disabled(): void
    {
        $this->fakeAzure();
        Setting::setValue('teams_bot_enabled', '0'); // global toggle OFF — the persona is its own gate.
        $persona = $this->makePersona();

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        $result = app(OperatorDelivery::class)->send(
            null,
            'persona-conv-1',
            'https://smba.trafficmanager.net/amer/',
            'Subject',
            'Body from Gus',
            $persona,
        );

        $this->assertTrue($result->posted);
        $this->assertTrue($result->postedToChat);

        // The bot chunk was posted using the PERSONA's Bot Framework token.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/persona-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'persona-app-id');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'smba.trafficmanager.net/amer/v3/conversations/')
            && $req->hasHeader('Authorization', 'Bearer BF-TOKEN')
            && $req['text'] === 'Body from Gus');
    }

    public function test_operator_delivery_send_without_a_persona_is_still_gated_by_the_global_toggle(): void
    {
        $this->fakeAzure();
        Setting::setValue('teams_bot_enabled', '0'); // global toggle OFF, no persona ⇒ webhook fallback (unchanged).

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        $result = app(OperatorDelivery::class)->send(
            null,
            'legacy-conv-1',
            'https://smba.trafficmanager.net/amer/',
            'Subject',
            'Body legacy',
        );

        $this->assertTrue($result->posted);
        $this->assertFalse($result->postedToChat);
        Http::assertNothingSent(); // no bot HTTP at all — gate closed, fell straight to the webhook.
    }

    // ── Cluster C: OperatorBridgeToolExecutor::postToOperator provenance ─────

    /**
     * Mocks OperatorDelivery wholesale and captures the exact positional args
     * postToOperator() passes to send() — the load-bearing surface for "did the
     * executor resolve the right conversation/serviceUrl/actor/persona", without
     * re-testing OperatorDelivery's own bot-vs-webhook mechanics (Cluster B's job).
     *
     * @param  array<int, mixed>|null  $captured
     */
    private function mockDeliveryCapture(?array &$captured): void
    {
        $this->mock(OperatorDelivery::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sanitizeMessage')->andReturnUsing(fn (string $msg): string => $msg);
            $m->shouldReceive('send')->once()->andReturnUsing(function (...$args) use (&$captured): OperatorDeliveryResult {
                $captured = $args;

                return new OperatorDeliveryResult(posted: true, postedToChat: true, remoteMessageId: null);
            });
        });
    }

    public function test_post_to_operator_targets_the_persona_conversation_and_actor_for_an_enabled_persona_token(): void
    {
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $persona = $this->makePersona([
            'mcp_token_label' => 'gus-mcp',
            'conversation_refs' => [
                'conversation_id' => 'gus-conv-1',
                'service_url' => 'https://smba.trafficmanager.net/gus/',
            ],
        ]);

        $captured = null;
        $this->mockDeliveryCapture($captured);

        $result = app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello from gus'],
            'gus-mcp',
        );

        $this->assertTrue($result['posted']);
        $this->assertNotNull($captured, 'OperatorDelivery::send() must have been called.');

        [, $conversationId, $serviceUrl, $subject, , $sentPersona] = $captured;
        $this->assertSame('gus-conv-1', $conversationId);
        $this->assertSame('https://smba.trafficmanager.net/gus/', $serviceUrl);
        $this->assertStringContainsString('Gus', $subject);
        $this->assertNotNull($sentPersona);
        $this->assertSame($persona->id, $sentPersona->id);
    }

    public function test_post_to_operator_falls_back_to_legacy_targets_when_no_token_label_is_present(): void
    {
        Setting::setValue('teams_chet_conversation_id', 'legacy-chet-conv');
        Setting::setValue('teams_escalation_service_url', 'https://smba.trafficmanager.net/legacy/');
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $this->makePersona([
            'mcp_token_label' => 'gus-mcp',
            'conversation_refs' => [
                'conversation_id' => 'gus-conv-1',
                'service_url' => 'https://smba.trafficmanager.net/gus/',
            ],
        ]);

        $captured = null;
        $this->mockDeliveryCapture($captured);

        app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello legacy'],
            null,
        );

        $this->assertNotNull($captured);
        [, $conversationId, $serviceUrl, , , $sentPersona] = $captured;
        $this->assertSame('legacy-chet-conv', $conversationId);
        $this->assertSame('https://smba.trafficmanager.net/legacy/', $serviceUrl);
        $this->assertNull($sentPersona, 'an absent token label must never resolve a persona');
    }

    public function test_post_to_operator_falls_back_to_legacy_targets_when_the_token_label_is_unknown(): void
    {
        Setting::setValue('teams_chet_conversation_id', 'legacy-chet-conv');
        Setting::setValue('teams_escalation_service_url', 'https://smba.trafficmanager.net/legacy/');
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $this->makePersona([
            'mcp_token_label' => 'gus-mcp',
            'conversation_refs' => [
                'conversation_id' => 'gus-conv-1',
                'service_url' => 'https://smba.trafficmanager.net/gus/',
            ],
        ]);

        $captured = null;
        $this->mockDeliveryCapture($captured);

        app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello legacy'],
            'unknown-label',
        );

        $this->assertNotNull($captured);
        [, $conversationId, $serviceUrl, , , $sentPersona] = $captured;
        $this->assertSame('legacy-chet-conv', $conversationId);
        $this->assertSame('https://smba.trafficmanager.net/legacy/', $serviceUrl);
        $this->assertNull($sentPersona, 'an unknown token label must never resolve a persona — no cross-persona leak');
    }

    public function test_post_to_operator_drops_when_enabled_persona_has_incomplete_conversation_refs(): void
    {
        // An ENABLED persona whose conversation_refs are missing/partial resolves
        // null targets. That must fail CLOSED — a DROP (no-op + log) — never fall
        // through to the shared legacy webhook, which would misroute the persona's
        // display_name + ticket content into an unrelated channel (fail-open).
        $this->fakeAzure();
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);
        $this->makePersona([
            'mcp_token_label' => 'gus-mcp',
            'conversation_refs' => [], // enabled, but NO usable targets
        ]);

        // Neither the bot client (no HTTP) nor the legacy webhook may be reached.
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        $result = app(OperatorBridgeToolExecutor::class)->execute(
            'post_to_operator',
            ['category' => 'reply', 'message' => 'hello from gus'],
            'gus-mcp',
        );

        $this->assertFalse($result['posted'], 'an incompletely-targeted persona message must report posted=false, not misroute');
        Http::assertNothingSent(); // dropped — not sent to the bot AND not misrouted to the webhook
    }

    // ── Cluster D: TeamsReplyService persona routing ─────────────────────────

    private function sender(User $user, ?string $personaKey = null): ResolvedSender
    {
        return new ResolvedSender(
            user: $user,
            appId: 'persona-app-id',
            tenantId: 'persona-tenant-id',
            conversationId: 'a:conv-1',
            serviceUrl: 'https://smba.trafficmanager.net/amer/',
            aadObjectId: 'aad-'.$user->id,
            personaKey: $personaKey,
        );
    }

    public function test_reply_routes_outbound_through_the_persona_client_and_attributes_the_transcript_to_the_persona(): void
    {
        $this->fakeAzure();
        $personaActor = User::factory()->create(['name' => 'Gus Bot']);
        $persona = $this->makePersona(['actor_user_id' => $personaActor->id, 'display_name' => 'Gus']);

        $senderUser = User::factory()->create(['name' => 'Charlie Coutts']);
        $sender = $this->sender($senderUser, $persona->persona_key);

        $ai = $this->mock(AiClient::class);
        $capturedSystem = null;
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedSystem): AiResponse {
                $capturedSystem = $system;

                return new AiResponse(text: 'hi from gus', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
            });

        $service = new TeamsReplyService($ai, app(TeamsBotClient::class));
        $service->reply($sender, 'hello', 'BlueTier IT');

        // The reply went out using the PERSONA's Bot Framework token.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/persona-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'persona-app-id');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'activities') && ($req['text'] ?? null) === 'hi from gus');

        // The transcript is owned by the PERSONA's actor, not the legacy AI actor.
        $conv = AssistantConversation::where('external_key', 'teams:a:conv-1')->first();
        $this->assertNotNull($conv);
        $this->assertSame($personaActor->id, $conv->user_id);

        // The system prompt names the PERSONA, not the legacy actor.
        $this->assertStringContainsString('Gus', (string) $capturedSystem);
    }

    public function test_reply_with_no_persona_key_is_byte_identical_to_the_legacy_global_actor(): void
    {
        $this->fakeAzure();
        $legacyActor = User::factory()->create(['name' => 'Legacy Assistant']);
        Setting::setValue('triage_system_user_id', (string) $legacyActor->id);

        $senderUser = User::factory()->create(['name' => 'Charlie Coutts']);
        $sender = $this->sender($senderUser, null);

        $ai = $this->mock(AiClient::class);
        $capturedSystem = null;
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, ...$rest) use (&$capturedSystem): AiResponse {
                $capturedSystem = $system;

                return new AiResponse(text: 'hi', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
            });

        $service = new TeamsReplyService($ai, app(TeamsBotClient::class));
        $service->reply($sender, 'hello', 'BlueTier IT');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/legacy-tenant-id/oauth2/v2.0/token')
            && $req['client_id'] === 'legacy-app-id');

        $conv = AssistantConversation::where('external_key', 'teams:a:conv-1')->first();
        $this->assertNotNull($conv);
        $this->assertSame($legacyActor->id, $conv->user_id);
        $this->assertStringContainsString('Legacy Assistant', (string) $capturedSystem);
    }
}
