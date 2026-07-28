<?php

namespace Tests\Feature\Web;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn.9: the dashboard "Open Tickets" widget lists tickets through the
 * shared tickets._list partial but passed a $columns allowlist without
 * 'category', so the desktop table hid the ITIL category while the ungated
 * mobile cards showed it. Charlie: "everywhere that lists a ticket should
 * also list its category" — the widget's allowlist must include it. Null-safe;
 * retired nodes preserved. Subjects deliberately avoid the category words so
 * the assertions prove the badge rendered, not the subject.
 */
class DashboardTicketCategoryColumnTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    public function test_dashboard_open_tickets_widget_shows_category_column(): void
    {
        Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Machine acting strange',
            'category_id' => $this->tree()->id,
        ]);

        $resp = $this->actingAs(User::factory()->create())->get('/')->assertOk();
        // The desktop table header is gated on in_array('category', $columns) —
        // exactly what the widget's allowlist previously omitted.
        $resp->assertSee('<th>Category</th>', false);
        $resp->assertSee('Fake-AV popup');
        $resp->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false);
    }

    public function test_dashboard_open_tickets_widget_is_null_safe_and_preserves_retired(): void
    {
        Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);
        Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'category_id' => TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false])->id,
        ]);

        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }
}
