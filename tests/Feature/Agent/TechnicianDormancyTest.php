<?php

namespace Tests\Feature\Agent;

use App\Enums\CallDirection;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\TechnicianAgent;
use App\Services\Agent\TechnicianAgentSurface;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-hbbuq — the AI Technician's situation drill-downs must be UNRUNNABLE when
 * the operator's flag is off, not merely unpublished.
 *
 * THE DEFECT THESE TESTS PIN.
 *
 * TriageToolDefinitions::readTools() gates the three drill-downs on
 * AgentConfig::situationContextEnabled(), so with the flag off the model is never
 * OFFERED them. TechnicianAgentToolExecutor listed the same three in its READ_TOOLS
 * const UNCONDITIONALLY — the flag appeared nowhere in it. AiClient::runToolLoop
 * dispatches whatever tool NAME the model returns without checking it against the
 * schema it sent, so all three RAN at the default flag setting:
 * get_client_security_posture returned mfa_gaps, external_forwards,
 * inactive_accounts, open_device_alerts and mail_security for a capability the
 * operator had switched off.
 *
 * Not-offered is not not-callable when dispatch is by name. TechnicianAgent.php
 * fences the ticket body as untrusted client text (correctly — it is), so a prompt
 * injection naming one of these tools is a realistic trigger: the model does not
 * need a tool in its schema to emit its name.
 *
 * WHY THESE DRIVE TechnicianAgent::run() AND NOT THE EXECUTOR DIRECTLY.
 *
 * The defect lives in the SEAM between the publisher and the runner, not in either
 * one alone — each was self-consistent. A test that asks the publisher what it
 * publishes, or the executor what it runs, cannot see a disagreement between them.
 * So every case here captures the schema actually handed to AiClient AND the
 * executor closure actually handed alongside it, from one real run() call, and
 * compares the two.
 *
 * Assertions are made AFTER run() returns, never inside the runToolLoop callback:
 * run() is fail-soft and its try/catch would swallow an AssertionFailedError
 * (Error → Throwable), turning a red test green.
 */
class TechnicianDormancyTest extends TestCase
{
    use RefreshDatabase;

    /** The three flag-gated situation drill-downs. */
    private const SITUATION_TOOLS = [
        'list_client_tickets',
        'list_client_calls',
        'get_client_security_posture',
    ];

