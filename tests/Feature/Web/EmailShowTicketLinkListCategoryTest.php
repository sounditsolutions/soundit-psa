<?php

namespace Tests\Feature\Web;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-717bn.9: the email detail "Or link to an open ticket" list shows up to
 * 10 of the client's open tickets so a tech can attach the email to the right
 * one. Each row must show the ticket's ITIL category via the shared
 * <x-ticket-category-badge> (leaf in the row, full path on hover) — the epic
 * goal "everywhere that lists a ticket". Null-safe; retired nodes preserved;
 * ancestor chain eager-loaded so pathString() never N+1s across rows.
 * Subjects deliberately avoid the category words so the assertions prove the
 * badge rendered, not the subject.
 */
class EmailShowTicketLinkListCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function tree(): TicketCategory
    {
        $root = TicketCategory::create(['name' => 'Security & EDR']);
        $mid = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $root->id]);

        return TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $mid->id]);
    }

    private function retiredNode(): TicketCategory
    {
        return TicketCategory::create(['name' => 'Legacy Bucket', 'is_active' => false]);
    }

    /** An unlinked (no ticket_id) inbound email for $client, so emails/show renders the link list. */
    private function unlinkedEmailFor(Client $client): Email
    {
        return Email::create([
            'direction' => 'inbound',
            'from_address' => 'alice@acme.test',
            'to_address' => 'support@msp.test',
            'subject' => 'Printer is offline again',
            'body' => 'The main office printer stopped responding this morning.',
            'client_id' => $client->id,
            'received_at' => now(),
        ]);
    }

    public function test_email_link_list_rows_show_category(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Machine acting strange',
            'category_id' => $this->tree()->id,
        ]);
        $email = $this->unlinkedEmailFor($client);

        $resp = $this->actingAs($this->staff())->get(route('emails.show', $email))->assertOk();
        $resp->assertSee('Fake-AV popup');
        $resp->assertSee('Security &amp; EDR / Scareware / Fake-AV popup', false);
    }

    public function test_email_link_list_is_null_safe_and_preserves_retired(): void
    {
        $client = Client::factory()->create();
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'category_id' => null,
        ]);
        Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Old-style request',
            'category_id' => $this->retiredNode()->id,
        ]);
        $email = $this->unlinkedEmailFor($client);

        $this->actingAs($this->staff())->get(route('emails.show', $email))
            ->assertOk()
            ->assertSee('Legacy Bucket')
            ->assertSee('retired');
    }

    public function test_email_link_list_category_path_is_not_n_plus_one(): void
    {
        $client = Client::factory()->create();
        // Several tickets on the same depth-3 node; the ancestor walk must
        // resolve from the eager-loaded chain, not one query per row.
        Ticket::factory()->count(4)->for($client)->create([
            'status' => TicketStatus::InProgress,
            'category_id' => $this->tree()->id,
        ]);
        $email = $this->unlinkedEmailFor($client);

        DB::enableQueryLog();
        $this->actingAs($this->staff())->get(route('emails.show', $email))->assertOk();
        $categoryQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'ticket_categories'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $categoryQueries, "Email link-list category path is N+1 across rows ({$categoryQueries} ticket_categories queries)");
    }
}
