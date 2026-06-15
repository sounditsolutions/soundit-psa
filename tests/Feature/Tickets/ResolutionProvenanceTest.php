<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ResolutionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Suppress queued jobs fired by the ticket observer
        Bus::fake();
    }

    public function test_resolution_ai_drafted_casts_to_bool_and_defaults_false(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertIsBool($ticket->resolution_ai_drafted);
        $this->assertFalse($ticket->resolution_ai_drafted);
    }

    public function test_resolving_via_change_status_with_resolution_leaves_ai_drafted_false(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
            'resolution_ai_drafted' => false,
        ]);

        $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => 'Rebooted the router and confirmed connectivity.',
        ]);

        $ticket->refresh();
        $this->assertSame('Rebooted the router and confirmed connectivity.', $ticket->resolution);
        $this->assertFalse($ticket->resolution_ai_drafted);
    }

    public function test_human_resolution_write_clears_ai_drafted_flag(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => 'AI-generated summary here.',
            'resolution_ai_drafted' => true,
        ]);

        // A human writes a resolution via the status endpoint
        $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => 'Human-confirmed fix: replaced the NIC.',
        ]);

        $ticket->refresh();
        $this->assertSame('Human-confirmed fix: replaced the NIC.', $ticket->resolution);
        $this->assertFalse($ticket->resolution_ai_drafted, 'Human resolution must clear the AI-drafted flag');
    }

    public function test_ticket_show_renders_ai_drafted_badge_when_flag_is_true(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'resolution' => 'AI-generated summary of the fix.',
            'resolution_ai_drafted' => true,
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('AI-drafted', false);
        $resp->assertSee('review', false);
    }

    public function test_ticket_show_does_not_render_ai_drafted_badge_when_flag_is_false(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'resolution' => 'Human-written resolution text.',
            'resolution_ai_drafted' => false,
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertDontSee('AI-drafted · review', false);
    }
}
