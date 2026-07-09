<?php

namespace Tests\Feature\Tickets;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\TicketStatus;
use App\Models\Alert;
use App\Models\Client;
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
 *  - psa-iqzv: the client Details card locks its table width and wraps long
 *    email/website values so they stay inside the card on a mobile viewport.
 *  - psa-ybif: the two columns split at lg (not md) so the dense right rail
 *    stacks below the content at tablet width (768px) instead of overflowing a
 *    33% column and clipping fields off-screen (matches contracts/show).
 *  - psa-dxie: the operational alerts list keeps the full table at md+ and falls
 *    back to stacked rows below md so the client, status, and response controls
 *    stay visible without a horizontal scroll.
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

        // Content column (subject/description/notes) leads while stacked (order-1);
        // the metadata sidebar (status/info/details/triage) follows (order-2). psa-i2e7.
        // Columns split at lg so the rail stacks below the content at tablet width
        // rather than clipping off-screen in a cramped 33% column. psa-ybif.
        $resp->assertSee('col-lg-8 order-1 order-lg-1', false);
        $resp->assertSee('col-lg-4 order-2 order-lg-2 detail-sidebar', false);

        // Guard against regressing to the md breakpoint, where the side rail sat
        // beside the content at 768px and overflowed the tablet viewport. psa-ybif.
        $resp->assertDontSee('col-md-8 order-1 order-md-1', false);
        $resp->assertDontSee('col-md-4 order-2 order-md-2 detail-sidebar', false);
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

    public function test_alerts_index_renders_mobile_stacked_fallback(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        // Active + open so it survives the index's default open() filter and the
        // acknowledge/create-ticket/resolve controls all render.
        $alert = Alert::create([
            'source' => AlertSource::Tactical,
            'source_alert_id' => 'psa-dxie-1',
            'severity' => AlertSeverity::Critical,
            'status' => AlertStatus::Active,
            'title' => 'Disk space critically low on C:',
            'hostname' => 'WS-FINANCE-04',
            'client_id' => $client->id,
            'fired_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('alerts.index'))->assertOk();

        // Desktop: the full table is hidden below md.
        $resp->assertSee('table-responsive d-none d-md-block', false);
        // Mobile: the stacked-row fallback surfaces the columns the desktop table
        // pushes off-screen — client, source, status — as labelled rows. psa-dxie.
        $resp->assertSee('<span class="data-label">Client</span>', false);
        $resp->assertSee('<span class="data-label">Source</span>', false);
        // The operational context (affected client + alert) is present in the markup.
        $resp->assertSee($client->name, false);
        $resp->assertSee($alert->title, false);
    }

    public function test_client_detail_renders_with_responsive_tables(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        // Compile/render smoke for the People + Contracts dual-render edits. psa-6zs7.
        $this->actingAs($user)->get(route('clients.show', $client))->assertOk();
    }

    public function test_client_detail_card_wraps_long_contact_values_on_mobile(): void
    {
        $user = User::factory()->create();
        // Long, unbreakable email + website — the values that used to push the
        // Details table past the rounded card boundary on a 390px viewport.
        $client = Client::factory()->create([
            'email' => 'accounts.receivable.department@averylongcorporatedomainname.example.com',
            'website' => 'https://www.averylongcorporatedomainname.example.com/about/contact-us',
        ]);

        $resp = $this->actingAs($user)->get(route('clients.show', $client))->assertOk();

        // The long values render in full...
        $resp->assertSee($client->email, false);
        $resp->assertSee($client->website, false);

        // ...inside a Details table that is width-locked (table-layout: fixed) and
        // breaks long tokens (text-break), so they wrap instead of overflowing the
        // rounded card boundary. psa-iqzv.
        $resp->assertSee('table table-borderless mb-0 text-break', false);
        $resp->assertSee('table-layout: fixed', false);
    }
}
