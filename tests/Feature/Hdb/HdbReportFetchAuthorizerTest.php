<?php

namespace Tests\Feature\Hdb;

use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Hdb\HdbReportFetchAuthorization;
use App\Services\Hdb\HdbReportFetchAuthorizer;
use App\Services\Hdb\HdbReportFetchRefusal;
use App\Services\T2T\T2TService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The synthetic acceptance cases for GitHub #1359 — "neither an arbitrary
 * pasted press link nor the ticket-level cache may authorize a cross-client
 * report fetch".
 *
 * SYNTHETIC is the point. Every fixture here is built in the test: no historical
 * backfill is run, no production row is read, no HDB credential exists and no
 * request leaves the box. The cases are the ones a real fetch has to survive,
 * expressed against data this test creates, so the gate can be verified before
 * the client that will call it is written and before the portal login is proven.
 */
class HdbReportFetchAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private const PRESS_A = '780d16b2-76f4-4931-837b-c2917fb8db9a';

    private const PRESS_B = '2f9c1a04-8b1e-4d77-9a3c-55e0b6d21f88';

    private function authorizer(): HdbReportFetchAuthorizer
    {
        return app(HdbReportFetchAuthorizer::class);
    }

    private function ticketFor(?Client $client = null): Ticket
    {
        return Ticket::factory()->create([
            'source' => TicketSource::HelpdeskButton->value,
            'client_id' => ($client ?? Client::factory()->create())->id,
        ]);
    }

    /**
     * A note carrying a press id, keyed the way capture keys it.
     *
     * hdb_press_id is deliberately NOT fillable — it is stamped by the system
     * from the vendor's own note and must not be forgeable through mass
     * assignment — so the fixture writes it the same way T2TService does, with
     * a keyed base-query update.
     */
    private function keyedNote(Ticket $ticket, string $pressId): TicketNote
    {
        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::System,
            'body' => "View report: https://beta.helpdeskbuttons.com/pressView.php?pressID={$pressId}",
            'is_private' => true,
            'noted_at' => now(),
        ]);

        TicketNote::whereKey($note->id)->toBase()->update(['hdb_press_id' => $pressId]);

        return $note->fresh();
    }

    /**
     * Write the ticket-level cache, the way capture writes it.
     *
     * Called on its own — with no keyed note behind it — this builds the #1359
     * trap: a ticket that names a press it has no authority for.
     */
    private function cachePressOnTicket(Ticket $ticket, string $pressId): void
    {
        Ticket::whereKey($ticket->id)->toBase()->update(['hdb_press_id' => $pressId]);
    }

    private function assertRefused(
        HdbReportFetchAuthorization $decision,
        HdbReportFetchRefusal $expected,
    ): void {
        $this->assertTrue($decision->refused(), 'Expected a refusal, got an allow.');
        $this->assertFalse($decision->allowed);
        $this->assertSame($expected, $decision->refusal);
        $this->assertSame($expected->value, $decision->reason());

        // A refusal carries a symbol and nothing else: no press id, no note id,
        // no ticket id, no client id. See HdbReportFetchRefusal.
        $this->assertNull($decision->pressId);
        $this->assertNull($decision->noteId);
        $this->assertNull($decision->ticketId);
        $this->assertNull($decision->clientId);
    }

    // ── The allow ──

    public function test_note_keyed_press_on_the_owning_ticket_is_authorized_and_scoped_to_its_client(): void
    {
        $ticket = $this->ticketFor();
        $note = $this->keyedNote($ticket, self::PRESS_A);

        $decision = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->refusal);
        $this->assertSame(self::PRESS_A, $decision->pressId);
        $this->assertSame($note->id, $decision->noteId);
        $this->assertSame($ticket->id, $decision->ticketId);
        $this->assertSame($ticket->client_id, $decision->clientId);
    }

    public function test_the_press_id_is_normalized_so_a_pasted_uppercase_key_matches_the_lowercased_capture(): void
    {
        $ticket = $this->ticketFor();
        $this->keyedNote($ticket, self::PRESS_A);

        $decision = $this->authorizer()->authorize($ticket->id, '  '.strtoupper(self::PRESS_A).'  ');

        $this->assertTrue($decision->allowed);
        $this->assertSame(self::PRESS_A, $decision->pressId);
    }

    public function test_the_note_entry_point_reaches_the_same_decision_as_the_press_id_entry_point(): void
    {
        $ticket = $this->ticketFor();
        $note = $this->keyedNote($ticket, self::PRESS_A);

        $byNote = $this->authorizer()->authorizeNote($ticket->id, $note->id);
        $byPress = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->assertTrue($byNote->allowed);
        $this->assertEquals($byPress, $byNote);
    }

    // ── The refusals #1359 names ──

    public function test_the_same_key_on_another_clients_ticket_is_refused(): void
    {
        $owner = $this->ticketFor();
        $this->keyedNote($owner, self::PRESS_A);

        $otherClientsTicket = $this->ticketFor();

        $decision = $this->authorizer()->authorize($otherClientsTicket->id, self::PRESS_A);

        $this->assertRefused($decision, HdbReportFetchRefusal::NoKeyedNote);
        $this->assertNotSame($owner->client_id, $otherClientsTicket->client_id);
    }

    public function test_a_cross_client_refusal_is_indistinguishable_from_a_press_that_exists_nowhere(): void
    {
        // The refusal must not become an oracle for "this press exists, but not
        // for you" — a technician probing pasted ids must learn nothing about
        // another client's records from the difference between the two answers.
        $this->keyedNote($this->ticketFor(), self::PRESS_A);

        $mine = $this->ticketFor();

        $crossClient = $this->authorizer()->authorize($mine->id, self::PRESS_A);
        $nonExistent = $this->authorizer()->authorize($mine->id, self::PRESS_B);

        $this->assertEquals($nonExistent, $crossClient);
        $this->assertSame($nonExistent->refusal?->label(), $crossClient->refusal?->label());
    }

    public function test_a_pasted_key_with_no_keyed_note_anywhere_is_refused(): void
    {
        $ticket = $this->ticketFor();

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_B),
            HdbReportFetchRefusal::NoKeyedNote,
        );
    }

    public function test_the_ticket_level_cache_alone_never_authorizes_a_fetch(): void
    {
        // tickets.hdb_press_id is a never-cleared, last-note-wins CACHE. If it
        // could authorize, a later note would silently repoint a ticket at
        // another endpoint's diagnostics — which is #1359 itself, and #1358
        // from the other side (the cache is not maintained across a merge).
        $ticket = $this->ticketFor();
        $this->cachePressOnTicket($ticket, self::PRESS_A);

        $this->assertSame(self::PRESS_A, $ticket->fresh()->hdb_press_id);

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_A),
            HdbReportFetchRefusal::NoKeyedNote,
        );
    }

    public function test_a_soft_deleted_keyed_note_no_longer_authorizes_even_while_the_ticket_cache_still_points_at_it(): void
    {
        // #1360. Capture wrote both columns; the technician then removed the
        // note. The cache still names the press, and it still must not count.
        $ticket = $this->ticketFor();
        $note = $this->keyedNote($ticket, self::PRESS_A);
        $this->cachePressOnTicket($ticket, self::PRESS_A);

        $note->delete();

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_A),
            HdbReportFetchRefusal::NoKeyedNote,
        );
        $this->assertRefused(
            $this->authorizer()->authorizeNote($ticket->id, $note->id),
            HdbReportFetchRefusal::NoKeyedNote,
        );

        // The refusal is caused by the soft delete and by nothing else: the row
        // and its key are both still there under withTrashed(). Without this
        // assertion the test would also pass if the fixture had simply failed
        // to key the note.
        $this->assertSame(
            self::PRESS_A,
            TicketNote::withTrashed()->whereKey($note->id)->value('hdb_press_id'),
        );
        $this->assertSame(self::PRESS_A, $ticket->fresh()->hdb_press_id);
    }

    // ── Two presses on one ticket: per-note, and neither wins ──

    public function test_two_notes_with_different_keys_resolve_independently_and_neither_wins(): void
    {
        $ticket = $this->ticketFor();
        $first = $this->keyedNote($ticket, self::PRESS_A);
        $second = $this->keyedNote($ticket, self::PRESS_B);

        // Capture writes the cache from each note write, so after two presses
        // it holds the newer one. Reproduced here rather than assumed.
        $this->cachePressOnTicket($ticket, self::PRESS_B);

        $a = $this->authorizer()->authorize($ticket->id, self::PRESS_A);
        $b = $this->authorizer()->authorize($ticket->id, self::PRESS_B);

        $this->assertTrue($a->allowed);
        $this->assertTrue($b->allowed);
        $this->assertSame($first->id, $a->noteId);
        $this->assertSame($second->id, $b->noteId);

        // The cache holds only the newest, which is exactly why it cannot be
        // the authority: reading it would have lost the first press entirely.
        $this->assertSame(self::PRESS_B, $ticket->fresh()->hdb_press_id);
    }

    public function test_one_key_on_two_notes_of_the_same_ticket_resolves_deterministically_to_the_first(): void
    {
        $ticket = $this->ticketFor();
        $first = $this->keyedNote($ticket, self::PRESS_A);
        $this->keyedNote($ticket, self::PRESS_A);

        $decision = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->assertTrue($decision->allowed);
        $this->assertSame($first->id, $decision->noteId);
    }

    // ── Fail closed on every unknown ──

    public function test_a_value_that_is_not_a_press_id_is_refused_as_malformed(): void
    {
        $ticket = $this->ticketFor();

        foreach ([null, '', 'not-a-uuid', '780d16b2-76f4-4931-837b', self::PRESS_A.'x'] as $offered) {
            $this->assertRefused(
                $this->authorizer()->authorize($ticket->id, $offered),
                HdbReportFetchRefusal::MalformedPressId,
            );
        }
    }

    public function test_a_whole_pasted_link_is_not_a_press_id_and_is_refused(): void
    {
        // Parsing a link is HdbPressId::fromBody()'s job — anchored on a
        // helpdeskbuttons.com URL, never on a bare UUID. The authorizer takes
        // the id only, and refuses anything else rather than parsing leniently.
        $ticket = $this->ticketFor();
        $this->keyedNote($ticket, self::PRESS_A);

        $this->assertRefused(
            $this->authorizer()->authorize(
                $ticket->id,
                'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::PRESS_A,
            ),
            HdbReportFetchRefusal::MalformedPressId,
        );
    }

    public function test_a_missing_or_deleted_ticket_is_refused(): void
    {
        $ticket = $this->ticketFor();
        $this->keyedNote($ticket, self::PRESS_A);
        $ticket->delete();

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_A),
            HdbReportFetchRefusal::TicketMissing,
        );
        $this->assertRefused(
            $this->authorizer()->authorize(null, self::PRESS_A),
            HdbReportFetchRefusal::TicketMissing,
        );
    }

    public function test_a_ticket_with_no_client_cannot_scope_a_fetch(): void
    {
        $ticket = $this->ticketFor();
        $this->keyedNote($ticket, self::PRESS_A);
        Ticket::whereKey($ticket->id)->toBase()->update(['client_id' => null]);

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_A),
            HdbReportFetchRefusal::ClientMissing,
        );
    }

    public function test_an_unkeyed_or_unknown_note_is_refused_through_the_note_entry_point(): void
    {
        $ticket = $this->ticketFor();

        $plain = TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::Note,
            'body' => 'A technician mentioned pressID= in prose and that is not a capture.',
            'noted_at' => now(),
        ]);

        $this->assertRefused($this->authorizer()->authorizeNote($ticket->id, $plain->id), HdbReportFetchRefusal::NoKeyedNote);
        $this->assertRefused($this->authorizer()->authorizeNote($ticket->id, null), HdbReportFetchRefusal::NoKeyedNote);
        $this->assertRefused($this->authorizer()->authorizeNote($ticket->id, $plain->id + 10_000), HdbReportFetchRefusal::NoKeyedNote);

        // No viewing context at all is the same refusal the press-id entry
        // point makes, and it is decided before the note is read.
        $this->assertRefused($this->authorizer()->authorizeNote(null, $plain->id), HdbReportFetchRefusal::TicketMissing);
    }

    public function test_a_keyed_note_on_another_clients_ticket_does_not_self_authorize_through_the_note_entry_point(): void
    {
        // #1359 reached through the note control. The note id is an opaque
        // integer in the request, so the TICKET BEING VIEWED — never the
        // offered note — decides which ticket, and therefore which client, is
        // in play. Enumerating note ids must not walk another client's presses.
        $owner = $this->ticketFor();
        $note = $this->keyedNote($owner, self::PRESS_A);

        $viewed = $this->ticketFor();

        $decision = $this->authorizer()->authorizeNote($viewed->id, $note->id);

        $this->assertRefused($decision, HdbReportFetchRefusal::NoKeyedNote);
        $this->assertNotSame($owner->client_id, $viewed->client_id);

        // And indistinguishable from a note id that exists nowhere: the refusal
        // must not become an oracle for "that note exists, but not for you".
        $this->assertEquals(
            $this->authorizer()->authorizeNote($viewed->id, $note->id + 10_000),
            $decision,
        );
    }

    public function test_a_keyed_note_on_another_ticket_of_the_same_client_is_also_refused(): void
    {
        // The binding is to the ticket, not merely to the client: a note on a
        // sibling ticket is not on the ticket being viewed.
        $client = Client::factory()->create();
        $owner = $this->ticketFor($client);
        $note = $this->keyedNote($owner, self::PRESS_A);

        $viewed = $this->ticketFor($client);

        $this->assertRefused(
            $this->authorizer()->authorizeNote($viewed->id, $note->id),
            HdbReportFetchRefusal::NoKeyedNote,
        );
    }

    // ── The seam this class must not be mistaken for ──

    public function test_the_decision_does_not_depend_on_the_authenticated_user(): void
    {
        // Stated as a test because the failure mode is a caller reading an
        // allow as "this user may see it". The binding is press <-> note <->
        // ticket <-> client; WHO may view the ticket stays the caller's policy.
        $ticket = $this->ticketFor();
        $this->keyedNote($ticket, self::PRESS_A);

        $anonymous = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->actingAs(User::factory()->create());
        $authenticated = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->assertTrue($anonymous->allowed);
        $this->assertEquals($anonymous, $authenticated);
    }

    // ── End to end against the real capture path, no backfill ──

    public function test_a_brand_new_inbound_hdb_note_is_authorized_with_no_backfill_run(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticketFor();

        $body = "A HelpDesk Button ticket was submitted.\n"
            .'View report: https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::PRESS_A."\n"
            .'Connect to user: https://beta.helpdeskbuttons.com/connect?pressID='.self::PRESS_A;

        $result = app(T2TService::class)->addNoteFromCw($ticket, $body, true, $user->id);

        $decision = $this->authorizer()->authorize($ticket->id, self::PRESS_A);

        $this->assertTrue($decision->allowed);
        $this->assertSame($result['id'], $decision->noteId);
        $this->assertSame($ticket->client_id, $decision->clientId);
    }

    public function test_a_note_whose_body_names_two_different_presses_keys_nothing_and_authorizes_nothing(): void
    {
        // HdbPressId::fromBody() returns null when a body disagrees with
        // itself, so capture writes no key — and an unkeyed note authorizes
        // nothing, for either press.
        $user = User::factory()->create();
        $ticket = $this->ticketFor();

        $body = 'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::PRESS_A."\n"
            .'https://beta.helpdeskbuttons.com/connect?pressID='.self::PRESS_B;

        app(T2TService::class)->addNoteFromCw($ticket, $body, true, $user->id);

        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_A),
            HdbReportFetchRefusal::NoKeyedNote,
        );
        $this->assertRefused(
            $this->authorizer()->authorize($ticket->id, self::PRESS_B),
            HdbReportFetchRefusal::NoKeyedNote,
        );
    }

    public function test_a_pasted_link_is_not_promoted_to_authority_by_the_backfill(): void
    {
        // The class's first claim — a pasted key is a locator, never a
        // credential — holds only while the note key means "the PSA captured
        // this". hdb:backfill-press-ids is the column's OTHER writer and is
        // re-runnable, so it is pointed at the capture identity: a technician
        // pasting client A's link onto their own client's ticket keys nothing,
        // and the gate still refuses after the command has run.
        Setting::setValue('t2t_system_user_id', (string) User::factory()->create()->id);
        $technician = User::factory()->create();

        $owner = $this->ticketFor();
        $this->keyedNote($owner, self::PRESS_A);

        $viewed = $this->ticketFor();
        $pasted = TicketNote::create([
            'ticket_id' => $viewed->id,
            'note_type' => NoteType::Note,
            'author_id' => $technician->id,
            'body' => 'Their report: https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::PRESS_A,
            'noted_at' => now(),
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertNull(TicketNote::whereKey($pasted->id)->value('hdb_press_id'));
        $this->assertRefused(
            $this->authorizer()->authorize($viewed->id, self::PRESS_A),
            HdbReportFetchRefusal::NoKeyedNote,
        );
        $this->assertRefused(
            $this->authorizer()->authorizeNote($viewed->id, $pasted->id),
            HdbReportFetchRefusal::NoKeyedNote,
        );
        $this->assertNotSame($owner->client_id, $viewed->client_id);
    }
}
