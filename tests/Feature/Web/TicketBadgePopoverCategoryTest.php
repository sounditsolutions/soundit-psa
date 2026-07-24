<?php

namespace Tests\Feature\Web;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-717bn.4 design call: on the compact <x-ticket-badge> chip (dense
 * contexts — calls/emails index rows, merged/child rows on ticket detail) the
 * ITIL category is popover-only, and it appears ONLY when the caller
 * eager-loaded categoryNode. A caller that did not opt in gets no category
 * line and — critically — no lazy queries. Category names here avoid '&' so
 * the escaped popover attribute can be asserted verbatim.
 */
class TicketBadgePopoverCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Networking']);
        $mid = TicketCategory::create(['name' => 'VPN', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Tunnel drops', 'parent_id' => $mid->id]);
    }

    private function categorizedTicket(): Ticket
    {
        return Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Connection to office fails',
            'category_id' => $this->tree()->id,
        ]);
    }

    // ── component behaviour ──────────────────────────────────────────────────

    public function test_popover_includes_category_path_when_relation_is_loaded(): void
    {
        $ticket = $this->categorizedTicket()->load('categoryNode.parent.parent');

        $html = Blade::render('<x-ticket-badge :ticket="$ticket" />', ['ticket' => $ticket]);

        $this->assertStringContainsString('Category:', $html);
        $this->assertStringContainsString('Networking / VPN / Tunnel drops', $html);
    }

    public function test_popover_marks_a_retired_category(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'category_id' => $retired->id,
        ])->load('categoryNode.parent.parent');

        $html = Blade::render('<x-ticket-badge :ticket="$ticket" />', ['ticket' => $ticket]);

        $this->assertStringContainsString('Legacy Bucket', $html);
        $this->assertStringContainsString('(retired)', $html);
    }

    public function test_popover_omits_category_and_never_lazy_loads_when_not_eager_loaded(): void
    {
        $id = $this->categorizedTicket()->id;
        $ticket = Ticket::query()->find($id); // fresh — categoryNode NOT loaded

        DB::enableQueryLog();
        $html = Blade::render('<x-ticket-badge :ticket="$ticket" />', ['ticket' => $ticket]);
        $categoryQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'ticket_categories'))
            ->count();
        DB::disableQueryLog();

        $this->assertStringNotContainsString('Category:', $html);
        $this->assertSame(0, $categoryQueries, 'compact badge must not lazy-load the category chain');
    }

    // ── surfaces that opted in ───────────────────────────────────────────────

    public function test_calls_index_ticket_chip_carries_category_in_popover(): void
    {
        $ticket = $this->categorizedTicket();
        PhoneCall::create([
            'call_uuid' => 'cat-call-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'to_number' => '+15555550000',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'ticket_id' => $ticket->id,
        ]);

        $this->actingAs(User::factory()->create())->get(route('calls.index'))
            ->assertOk()
            ->assertSee('Networking / VPN / Tunnel drops', false);
    }

    public function test_emails_index_ticket_chip_carries_category_in_popover(): void
    {
        $ticket = $this->categorizedTicket();
        Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'user@example.test',
            'subject' => 'Office connection failing',
            'body_text' => 'It drops every hour.',
            'received_at' => now(),
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
        ]);

        $this->actingAs(User::factory()->create())->get(route('emails.index', ['preset' => 'inbound']))
            ->assertOk()
            ->assertSee('Networking / VPN / Tunnel drops', false);
    }

    public function test_merged_child_chip_on_ticket_detail_carries_category_in_popover(): void
    {
        $client = Client::factory()->create();
        $parent = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::Closed,
            'subject' => 'Duplicate of the office issue',
            'parent_ticket_id' => $parent->id,
            'category_id' => $this->tree()->id,
        ]);

        $this->actingAs(User::factory()->create())->get(route('tickets.show', $parent))
            ->assertOk()
            ->assertSee('Networking / VPN / Tunnel drops', false);
    }
}
