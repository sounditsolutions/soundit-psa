<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketResolutionDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DraftResolutionEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default: AI is configured (so endpoint gating passes unless a test overrides)
        config(['services.ai.api_key' => 'sk-test-key']);
    }

    protected function tearDown(): void
    {
        // Restore config to a neutral state so config bleed doesn't affect other test classes
        config(['services.ai.api_key' => null]);
        parent::tearDown();
    }

    // ── 1. Drafter returns a string → 200 JSON {resolution: ...} ─────────────

    public function test_returns_resolution_when_drafter_succeeds(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $ticketId = $ticket->id;
        $this->mock(TicketResolutionDrafter::class)
            ->shouldReceive('draft')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $ticketId), 'manual')
            ->andReturn('Replaced the NIC.');

        $resp = $this->actingAs($user)
            ->postJson(route('tickets.draft-resolution', $ticket));

        $resp->assertOk();
        $resp->assertJson(['resolution' => 'Replaced the NIC.']);
    }

    // ── 2. Drafter returns null → friendly non-200 response ──────────────────

    public function test_returns_friendly_message_when_drafter_returns_null(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $ticketId = $ticket->id;
        $this->mock(TicketResolutionDrafter::class)
            ->shouldReceive('draft')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $ticketId), 'manual')
            ->andReturn(null);

        $resp = $this->actingAs($user)
            ->postJson(route('tickets.draft-resolution', $ticket));

        $resp->assertStatus(422);
        $resp->assertJsonStructure(['error']);
    }

    // ── 3. Gating: AI not configured → 422 with error, drafter never called ──

    public function test_returns_error_when_ai_not_configured(): void
    {
        // Override setUp's key — no AI configured
        config(['services.ai.api_key' => null]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->mock(TicketResolutionDrafter::class)
            ->shouldNotReceive('draft');

        $resp = $this->actingAs($user)
            ->postJson(route('tickets.draft-resolution', $ticket));

        $resp->assertStatus(422);
        $resp->assertJson(['error' => 'AI is not configured. Set it up in Settings > Integrations.']);
    }

    // ── 4. Unauthenticated → 401/redirect ────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $resp = $this->postJson(route('tickets.draft-resolution', $ticket));

        $resp->assertUnauthorized();
    }

    // ── 5. View: "Draft with AI" button appears when AI is configured ─────────

    public function test_draft_with_ai_button_shown_in_resolve_modal_when_ai_enabled(): void
    {
        // setUp already sets the key — AI is configured
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        // Check for the button element itself (not the JS variable which is always rendered)
        $resp->assertSee('id="draftResolutionBtn"', false);
        $resp->assertSee(route('tickets.draft-resolution', $ticket), false);
    }

    // ── 6. View: button hidden when AI is NOT configured ─────────────────────

    public function test_draft_with_ai_button_hidden_when_ai_not_configured(): void
    {
        // Override setUp's key — no AI configured
        config(['services.ai.api_key' => null]);

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        // The button element must be absent; the JS variable 'draftResolutionBtn' is always
        // present in the script block but is harmless (the getElementById returns null)
        $resp->assertDontSee('id="draftResolutionBtn"', false);
    }
}
