<?php

namespace Tests\Feature\Clients;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OverviewTicketBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation dispatches RunTriagePipeline via TicketObserver.
        Bus::fake();
    }

    /**
     * The Overview "Tickets (N)" tab badge and the "N open" section badge are
     * derived from a true count — not the 5-row preview collection. A client
     * with more than five open tickets must show its real open count, so the
     * Overview agrees with the dedicated Tickets tab list.
     */
    public function test_overview_open_ticket_badges_show_true_count_beyond_preview_cap(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['is_active' => true]);

        // 9 open tickets — more than the 5-row preview cap.
        Ticket::factory()->count(9)->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress->value,
            'closed_at' => null,
        ]);
        // Closed tickets that must not inflate the open count.
        Ticket::factory()->count(3)->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed->value,
        ]);

        $response = $this->actingAs($user)->get(route('clients.show', $client));

        $response->assertOk();
        // Overview "Recent Tickets" section badge shows the true open count.
        $response->assertSee('9 open');
        // Tab badge shows the true open count.
        $response->assertSee('(9)');
        // Not clamped to the 5-row preview.
        $response->assertDontSee('5 open');
    }

    /**
     * The badge counts only open tickets and is not capped: closed tickets are
     * excluded and the true open total is shown even when the grand total also
     * exceeds the preview cap.
     */
    public function test_overview_open_ticket_badge_excludes_closed_tickets(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['is_active' => true]);

        Ticket::factory()->count(6)->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress->value,
            'closed_at' => null,
        ]);
        Ticket::factory()->count(4)->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed->value,
        ]);

        $response = $this->actingAs($user)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertSee('6 open');   // true open count
        $response->assertDontSee('10 open'); // not total (open + closed)
        $response->assertDontSee('4 open');  // closed not counted as open
    }
}
