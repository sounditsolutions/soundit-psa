<?php

namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReportingAndBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    // ── Customer-count / KPI ──────────────────────────────────────────────────

    public function test_prospect_does_not_increment_operational_customer_count(): void
    {
        $active = Client::factory()->create(['is_active' => true]);      // stage=Active (default)
        $prospect = Client::factory()->prospect()->create();             // stage=Prospect, is_active=true

        $count = Client::operational()->count();

        $this->assertSame(1, $count, 'Only the Active client should be counted as a customer');
        $this->assertTrue(Client::operational()->pluck('id')->contains($active->id));
        $this->assertFalse(Client::operational()->pluck('id')->contains($prospect->id));
    }

    // ── Client header badge ───────────────────────────────────────────────────

    public function test_client_show_renders_prospect_badge_for_prospect(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();

        $response = $this->actingAs($user)->get(route('clients.show', $prospect));

        $response->assertOk();
        $response->assertSee('Prospect');
    }

    public function test_client_show_does_not_render_prospect_badge_for_active_client(): void
    {
        $user = User::factory()->create();
        $active = Client::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get(route('clients.show', $active));

        $response->assertOk();
        // Badge text "Prospect" should not appear in the header for an active client
        $response->assertDontSee('badge-prospect');
    }

    // ── Ticket header badge ───────────────────────────────────────────────────

    public function test_ticket_show_renders_prospect_badge_when_client_is_prospect(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $response = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('Prospect');
    }

    public function test_ticket_show_does_not_render_prospect_badge_for_active_client_ticket(): void
    {
        $user = User::factory()->create();
        $active = Client::factory()->create(['is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $active->id]);

        $response = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee('badge-prospect');
    }
}
