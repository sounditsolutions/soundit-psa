<?php

namespace Tests\Feature\Tickets;

use App\Models\Asset;
use App\Models\AssistantConversation;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Assistant\AssistantService;
use App\Services\Tactical\TacticalClient;
use App\Services\TicketResolutionDrafter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Integration test: verify the TacticalContextProvider fenced block flows into
 * both the chat system prompt (AssistantService) and the resolution context
 * (TicketResolutionDrafter). Also verifies the block is absent for non-Tactical
 * tickets on both surfaces.
 *
 * Chat surface: the block enters via ContextBuilder::buildForTicket() →
 * buildAssetSection() → provider call (Task 5 wiring). Tested via reflection
 * on buildSystemPrompt() — no live AiClient needed.
 *
 * Resolution surface: the block enters via TicketResolutionDrafter::draft()
 * appending provider output after WikiTicketContext::build() (Task 6 wiring).
 * Tested by capturing the 2nd argument to completeJson() on a mocked AiClient.
 */
class AiTacticalContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Make TacticalConfig::isConfigured() return true so the provider is invoked.
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Seed a ticket whose first linked asset has a Tactical RMM association.
     * The TacticalClient will be bound to the mock responses via bindClient().
     */
    private function tacticalTicket(): Ticket
    {
        $asset = Asset::factory()->create(['hostname' => 'T6-BOX']);
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-T6',
            'hostname' => 'T6-BOX',
            'status' => 'online',
            'checks_failing' => 1,
            'checks_total' => 3,
            'last_seen_at' => now()->subMinutes(2),
            'synced_at' => now()->subMinutes(5),
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $ticket = Ticket::factory()->create(['client_id' => $asset->client_id]);
        $ticket->assets()->attach($asset->id);

        return $ticket->fresh();
    }

    /**
     * Seed a ticket with a plain (non-Tactical) asset — no TacticalAsset row.
     */
    private function nonTacticalTicket(): Ticket
    {
        $asset = Asset::factory()->create(['hostname' => 'PLAIN-BOX']);
        $ticket = Ticket::factory()->create(['client_id' => $asset->client_id]);
        $ticket->assets()->attach($asset->id);

        return $ticket->fresh();
    }

    /**
     * Add a reply note so TicketResolutionDrafter::hasSubstance() passes.
     */
    private function withReplyNote(Ticket $ticket): void
    {
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Tech',
            'body' => 'Rebooted the endpoint and confirmed the issue was resolved.',
            'note_type' => 'reply',
            'noted_at' => now(),
        ]);
    }

    /**
     * Bind TacticalClient to a MockHandler so the provider's live HTTP calls
     * resolve without hitting the network. Matches the pattern in
     * ContextBuilderTacticalChecksTest.
     */
    private function bindClient(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    /** Minimal agent-status payload (getAgent shape). */
    private function agentStatusPayload(): array
    {
        return [
            'status' => 'online',
            'maintenance_mode' => false,
            'logged_in_username' => 'None',
            'needs_reboot' => false,
        ];
    }

    /** One failing check payload (getAgentChecks shape). */
    private function failingChecksPayload(): array
    {
        return [
            [
                'name' => 'Disk Space C',
                'check_result' => ['status' => 'failing', 'stdout' => 'C: at 92%', 'retcode' => 1],
            ],
        ];
    }

    /**
     * Two-response live-read sequence (agent status + checks).
     * The provider always issues both calls when the asset is linked.
     */
    private function liveResponses(): array
    {
        return [
            new Response(200, [], json_encode($this->agentStatusPayload())),
            new Response(200, [], json_encode($this->failingChecksPayload())),
        ];
    }

    // ── Helper: invoke buildSystemPrompt() via reflection ────────────────────

    private function buildSystemPrompt(AssistantConversation $conversation): string
    {
        $method = new ReflectionMethod(AssistantService::class, 'buildSystemPrompt');
        $method->setAccessible(true);

        // psa-uw2o.2: $hasClient is now passed in rather than re-derived inside
        // the method, so the prompt and the tool list cannot disagree. Mirror
        // how sendMessage computes it.
        return $method->invoke(new AssistantService, $conversation, $conversation->resolveClientId() !== null);
    }

    // ── Chat surface ─────────────────────────────────────────────────────────

    /**
     * A ticket conversation whose asset is Tactical-linked: the fenced
     * ENDPOINT TELEMETRY block must appear in the chat system prompt.
     * The block enters via ContextBuilder::buildForTicket() → buildAssetSection()
     * (Task 5 wiring — already in place).
     */
    public function test_chat_prompt_contains_fence_for_tactical_ticket(): void
    {
        $ticket = $this->tacticalTicket();

        // Provider issues 2 HTTP calls: getAgent + getAgentChecks.
        $this->bindClient($this->liveResponses());

        $conversation = AssistantConversation::create([
            'user_id' => User::factory()->create()->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);

        $prompt = $this->buildSystemPrompt($conversation);

        $this->assertStringContainsString('=== ENDPOINT TELEMETRY', $prompt);
        $this->assertStringContainsString('=== END ENDPOINT TELEMETRY', $prompt);
        $this->assertStringContainsString('Failing check: Disk Space C', $prompt);
    }

    /**
     * A non-Tactical ticket: the fenced block must be ABSENT from the chat
     * system prompt.
     */
    public function test_chat_prompt_has_no_fence_for_non_tactical_ticket(): void
    {
        $ticket = $this->nonTacticalTicket();

        // No HTTP calls expected — no Tactical asset → provider returns null.
        $this->bindClient([]);

        $conversation = AssistantConversation::create([
            'user_id' => User::factory()->create()->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);

        $prompt = $this->buildSystemPrompt($conversation);

        $this->assertStringNotContainsString('=== ENDPOINT TELEMETRY', $prompt);
    }

    // ── Resolution surface ───────────────────────────────────────────────────

    /**
     * A ticket with a Tactical-linked asset: the fenced block must appear in
     * the context string passed as the 2nd argument to AiClient::completeJson().
     * This is the Task 6 wiring gap — resolution uses WikiTicketContext::build()
     * which does NOT call buildAssetSection(), so the drafter must explicitly
     * append the provider block.
     */
    public function test_resolution_context_contains_fence_for_tactical_ticket(): void
    {
        $ticket = $this->tacticalTicket();
        $this->withReplyNote($ticket);

        // Provider issues 2 HTTP calls: getAgent + getAgentChecks.
        $this->bindClient($this->liveResponses());

        // Capture the context string passed to completeJson().
        $capturedContext = null;
        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')
            ->once()
            ->withArgs(function (string $system, string $context, int $maxOutputTokens) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(['resolution' => 'Rebooted the endpoint.']);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(900);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(50);

        app(TicketResolutionDrafter::class)->draft($ticket);

        $this->assertNotNull($capturedContext, 'completeJson was not called');
        $this->assertStringContainsString('=== ENDPOINT TELEMETRY', $capturedContext);
        $this->assertStringContainsString('=== END ENDPOINT TELEMETRY', $capturedContext);
        $this->assertStringContainsString('Failing check: Disk Space C', $capturedContext);
    }

    /**
     * A non-Tactical ticket: the fenced block must be ABSENT from the
     * resolution context.
     */
    public function test_resolution_context_has_no_fence_for_non_tactical_ticket(): void
    {
        $ticket = $this->nonTacticalTicket();
        $this->withReplyNote($ticket);

        // No HTTP calls expected.
        $this->bindClient([]);

        $capturedContext = null;
        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')
            ->once()
            ->withArgs(function (string $system, string $context, int $maxOutputTokens) use (&$capturedContext) {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(['resolution' => 'Checked the device; no issues found.']);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(500);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(30);

        app(TicketResolutionDrafter::class)->draft($ticket);

        $this->assertNotNull($capturedContext, 'completeJson was not called');
        $this->assertStringNotContainsString('=== ENDPOINT TELEMETRY', $capturedContext);
    }
}
