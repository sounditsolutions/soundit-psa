<?php

namespace Tests\Feature\Emails;

use App\Enums\EmailDirection;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ROXZP7e5 support mailbox: folder scopes, rail count == list total, the
 * agent-set equivalence with list_email_items, the 7-day All mail default, the
 * legacy preset aliases, bulk/single read state, Archive over dismiss, and the
 * open-ticket picker. Spec: design/mockups/emails/mailbox-*.html (r2, 226d0323).
 * Synthetic data only.
 */
class MailboxFoldersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Ticket $ticket;

    /** @var array<string, Email> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        Cache::flush();

        $this->user = User::factory()->create();
        $this->client = Client::factory()->create(['name' => 'Example Dental Co.']);
        $this->ticket = Ticket::factory()->for($this->client)->create(['status' => TicketStatus::InProgress]);
    }

    private function mail(string $key, array $attrs = []): Email
    {
        return $this->rows[$key] = Email::create($attrs + [
            'graph_id' => 'mb-'.$key,
            'direction' => EmailDirection::Inbound,
            'from_address' => $key.'@example.test',
            'from_name' => 'Sender '.$key,
            'subject' => 'Subject '.$key,
            'body_preview' => 'Preview '.$key,
            'received_at' => now()->subHour(),
            'client_id' => $this->client->id,
        ]);
    }

    /**
     * One row per region of the folder lattice, so every folder has rows it
     * must include and rows it must exclude.
     */
    private function seedLattice(): void
    {
        $this->mail('inbox_client');                                                    // Inbox
        $this->mail('inbox_noclient', ['client_id' => null]);                            // Inbox + Inbox: no client
        $this->mail('inbound_filed', ['ticket_id' => $this->ticket->id]);               // Filed
        $this->mail('inbound_archived', ['dismissed_at' => now()]);                     // Archive
        $this->mail('inbound_filed_archived', ['ticket_id' => $this->ticket->id, 'dismissed_at' => now()]); // Filed + Archive
        $this->mail('out_plain', ['direction' => EmailDirection::Outbound]);            // Sent
        $this->mail('out_linked', ['direction' => EmailDirection::Outbound, 'ticket_id' => $this->ticket->id]); // Sent, NOT Filed
        $this->mail('out_archived_noticket', ['direction' => EmailDirection::Outbound, 'dismissed_at' => now(), 'client_id' => null]); // Sent + Archive
        $this->mail('old_inbox', ['received_at' => now()->subDays(20)]);                // Inbox, outside All mail 7 days
        $this->mail('old_sent', ['direction' => EmailDirection::Outbound, 'received_at' => now()->subDays(30)]); // Sent, outside 7 days
    }

    /** @return list<string> keys of the rows the /emails list shows for $query */
    private function listed(array $query): array
    {
        $resp = $this->actingAs($this->user)->get(route('emails.index', $query))->assertOk();
        $paginator = $resp->viewData('emails');
        $byId = collect($this->rows)->mapWithKeys(fn (Email $e, $k) => [$e->id => $k]);

        $keys = collect($paginator->items())->map(fn (Email $e) => $byId[$e->id])->sort()->values()->all();
        $this->assertSame(count($keys), $paginator->total(), 'fixture fits on one page');

        return $keys;
    }

    private function counts(): array
    {
        return $this->actingAs($this->user)->get(route('emails.index'))->assertOk()->viewData('folderCounts');
    }

    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    public function test_inbox_is_inbound_unlinked_unarchived(): void
    {
        $this->seedLattice();
        $this->assertSame(self::sorted(['inbox_client', 'inbox_noclient', 'old_inbox']), $this->listed(['preset' => 'inbox']));
    }

    public function test_default_view_is_the_inbox(): void
    {
        $this->seedLattice();
        $this->actingAs($this->user)->get(route('emails.index'))->assertOk()->assertViewHas('folder', 'inbox');
        $this->assertSame(self::sorted(['inbox_client', 'inbox_noclient', 'old_inbox']), $this->listed([]));
    }

    public function test_filed_is_inbound_linked_only_and_excludes_outbound_linked_mail(): void
    {
        $this->seedLattice();
        $filed = $this->listed(['preset' => 'filed']);
        $this->assertSame(self::sorted(['inbound_filed', 'inbound_filed_archived']), $filed);
        $this->assertNotContains('out_linked', $filed);
    }

    public function test_archive_is_every_dismissed_message(): void
    {
        $this->seedLattice();
        $this->assertSame(self::sorted(['inbound_archived', 'inbound_filed_archived', 'out_archived_noticket']), $this->listed(['preset' => 'archive']));
    }

    public function test_sent_is_every_outbound_message(): void
    {
        $this->seedLattice();
        $this->assertSame(self::sorted(['out_plain', 'out_linked', 'out_archived_noticket', 'old_sent']), $this->listed(['preset' => 'sent']));
    }

    public function test_all_mail_defaults_to_seven_days_with_its_header_label(): void
    {
        $this->seedLattice();
        $this->assertSame(self::sorted(['inbox_client', 'inbox_noclient', 'inbound_filed', 'inbound_archived', 'inbound_filed_archived', 'out_plain', 'out_linked', 'out_archived_noticket']),
            $this->listed(['preset' => 'all']));

        $this->actingAs($this->user)->get(route('emails.index', ['preset' => 'all']))
            ->assertOk()
            ->assertViewHas('defaultWindow', true)
            ->assertSee('last 7 days; search or set a date for older');
    }

    public function test_a_date_or_search_lifts_the_all_mail_window(): void
    {
        $this->seedLattice();
        $dated = $this->listed(['preset' => 'all', 'date_from' => now()->subDays(60)->toDateString()]);
        $this->assertContains('old_inbox', $dated);
        $this->assertContains('old_sent', $dated);
        $this->assertCount(10, $dated);

        $this->assertSame(['old_sent'], $this->listed(['preset' => 'all', 'search' => 'old_sent']));

        // The rail's All mail count is always the 7-day count, so its label stays; the
        // list header label (a second occurrence) goes once a search lifts the window.
        $label = 'last 7 days; search or set a date for older';
        $lifted = $this->actingAs($this->user)->get(route('emails.index', ['preset' => 'all', 'search' => 'old_sent']))
            ->assertViewHas('defaultWindow', false)->getContent();
        $windowed = $this->actingAs($this->user)->get(route('emails.index', ['preset' => 'all']))->getContent();
        $this->assertSame(2, substr_count($windowed, $label), 'rail + list header');
        $this->assertSame(1, substr_count($lifted, $label), 'rail only');
    }

    public function test_inbox_no_client_is_inbox_messages_with_no_client(): void
    {
        $this->seedLattice();
        $this->assertSame(['inbox_noclient'], $this->listed(['preset' => 'inbox', 'no_client' => 1]));
    }

    public function test_each_rail_count_equals_its_list_total(): void
    {
        $this->seedLattice();
        $counts = $this->counts();

        $map = [
            'inbox' => ['preset' => 'inbox'],
            'filed' => ['preset' => 'filed'],
            'archive' => ['preset' => 'archive'],
            'sent' => ['preset' => 'sent'],
            'all' => ['preset' => 'all'],
            'no_client' => ['preset' => 'inbox', 'no_client' => 1],
        ];
        foreach ($map as $folder => $query) {
            $total = $this->actingAs($this->user)->get(route('emails.index', $query))->viewData('emails')->total();
            $this->assertGreaterThan(0, $total, "{$folder}: fixture must populate the folder");
            $this->assertSame($total, $counts[$folder], "{$folder}: rail count != list total");
        }
        $this->assertSame(['inbox' => 3, 'filed' => 2, 'archive' => 3, 'sent' => 4, 'all' => 8, 'no_client' => 1], $counts);
    }

    public function test_rail_counts_are_one_query_and_cached(): void
    {
        $this->seedLattice();
        $service = app(EmailService::class);

        \DB::enableQueryLog();
        $first = $service->folderCounts();
        $queries = count(\DB::getQueryLog());
        $second = $service->folderCounts();
        $after = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(1, $queries, 'folder counts must be one grouped query');
        $this->assertSame($queries, $after, 'second read must come from cache');
        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(60, EmailService::FOLDER_COUNTS_TTL);
    }

    public function test_inbox_set_equals_the_agent_set_from_list_email_items(): void
    {
        $this->seedLattice();

        $agent = (new AssistantToolExecutor)->execute('list_email_items', ['direction' => 'inbound', 'unlinked' => true, 'limit' => 50]);
        $this->assertArrayHasKey('email_items', $agent);
        $agentIds = collect($agent['email_items'])->whereNull('dismissed_at')->pluck('id')->sort()->values()->all();

        $listIds = collect($this->listed(['preset' => 'inbox']))->map(fn ($k) => $this->rows[$k]->id)->sort()->values()->all();

        $this->assertNotEmpty($agentIds);
        $this->assertSame($agentIds, $listIds);
        $this->assertSame(count($agentIds), $this->counts()['inbox']);
        // The raw agent set (before the dismissed_at filter) also holds archived inbound mail.
        $this->assertGreaterThan(count($agentIds), count($agent['email_items']));
    }

    public function test_legacy_preset_values_alias_to_folders(): void
    {
        $this->seedLattice();
        $this->assertSame($this->listed(['preset' => 'inbox']), $this->listed(['preset' => 'needs_attention']));
        $this->assertSame($this->listed(['preset' => 'archive']), $this->listed(['preset' => 'dismissed']));
        $this->assertSame($this->listed(['preset' => 'sent']), $this->listed(['preset' => 'outbound']));
        $this->assertSame(self::sorted(['inbox_client', 'inbox_noclient', 'inbound_filed', 'inbound_archived', 'inbound_filed_archived', 'old_inbox']),
            $this->listed(['preset' => 'inbound']));

        $this->actingAs($this->user)->get(route('emails.index', ['preset' => 'dismissed']))->assertViewHas('folder', 'archive');
        $this->actingAs($this->user)->get(route('emails.index', ['preset' => 'needs_attention']))->assertViewHas('folder', 'inbox');
    }

    public function test_rail_and_list_render_folder_names_and_footer(): void
    {
        $this->seedLattice();
        $this->actingAs($this->user)->get(route('emails.index'))
            ->assertOk()
            ->assertSeeInOrder(['Compose', 'Inbox', 'Filed', 'Archive', 'Sent', 'All mail', 'Inbox: no client'])
            ->assertSee('Inbound mail with no ticket that has not been archived.')
            ->assertSee('3 messages.')
            ->assertSee('Archive and Create ticket remove a message from Inbox; it then appears in Archive or Filed.')
            ->assertSee('No client')
            ->assertDontSee('Dismiss');
    }

    public function test_bulk_mark_read_and_mark_unread(): void
    {
        $a = $this->mail('a', ['is_read' => false]);
        $b = $this->mail('b', ['is_read' => false]);
        $c = $this->mail('c', ['is_read' => true]);

        $this->actingAs($this->user)->post(route('emails.bulk-action'), ['action' => 'mark_read', 'email_ids' => [$a->id, $b->id]])
            ->assertRedirect(route('emails.index'))
            ->assertSessionHas('success', '2 email(s) marked read.');
        $this->assertTrue($a->fresh()->is_read);
        $this->assertTrue($b->fresh()->is_read);

        $this->actingAs($this->user)->post(route('emails.bulk-action'), ['action' => 'mark_unread', 'email_ids' => [$a->id, $c->id]])
            ->assertSessionHas('success', '2 email(s) marked unread.');
        $this->assertFalse($a->fresh()->is_read);
        $this->assertFalse($c->fresh()->is_read);
        $this->assertTrue($b->fresh()->is_read, 'unselected row untouched');
    }

    public function test_single_mark_unread_from_pane_returns_to_the_folder(): void
    {
        $e = $this->mail('single', ['is_read' => false]);

        // Opening the pane marks it read, as the show page does.
        $this->actingAs($this->user)->get(route('emails.show', ['email' => $e, 'pane' => 1, 'return_to' => '/emails?preset=inbox']))
            ->assertOk()
            ->assertSee('Mark unread')
            ->assertSee('Create ticket')
            ->assertSee('Add to ticket')
            ->assertSee('Reply')
            ->assertSee('Archive')
            ->assertDontSee('<html', false);
        $this->assertTrue($e->fresh()->is_read);

        $this->actingAs($this->user)->post(route('emails.bulk-action'), [
            'action' => 'mark_unread', 'email_ids' => [$e->id], 'return_to' => '/emails?preset=inbox',
        ])->assertRedirect('/emails?preset=inbox');
        $this->assertFalse($e->fresh()->is_read);
    }

    public function test_return_to_refuses_an_off_site_target(): void
    {
        $e = $this->mail('offsite');
        $this->actingAs($this->user)->post(route('emails.bulk-action'), [
            'action' => 'mark_read', 'email_ids' => [$e->id], 'return_to' => '//evil.example/emails',
        ])->assertRedirect(route('emails.index'));
        $this->actingAs($this->user)->post(route('emails.bulk-action'), [
            'action' => 'mark_read', 'email_ids' => [$e->id], 'return_to' => '/emailsx',
        ])->assertRedirect(route('emails.index'));
    }

    public function test_archive_posts_to_dismiss_and_sets_dismissed_at(): void
    {
        $e = $this->mail('arch');
        $this->actingAs($this->user)->get(route('emails.show', ['email' => $e, 'pane' => 1]))
            ->assertSee(route('emails.dismiss', $e), false);

        $this->actingAs($this->user)->from('/emails')->post(route('emails.dismiss', $e))
            ->assertRedirect('/emails')
            ->assertSessionHas('success', 'Email archived.');
        $this->assertNotNull($e->fresh()->dismissed_at);
        $this->assertSame($this->user->id, $e->fresh()->dismissed_by);

        $this->actingAs($this->user)->get(route('emails.show', ['email' => $e, 'pane' => 1]))
            ->assertSee('Unarchive')
            ->assertSee(route('emails.undismiss', $e), false);
        $this->actingAs($this->user)->from('/emails')->post(route('emails.undismiss', $e))
            ->assertSessionHas('success', 'Email moved out of Archive.');
        $this->assertNull($e->fresh()->dismissed_at);
    }

    public function test_bulk_archive_uses_dismiss_and_refreshes_counts(): void
    {
        $e = $this->mail('bulkarch');
        $this->assertSame(1, $this->counts()['inbox']);

        $this->actingAs($this->user)->post(route('emails.bulk-action'), ['action' => 'dismiss', 'email_ids' => [$e->id]])
            ->assertSessionHas('success', '1 email(s) archived.');
        $this->assertNotNull($e->fresh()->dismissed_at);
        $this->assertSame(0, $this->counts()['inbox']);
        $this->assertSame(1, $this->counts()['archive']);
    }

    public function test_picker_lists_open_tickets_only_client_first(): void
    {
        $other = Client::factory()->create(['name' => 'Sample Logistics LLC']);
        $mineOpen = $this->ticket; // InProgress, this client
        $mineClosed = Ticket::factory()->for($this->client)->create(['status' => TicketStatus::Closed]);
        $mineResolved = Ticket::factory()->for($this->client)->create(['status' => TicketStatus::Resolved]);
        $otherOpen = Ticket::factory()->for($other)->create(['status' => TicketStatus::New]);
        $otherClosed = Ticket::factory()->for($other)->create(['status' => TicketStatus::Closed]);
        // Make the other client's ticket the most recently updated so client-first ordering is what puts ours on top.
        $otherOpen->forceFill(['updated_at' => now()->addMinute()])->save();

        $rows = $this->actingAs($this->user)
            ->getJson(route('api.tickets.search', ['open' => 1, 'client_first' => $this->client->id]))
            ->assertOk()->json();
        $ids = array_column($rows, 'id');

        $this->assertSame([$mineOpen->id, $otherOpen->id], $ids);
        $this->assertNotContains($mineClosed->id, $ids);
        $this->assertNotContains($mineResolved->id, $ids);
        $this->assertNotContains($otherClosed->id, $ids);
        $this->assertSame('Example Dental Co.', $rows[0]['client_name']);

        // Without the picker params the endpoint is unchanged: closed tickets still list.
        $all = array_column($this->actingAs($this->user)->getJson(route('api.tickets.search'))->json(), 'id');
        $this->assertContains($mineClosed->id, $all);
    }

    public function test_picker_wiring_on_the_page_uses_the_open_filter(): void
    {
        $this->mail('wire');
        $this->actingAs($this->user)->get(route('emails.index'))
            ->assertOk()
            ->assertSee('/api/tickets/search?open=1&q=', false)
            ->assertSee('client_first', false);
    }
}
