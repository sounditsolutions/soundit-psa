<?php

namespace Tests\Feature\Taxonomy;

use App\Enums\SopStatus;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * so-0ftg Part 3 (psa-id3rt) — applicable_sop delivery on get_ticket_detail.
 * The DETAIL fetch carries the resolved category path plus that node's FULL
 * SOP text; sop_status is an authoring hint that never withholds the text; a
 * missing category or empty SOP degrades to a gap marker naming the fix; and
 * list tools never carry the block.
 */
class ApplicableSopDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function detail(Ticket $ticket): array
    {
        return (new AssistantToolExecutor(clientId: $ticket->client_id))
            ->execute('get_ticket_detail', ['ticket_id' => $ticket->id]);
    }

    /** A depth-3 leaf under Security & EDR / Scareware. */
    private function leaf(array $attributes = []): TicketCategory
    {
        $cat = TicketCategory::create(['name' => 'Security & EDR']);
        $sub = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $cat->id]);

        return TicketCategory::create(array_merge([
            'name' => 'Fake-AV popup',
            'parent_id' => $sub->id,
        ], $attributes));
    }

    public function test_detail_serves_full_path_full_sop_text_status_updated_at_and_edit_link(): void
    {
        // Longer than every truncation cap in getTicketDetail (description 2000,
        // notes 2000) — the tail proves the SOP text arrives FULL, untruncated.
        $sop = str_repeat('Step. ', 600).'SOP_TAIL_MARKER';
        $leaf = $this->leaf(['sop_text' => $sop, 'sop_status' => SopStatus::Draft]);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $block = $this->detail($ticket)['applicable_sop'];

        $this->assertNull($block['gap']);
        $this->assertSame($leaf->id, $block['category_id']);
        $this->assertSame('Security & EDR / Scareware / Fake-AV popup', $block['category_path']);
        $this->assertSame('draft', $block['sop_status'], 'draft is served — status is a hint, not a gate');
        $this->assertSame($sop, $block['sop_text'], 'the FULL sop_text, byte for byte');
        $this->assertStringContainsString('SOP_TAIL_MARKER', $block['sop_text']);
        $this->assertNotNull($block['updated_at']);
        $this->assertStringContainsString("/ticket-categories/{$leaf->id}/edit", $block['edit_url']);
    }

    public function test_sop_status_never_withholds_the_text_even_at_none(): void
    {
        // The strongest form of "hint never gates": text present while the status
        // still says none. Serving keys on hasSop() (text presence) alone.
        $leaf = $this->leaf(['sop_text' => '# Reboot it', 'sop_status' => SopStatus::None]);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $block = $this->detail($ticket)['applicable_sop'];

        $this->assertNull($block['gap']);
        $this->assertSame('# Reboot it', $block['sop_text']);
        $this->assertSame('none', $block['sop_status'], 'the hint is still shown alongside');
    }

    public function test_category_without_sop_text_is_a_gap_with_the_edit_link_to_drive_the_fix(): void
    {
        // Reviewed-with-no-text proves the gap keys on text absence, not status.
        $leaf = $this->leaf(['sop_status' => SopStatus::Reviewed]);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $block = $this->detail($ticket)['applicable_sop'];

        $this->assertSame('no_sop_text', $block['gap']);
        $this->assertNull($block['sop_text']);
        $this->assertSame('Security & EDR / Scareware / Fake-AV popup', $block['category_path'], 'path still resolves so the reader knows WHERE the gap is');
        $this->assertStringContainsString("/ticket-categories/{$leaf->id}/edit", $block['edit_url'], 'the edit link is what drives authoring the missing SOP');
        $this->assertNotSame('', (string) $block['note']);
    }

    public function test_uncategorized_ticket_is_a_gap_not_an_omission(): void
    {
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $result = $this->detail($ticket);

        $this->assertArrayHasKey('applicable_sop', $result, 'the block is always present on detail');
        $this->assertSame('no_category', $result['applicable_sop']['gap']);
        $this->assertNull($result['applicable_sop']['sop_text']);
        $this->assertNull($result['applicable_sop']['category_path']);
    }

    public function test_retired_category_still_serves_its_sop_for_tickets_pointing_at_it(): void
    {
        // Retiring a node stops NEW assignment (a UI concern) — it must not
        // strip guidance from tickets already categorized under it.
        $leaf = $this->leaf(['sop_text' => 'Old but valid steps', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $block = $this->detail($ticket)['applicable_sop'];

        $this->assertNull($block['gap']);
        $this->assertSame('Old but valid steps', $block['sop_text']);
    }

    public function test_edit_link_follows_the_named_route_registered_by_the_taxonomy_ui(): void
    {
        // Pre-UI, this test registered the name at a decoy path to prove the
        // link prefers a named route over the conventional fallback. Now the
        // CRUD UI (psa-7uynn) registers ticket-categories.edit at boot, and
        // Laravel's name lookup is first-wins (RouteCollection::addLookups and
        // refreshNameLookups both skip names already taken), so a late decoy
        // re-registration can never displace the real route — that proof is
        // unsatisfiable by construction. The durable contract stands instead:
        // edit_url IS the URL the registered editor route generates.
        $leaf = $this->leaf(['sop_text' => 'steps']);
        $ticket = Ticket::factory()->create(['category_id' => $leaf->id]);

        $block = $this->detail($ticket)['applicable_sop'];

        $this->assertTrue(Route::has('ticket-categories.edit'), 'the taxonomy CRUD UI must register the named editor route');
        $this->assertSame(route('ticket-categories.edit', $leaf), $block['edit_url']);
    }

    public function test_list_tools_never_carry_the_applicable_sop_block(): void
    {
        // DETAIL FETCH ONLY is a spec requirement, not an accident: the SOP is
        // a full-text payload and belongs on the by-id read, never on lists.
        $leaf = $this->leaf(['sop_text' => 'steps', 'sop_status' => SopStatus::Reviewed]);
        $client = Client::factory()->create();
        $user = User::factory()->create();
        Ticket::factory()->create([
            'client_id' => $client->id,
            'category_id' => $leaf->id,
            'subject' => 'Fake AV popup on FINANCE-01',
            'status' => TicketStatus::New->value,
            'assignee_id' => $user->id,
        ]);

        $executor = new AssistantToolExecutor(clientId: $client->id, userId: $user->id);

        $lists = [
            'search_all_tickets' => $executor->execute('search_all_tickets', ['query' => 'popup'])['tickets'],
            'list_open_tickets' => $executor->execute('list_open_tickets', [])['tickets'],
            'list_my_tickets' => $executor->execute('list_my_tickets', [])['tickets'],
            'search_tickets' => $executor->execute('search_tickets', ['query' => 'popup'])['tickets'],
        ];

        foreach ($lists as $tool => $rows) {
            $this->assertNotEmpty($rows, "{$tool} must return the seeded ticket for the absence check to mean anything");
            foreach ($rows as $row) {
                $this->assertIsArray($row);
                $this->assertArrayNotHasKey('applicable_sop', $row, "{$tool} must not carry the applicable_sop block");
            }
        }
    }
}