    /** What the agent's tool fence returns for anything it will not run. */
    private const REFUSAL = ['error' => 'tool not available to the agent'];

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(); // AI actor fallback
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key'); // AiConfig::isConfigured() → true
    }

    /**
     * A ticket whose client carries enough data that a drill-down which RUNS
     * returns visible content — so "it ran" is proved by what came back, not
     * merely by the absence of an error key.
     */
    private function ticketWithSituationData(): Ticket
    {
        $client = Client::factory()->create();

        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);

        // A sibling ticket for list_client_tickets to find.
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'SIBLING-TICKET-SUBJECT',
        ]);

        // A call for list_client_calls to find.
        $call = new PhoneCall([
            'call_uuid' => (string) str()->uuid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+10000000000',
            'call_summary' => 'SIBLING-CALL-SUMMARY',
        ]);
        $call->client_id = $client->id;
        $call->started_at = now()->subHour();
        $call->save();

        return $ticket;
    }

    /**
     * Run the agent once against a mocked AiClient, having the "model" call every
     * situation drill-down by name in its first round.
     *
     * @return array{names: list<string>, results: array<string, mixed>}
     *                                                                   names   — the tool names actually published to the model
     *                                                                   results — each drill-down's name → what the executor returned for it
     */
    private function driveAgent(Ticket $ticket): array
    {
        $publishedNames = [];
        $results = [];

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runToolLoop')
            ->once()
            ->andReturnUsing(function ($system, $user, $tools, $executor) use (&$publishedNames, &$results): AiResponse {
                $publishedNames = array_column($tools, 'name');

                // The model emits the names regardless of what it was offered —
                // exactly what a prompt injection in the fenced ticket body would do.
                foreach (self::SITUATION_TOOLS as $tool) {
                    $results[$tool] = $executor($tool, []);
                }

                return new AiResponse(text: '', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
            });

        (new TechnicianAgent($ai))->run($ticket);

        return ['names' => $publishedNames, 'results' => $results];
    }

    // ── The defect: flag OFF must mean unpublished AND unrunnable ─────────────

    /**
     * RED before the fix: all three were absent from the published schema and
     * still RAN, returning live client data.
     */
    public function test_situation_drill_downs_are_neither_published_nor_runnable_when_the_flag_is_off(): void
    {
        // agent_situation_context_enabled is unset — the default, and the shipped posture.
        $this->assertFalse(
            \App\Support\AgentConfig::situationContextEnabled(),
            'Precondition: the situation-context flag must be off by default.',
        );

        $captured = $this->driveAgent($this->ticketWithSituationData());

        foreach (self::SITUATION_TOOLS as $tool) {
            $this->assertNotContains(
                $tool, $captured['names'],
                "Flag OFF: '{$tool}' must not be published to the model.",
            );

            $this->assertSame(
                self::REFUSAL, $captured['results'][$tool],
                "Flag OFF: '{$tool}' was not published, so calling it by name must be REFUSED. ".
                'Not-offered is not not-callable — dispatch is by name.',
            );
        }
    }

    /**
     * The specific payload the executed proof recovered. Pinned by name so a
     * regression cannot hide behind a shape change: with the flag off, the
     * security posture must not come back at all.
     */
    public function test_security_posture_returns_no_client_data_when_the_flag_is_off(): void
    {
        $captured = $this->driveAgent($this->ticketWithSituationData());

        $result = $captured['results']['get_client_security_posture'];

        foreach (['mfa_gaps', 'external_forwards', 'inactive_accounts', 'open_device_alerts', 'mail_security'] as $key) {
            $this->assertArrayNotHasKey(
                $key, $result,
                "Flag OFF: the posture read must not return '{$key}' — the operator switched this capability off.",
            );
        }
    }

    // ── The control: flag ON must mean published AND runnable ────────────────

    /**
     * Without this, the test above could pass vacuously — a fence that refuses
     * everything would satisfy it. With the flag on, all three must be published
     * AND actually return their data.
     */
    public function test_situation_drill_downs_are_both_published_and_runnable_when_the_flag_is_on(): void
    {
        Setting::setValue('agent_situation_context_enabled', '1');

        $captured = $this->driveAgent($this->ticketWithSituationData());

        foreach (self::SITUATION_TOOLS as $tool) {
            $this->assertContains(
                $tool, $captured['names'],
                "Flag ON: '{$tool}' must be published to the model.",
            );

            $this->assertNotSame(
                self::REFUSAL, $captured['results'][$tool],
                "Flag ON: '{$tool}' was published, so it must also RUN — the fence must not over-block.",
            );
        }

        // Each one actually returned its data, not just a non-refusal.
        $this->assertStringContainsString(
            'SIBLING-TICKET-SUBJECT', (string) json_encode($captured['results']['list_client_tickets']),
            'Flag ON: list_client_tickets must return the client\'s sibling ticket.',
        );
        $this->assertStringContainsString(
            'SIBLING-CALL-SUMMARY', (string) json_encode($captured['results']['list_client_calls']),
            'Flag ON: list_client_calls must return the client\'s call.',
        );
        $this->assertArrayHasKey(
            'mfa_gaps', $captured['results']['get_client_security_posture'],
            'Flag ON: get_client_security_posture must return the posture.',
        );
    }

    // ── The two conjuncts, pinned on the surface itself ──────────────────────

    /**
     * Derivation: a name the executor CAN route is still refused when this turn did
     * not publish it. The flag-off case above exercises this through the real
     * publisher; this one asks the question directly, so the property survives even
     * if the flag or the publisher changes shape.
     */
    public function test_a_dispatchable_tool_is_refused_when_the_turn_did_not_publish_it(): void
    {
        $ticket = $this->ticketWithSituationData();

        // Publish nothing at all — every dispatchable name must now be unrunnable.
        $surface = TechnicianAgentSurface::of([], $this->rawExecutor($ticket));
        $run = $surface->executor();

        foreach (['search_tickets', 'get_ticket_notes', 'propose_close', ...self::SITUATION_TOOLS] as $tool) {
            $this->assertFalse($surface->allows($tool), "Unpublished '{$tool}' must not be allowed.");
            $this->assertSame(
                self::REFUSAL, $run($tool, []),
                "Unpublished '{$tool}' must be refused — the runnable set is derived from the published one.",
            );
        }
    }

    /**
     * Dispatchability: publication alone is not enough. Hand the surface a schema
     * that publishes a mutator and assert it is STILL refused.
     *
     * Without this, the conjunct would be untestable through the real publisher —
     * which emits no mutators — so the two conditions would coincide and the check
     * could rot into a comment unnoticed. It is pinned by mutation instead.
     */
    public function test_a_published_mutator_is_still_refused(): void
    {
        $ticket = $this->ticketWithSituationData();
        $originalStatus = $ticket->status;

        $surface = TechnicianAgentSurface::of(
            [['name' => 'set_ticket_status', 'description' => 'x', 'input_schema' => []]],
            $this->rawExecutor($ticket),
        );

        $this->assertFalse(
            $surface->allows('set_ticket_status'),
            'A mutator must never become runnable merely by appearing in the schema.',
        );
        $this->assertSame(self::REFUSAL, ($surface->executor())('set_ticket_status', ['status' => 'closed']));
        $this->assertSame($originalStatus, $ticket->fresh()->status, 'The ticket must be untouched.');
    }

    private function rawExecutor(Ticket $ticket): \App\Services\Agent\TechnicianAgentToolExecutor
    {
        return new \App\Services\Agent\TechnicianAgentToolExecutor(
            $ticket,
            app(\App\Services\Agent\ProposeCloseTool::class),
            app(\App\Services\Agent\FlagAttentionTool::class),
            app(\App\Services\Agent\SendReplyTool::class),
            app(\App\Services\Agent\RequestToolTool::class),
        );
    }
}
