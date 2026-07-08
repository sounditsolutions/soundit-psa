<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Jobs\MineTicketKnowledge;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ResolveButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_button_opens_a_modal_that_captures_a_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));
        $resp->assertOk();
        $resp->assertSee('data-bs-target="#resolveModal"', false);   // Resolve now opens a modal
        $resp->assertSee('id="resolveModal"', false);
        $resp->assertSee('name="resolution"', false);                // the modal captures a resolution
        // the modal posts a resolve to the status endpoint
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);
    }

    public function test_resolving_with_a_resolution_fires_mining(): void
    {
        // wiki + auto-mine on so TicketObserver dispatches mining
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
        Bus::fake();
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution' => null]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => 'Replaced the failing NIC and re-seated the cable.',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertSame('Replaced the failing NIC and re-seated the cable.', $ticket->resolution);
        Bus::assertDispatched(MineTicketKnowledge::class);   // the gold-path mining fires
    }
}
