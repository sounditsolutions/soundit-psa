<?php

namespace Tests\Feature\Assets;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\TicketStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-cmte: the asset detail "Alerts & Tickets" tab keeps its full tables at md+
 * and falls back to stacked rows below md, so the active alert status/actions and
 * the linked ticket status/assignee/updated columns stay readable at a glance
 * without a horizontal scroll on a mobile viewport. Mirrors the in-card responsive
 * pattern used for the client People/Contracts lists (psa-6zs7).
 *
 * The desktop tables and the mobile stacked rows are both server-rendered; only CSS
 * (d-none d-md-block / d-md-none) decides which is shown, so assertSee finds the key
 * data regardless of viewport. The desktop tables carry the d-none d-md-block class,
 * so on a mobile viewport the only rendering of that data is the d-md-none fallback.
 */
class AssetAlertsTicketsMobileResponsiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_alerts_and_linked_tickets_render_mobile_fallback(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();

        Alert::create([
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'source' => AlertSource::Ninja,
            'source_alert_id' => 'alert-mobile-active',
            'severity' => AlertSeverity::Critical,
            'status' => AlertStatus::Active,
            'title' => 'Disk space critical',
            'message' => 'C: drive at 98%',
            'fired_at' => now()->subHour(),
        ]);

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Linked ticket subject',
        ]);
        $asset->tickets()->attach($ticket);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset))->assertOk();

        // Desktop tables are wrapped so they hide below md (psa-cmte).
        $resp->assertSee('table-responsive d-none d-md-block', false);
        // A mobile-only stacked fallback block renders below md.
        $resp->assertSee('d-md-none', false);
        // The previously-clipped active-alert signal rides in the tab.
        $resp->assertSee('Disk space critical');
        // The alert actions stay reachable (resolve action form present).
        $resp->assertSee(route('alerts.resolve', Alert::first()), false);
        // The previously-clipped linked-ticket signal rides in the tab.
        $resp->assertSee('Linked ticket subject');
        $resp->assertSee($ticket->display_id);
    }

    public function test_resolved_alerts_render_mobile_stacked_rows(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create();

        Alert::create([
            'asset_id' => $asset->id,
            'client_id' => $asset->client_id,
            'source' => AlertSource::Ninja,
            'source_alert_id' => 'alert-mobile-resolved',
            'severity' => AlertSeverity::Warning,
            'status' => AlertStatus::Resolved,
            'title' => 'Reboot required',
            'message' => 'Pending updates cleared',
            'fired_at' => now()->subDays(2),
            'resolved_at' => now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->get(route('assets.show', $asset))->assertOk();

        // Desktop resolved table hides below md; the mobile stacked rows carry
        // the Fired/Resolved label/value pairs that only exist in the fallback.
        $resp->assertSee('table-responsive d-none d-md-block', false);
        $resp->assertSee('<span class="data-label">Resolved</span>', false);
        $resp->assertSee('Reboot required');
    }
}
