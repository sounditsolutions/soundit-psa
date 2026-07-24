<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-717bn.5 — the search/typeahead JSON payloads that list tickets must carry
 * the ITIL taxonomy category (category_id + category_path via
 * Ticket::categoryFields()), null-safe, with the ancestor chain eager-loaded:
 * the merge/link typeahead (api.tickets.search), the Ctrl+K omni-search
 * (search.quick), and the softphone call-popup recent tickets (calls.latest).
 */
class SearchTypeaheadCategoryPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    // --- merge/link typeahead (TicketController::apiSearch) ---

    public function test_ticket_api_search_rows_carry_category_fields(): void
    {
        $leaf = $this->tree();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'category_id' => $leaf->id]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.tickets.search'))
            ->assertOk()
            ->assertJsonPath('0.id', $ticket->id)
            ->assertJsonPath('0.category_id', $leaf->id)
            ->assertJsonPath('0.category_path', 'Security & EDR / Scareware / Fake-AV popup');
    }

    public function test_ticket_api_search_is_null_safe_for_uncategorized_ticket(): void
    {
        Ticket::factory()->create(['status' => TicketStatus::InProgress, 'category_id' => null]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.tickets.search'))
            ->assertOk()
            ->assertJsonPath('0.category_id', null)
            ->assertJsonPath('0.category_path', null);
    }

    public function test_ticket_api_search_category_path_is_not_n_plus_one(): void
    {
        $leaf = $this->tree();
        // Several tickets on the same depth-3 node; the ancestor walk must
        // resolve from the eager-loaded chain, not one query per row.
        Ticket::factory()->count(4)->create(['status' => TicketStatus::InProgress, 'category_id' => $leaf->id]);

        DB::enableQueryLog();
        $this->actingAs(User::factory()->create())
            ->getJson(route('api.tickets.search'))
            ->assertOk();
        $categoryQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'ticket_categories'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $categoryQueries, "Category path is N+1 across rows ({$categoryQueries} ticket_categories queries)");
    }

    // --- Ctrl+K omni-search (QuickSearchController) ---

    public function test_quick_search_ticket_rows_carry_category_fields(): void
    {
        $leaf = $this->tree();
        Ticket::factory()->create([
            'subject' => 'Zebra label printer offline',
            'status' => TicketStatus::InProgress,
            'category_id' => $leaf->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('search.quick', ['q' => 'Zebra label']))
            ->assertOk()
            ->assertJsonPath('results.0.type', 'ticket')
            ->assertJsonPath('results.0.category_id', $leaf->id)
            ->assertJsonPath('results.0.category_path', 'Security & EDR / Scareware / Fake-AV popup');
    }

    public function test_quick_search_is_null_safe_for_uncategorized_ticket(): void
    {
        Ticket::factory()->create([
            'subject' => 'Zebra label printer offline',
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('search.quick', ['q' => 'Zebra label']))
            ->assertOk()
            ->assertJsonPath('results.0.type', 'ticket')
            ->assertJsonPath('results.0.category_path', null);
    }

    // --- softphone call-popup recent tickets (CallController::latest) ---

    private function ringingCallFor(Client $client): PhoneCall
    {
        // client_id is not mass-assignable on PhoneCall, so bypass the guard.
        return PhoneCall::forceCreate([
            'call_uuid' => 'uuid-test-'.$client->id,
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'to_number' => '+15555550199',
            'status' => CallStatus::Ringing,
            'started_at' => now(),
            'client_id' => $client->id,
        ]);
    }

    public function test_call_popup_recent_tickets_carry_category_fields(): void
    {
        $leaf = $this->tree();
        $client = Client::factory()->create();
        $this->ringingCallFor($client);
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'category_id' => $leaf->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('calls.latest'))
            ->assertOk()
            ->assertJsonPath('call.recent_tickets.0.category_id', $leaf->id)
            ->assertJsonPath('call.recent_tickets.0.category_path', 'Security & EDR / Scareware / Fake-AV popup');
    }

    public function test_call_popup_recent_tickets_are_null_safe_for_uncategorized_ticket(): void
    {
        $client = Client::factory()->create();
        $this->ringingCallFor($client);
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('calls.latest'))
            ->assertOk()
            ->assertJsonPath('call.recent_tickets.0.category_id', null)
            ->assertJsonPath('call.recent_tickets.0.category_path', null);
    }
}
