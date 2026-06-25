<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Agent\SignificanceGate;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SignificanceGate — cheap Haiku "worth a look?" filter (Task 6).
 *
 * All tests mock AiClient::complete — no real API calls made.
 *
 * 1. Model says YES → assess() returns true  (worth a look).
 * 2. Model says NO  → assess() returns false (clearly active, skip).
 * 3. Client throws  → assess() returns true  (escalate-when-unsure, never throws).
 * 4. Offline guard  → gate constructed with injected mock; no real HTTP (confirms mockability).
 */
class SignificanceGateTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Open ticket with a client (required by Ticket factory defaults). */
    private function openTicket(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    private function yesResponse(): AiResponse
    {
        return new AiResponse(text: 'YES', inputTokens: 5, outputTokens: 1);
    }

    private function noResponse(): AiResponse
    {
        return new AiResponse(text: 'NO', inputTokens: 5, outputTokens: 1);
    }

    // ── 1. Worth a look → true ───────────────────────────────────────────────

    public function test_worth_a_look_returns_true(): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('complete')->once()->andReturn($this->yesResponse());

        $gate = new SignificanceGate($ai);

        $this->assertTrue($gate->assess($this->openTicket()));
    }

    // ── 2. Clearly active → false ─────────────────────────────────────────────

    public function test_clearly_active_returns_false(): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('complete')->once()->andReturn($this->noResponse());

        $gate = new SignificanceGate($ai);

        $this->assertFalse($gate->assess($this->openTicket()));
    }

    // ── 3. Error → escalate (true, does not throw) ───────────────────────────

    public function test_error_escalates_to_true_and_does_not_throw(): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('complete')->once()->andThrow(new \RuntimeException('API unavailable'));

        $gate = new SignificanceGate($ai);

        // Must not throw; must return true (escalate-when-unsure).
        $this->assertTrue($gate->assess($this->openTicket()));
    }

    // ── 4. Mockable / offline ────────────────────────────────────────────────

    public function test_gate_is_mockable_and_runs_fully_offline(): void
    {
        // Confirms: gate is constructed with an injected AiClient; no real HTTP is made.
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('complete')->once()->andReturn($this->yesResponse());

        $gate = new SignificanceGate($ai);
        $result = $gate->assess($this->openTicket());

        $this->assertIsBool($result);
    }
}
