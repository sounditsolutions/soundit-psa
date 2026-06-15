<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReopenTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_resolved_ticket_show_page_has_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);
        $resp->assertSee('value="in_progress"', false);
        $resp->assertSee('Reopen');
    }

    public function test_open_ticket_does_not_show_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertDontSee('Reopen');
    }

    public function test_closed_ticket_show_page_has_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);
        $resp->assertSee('value="in_progress"', false);
        $resp->assertSee('Reopen');
    }

    public function test_reopening_preserves_the_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::InProgress->value,
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertSame('Replaced the failing NIC.', $ticket->resolution);
    }

    public function test_reopened_ticket_with_resolution_renders_prior_resolution_card(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('Prior resolution (ticket reopened)');
        $resp->assertSee('border-secondary', false);
        $resp->assertDontSee('border-success', false);
    }

    public function test_resolved_ticket_with_resolution_renders_resolution_card(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('Resolution');
        $resp->assertSee('border-success', false);
        $resp->assertDontSee('border-secondary', false);
    }
}
