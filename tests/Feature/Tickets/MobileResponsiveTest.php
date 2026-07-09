<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\SignalDestination;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Mobile responsive fixes:
 *  - psa-i2e7: ticket detail leads with title/description/notes on mobile,
 *    metadata/forms follow (Bootstrap order-* swap, not a source-order change).
 *  - psa-6zs7: wide console tables keep the full table at md+ and fall back to
 *    stacked cards below md so triage signal stays visible without a scroll.
 *  - psa-0h6e: Alerts Hub destinations table keeps the full table at md+ and
 *    falls back to stacked label/value rows below md so Target/Status/Actions
 *    stay reachable on a phone viewport instead of clipping off the right edge.
 */
class MobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_ticket_detail_leads_with_content_on_mobile(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();

        // Content column (subject/description/notes) leads on mobile (order-1);
        // the metadata sidebar (status/info/details/triage) follows (order-2). psa-i2e7.
        $resp->assertSee('col-md-8 order-1 order-md-1', false);
        $resp->assertSee('col-md-4 order-2 order-md-2 detail-sidebar', false);
    }

    public function test_tickets_index_renders_mobile_card_fallback(): void
    {
        $user = User::factory()->create();
        // Open status: the default queue applies open(), which excludes the
        // factory's default Closed status.
        Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        // assignee_id=all mirrors the queue view; ensures the row is not filtered out.
        $resp = $this->actingAs($user)
            ->get(route('tickets.index', ['assignee_id' => 'all']))
            ->assertOk();

        // Desktop: the full table is hidden below md.
        $resp->assertSee('card shadow-sm card-static d-none d-md-block', false);
        // Mobile: the stacked-card fallback container + at least one card render. psa-6zs7.
        $resp->assertSee('d-md-none ticket-cards', false);
        $resp->assertSee('ticket-card', false);
    }

    public function test_alerts_hub_destinations_render_mobile_card_fallback(): void
    {
        $user = User::factory()->create();

        SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd1234',
            'enabled' => true,
        ]);

        $resp = $this->actingAs($user)
            ->get(route('settings.alerts.index'))
            ->assertOk();

        // Desktop: the full destinations table is hidden below md.
        $resp->assertSee('table-responsive d-none d-md-block', false);
        // Mobile: below md the destinations body swaps to stacked label/value rows
        // so Target/Status/Actions stay reachable without a horizontal scroll. psa-0h6e.
        $resp->assertSee('d-md-none', false);
        $resp->assertSee('data-label', false);
        // The destination still renders in the mobile fallback.
        $resp->assertSee('Ops webhook');
    }

    public function test_client_detail_renders_with_responsive_tables(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        // Compile/render smoke for the People + Contracts dual-render edits. psa-6zs7.
        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();
    }
}
