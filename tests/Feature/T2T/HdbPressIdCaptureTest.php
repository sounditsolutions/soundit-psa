<?php

namespace Tests\Feature\T2T;

use App\Enums\TicketSource;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\T2T\T2TService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 1a/1b of GitHub #340: the press id is the single key for every later
 * report fetch, and it lives on the NOTE that carries the link — not on the
 * ticket around it. Parsed and persisted at the inbound write (addNoteFromCw)
 * and backfilled for the notes already in prod. No fetching happens here — that
 * is slice 2 and needs a credential that does not exist yet.
 */
class HdbPressIdCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '780d16b2-76f4-4931-837b-c2917fb8db9a';

    private const OTHER_UUID = '2f9c1a04-8b1e-4d77-9a3c-55e0b6d21f88';

    private function noteBody(string $uuid): string
    {
        return "A HelpDesk Button ticket was submitted.\n"
            ."View report: https://beta.helpdeskbuttons.com/pressView.php?pressID={$uuid}\n"
            ."Connect to user: https://beta.helpdeskbuttons.com/connect?pressID={$uuid}";
    }

    private function buttonTicket(): Ticket
    {
        return Ticket::factory()->create(['source' => TicketSource::HelpdeskButton->value]);
    }

    private function pressIdOfNote(int $noteId): ?string
    {
        return TicketNote::withTrashed()->whereKey($noteId)->value('hdb_press_id');
    }

    /**
     * The identity the PSA captures as — the configured T2T system user, the
     * author T2TController hands addNoteFromCw and the only authorship the
     * backfill may key (GitHub #1359).
     */
    private function captureIdentity(): User
    {
        $user = User::factory()->create();
        Setting::setValue('t2t_system_user_id', (string) $user->id);

        return $user;
    }

    public function test_inbound_note_persists_the_press_id_on_the_note_and_caches_it_on_the_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        $result = app(T2TService::class)->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);

        $this->assertSame(self::UUID, $this->pressIdOfNote($result['id']));
        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_note_without_a_press_id_leaves_both_columns_null_and_is_not_an_error(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        $result = app(T2TService::class)->addNoteFromCw($ticket, 'Customer called back, all good.', true, $user->id);

        $this->assertNull($this->pressIdOfNote($result['id']));
        $this->assertNull($ticket->fresh()->hdb_press_id);
        // The note itself must still be written — capture is fail-soft, never a gate.
        $this->assertNotNull($result['id']);
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_second_different_press_keeps_both_and_refuses_nothing(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();
        $service = app(T2TService::class);

        $first = $service->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);
        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);

        $second = $service->addNoteFromCw($ticket->fresh(), $this->noteBody(self::OTHER_UUID), true, $user->id);

        // This is the case the ticket-keyed version called a CONFLICT and
        // punished by clearing the column (GitHub #1330 — unrecoverable through
        // the app). A ticket holding two presses is a normal ticket holding two
        // presses: a merge brings the notes with it. Each note keeps its own key
        // and neither report is lost.
        $this->assertSame(self::UUID, $this->pressIdOfNote($first['id']));
        $this->assertSame(self::OTHER_UUID, $this->pressIdOfNote($second['id']));
        $this->assertSame(2, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_the_ticket_column_caches_the_newest_press_by_construction(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();
        $service = app(T2TService::class);

        $service->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);
        $service->addNoteFromCw($ticket->fresh(), $this->noteBody(self::OTHER_UUID), true, $user->id);

        // Written from the note write, so "newest" needs no win-rule to decide
        // it — and is never cleared.
        $this->assertSame(self::OTHER_UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_capture_is_not_gated_on_a_stale_in_memory_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();
        $service = app(T2TService::class);

        // Deliberately NOT re-hydrated: this is the instance a concurrent request
        // is holding, still reading hdb_press_id = null while the row underneath
        // it gets stamped. The keyed write must not consult that stale value.
        $stale = Ticket::find($ticket->id);

        $service->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);
        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);
        $this->assertNull($stale->hdb_press_id);

        $second = $service->addNoteFromCw($stale, $this->noteBody(self::OTHER_UUID), true, $user->id);

        $this->assertSame(self::OTHER_UUID, $this->pressIdOfNote($second['id']));
        $this->assertSame(self::OTHER_UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_capture_adds_no_timestamp_churn_beyond_the_note_write_itself(): void
    {
        $user = User::factory()->create();
        $pressTicket = $this->buttonTicket();
        $plainTicket = $this->buttonTicket();
        $service = app(T2TService::class);

        Ticket::whereKey($pressTicket->id)->toBase()->update(['updated_at' => now()->subDays(30)]);
        Ticket::whereKey($plainTicket->id)->toBase()->update(['updated_at' => now()->subDays(30)]);

        $service->addNoteFromCw($pressTicket->fresh(), $this->noteBody(self::UUID), true, $user->id);
        $service->addNoteFromCw($plainTicket->fresh(), 'Customer called back, all good.', true, $user->id);

        // An inbound note DOES touch its ticket — TicketService::addNote() calls
        // $ticket->touch() by design, so latest activity is visible. What must not
        // happen is capture adding churn of its own on top: a press note and a
        // plain note leave the ticket in the same timestamp state (GitHub #1317
        // is about mass churn on history, not about a live note arriving).
        $this->assertSame(self::UUID, $pressTicket->fresh()->hdb_press_id);
        $this->assertEquals(
            $plainTicket->fresh()->updated_at->startOfSecond(),
            $pressTicket->fresh()->updated_at->startOfSecond(),
        );
    }

    public function test_press_id_is_not_mass_assignable_on_either_table(): void
    {
        // Same reasoning as category_source: the press id is stamped by the
        // system from the vendor's own note, so no mass-assignment path (request
        // input, tool input) may forge it. Asserted through fill(), which honours
        // $fillable — factories deliberately bypass it and would prove nothing.
        $ticket = $this->buttonTicket();

        $ticket->fill(['hdb_press_id' => self::UUID, 'subject' => 'Changed']);

        $this->assertNull($ticket->hdb_press_id);
        $this->assertSame('Changed', $ticket->subject);

        $note = new TicketNote;
        $note->fill(['hdb_press_id' => self::UUID, 'body' => 'Changed']);

        $this->assertNull($note->hdb_press_id);
        $this->assertSame('Changed', $note->body);
    }

    public function test_backfill_keys_notes_regardless_of_ticket_source(): void
    {
        $user = $this->captureIdentity();
        $buttonTicket = $this->buttonTicket();
        // 50 of the 95 prod tickets carrying a press note are NOT
        // source=helpdesk_button — the notes arrive by merge and the source does
        // not follow. The old ticket-scoped filter missed every one of them.
        $mergedTicket = Ticket::factory()->create(['source' => TicketSource::Email->value]);
        $noPress = $this->buttonTicket();

        $onButton = TicketNote::create([
            'ticket_id' => $buttonTicket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        $onMerged = TicketNote::create([
            'ticket_id' => $mergedTicket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::OTHER_UUID),
            'is_private' => true,
        ]);
        $plain = TicketNote::create([
            'ticket_id' => $noPress->id,
            'author_id' => $user->id,
            'body' => 'No report on this one.',
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertSame(self::UUID, $this->pressIdOfNote($onButton->id));
        $this->assertSame(self::OTHER_UUID, $this->pressIdOfNote($onMerged->id));
        $this->assertNull($this->pressIdOfNote($plain->id));

        $this->assertSame(self::UUID, $buttonTicket->fresh()->hdb_press_id);
        $this->assertSame(self::OTHER_UUID, $mergedTicket->fresh()->hdb_press_id);
        $this->assertNull($noPress->fresh()->hdb_press_id);
    }

    public function test_backfill_keeps_both_presses_on_a_ticket_that_holds_two(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $first = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        $second = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::OTHER_UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        // The ticket-scoped pass REFUSED this shape — 7 of 45 tickets on prod,
        // a 12% refusal rate against a spec written off a sample where the case
        // never appeared. Nothing is refused now.
        $this->assertSame(self::UUID, $this->pressIdOfNote($first->id));
        $this->assertSame(self::OTHER_UUID, $this->pressIdOfNote($second->id));
        $this->assertSame(self::OTHER_UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_does_not_demote_a_ticket_that_already_holds_a_newer_press(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        // Legacy note, pre-dates capture: still unkeyed, and the OLDER press.
        $legacy = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        // A press that arrived through the vendor after the migration: the note
        // is already keyed, so the backfill's whereNull scan cannot see it.
        $newer = app(T2TService::class)->addNoteFromCw($ticket->fresh(), $this->noteBody(self::OTHER_UUID), true, $user->id);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertSame(self::UUID, $this->pressIdOfNote($legacy->id));
        $this->assertSame(self::OTHER_UUID, $this->pressIdOfNote($newer['id']));
        // The cache must still name the newest press on the ticket, not the older
        // legacy one this run happened to key.
        $this->assertSame(self::OTHER_UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_leaves_a_soft_deleted_notes_press_id_out_of_the_ticket(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $deleted = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        $deleted->delete();

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        // GitHub #1313: a remediated bad link must not poison the ticket. Under
        // the note model it cannot — a deleted note's key is scoped to the
        // deleted note, and the backfill never reads one.
        $this->assertNull($this->pressIdOfNote($deleted->id));
        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_does_not_touch_timestamps(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        Ticket::whereKey($ticket->id)->toBase()->update(['updated_at' => now()->subDays(30)]);
        TicketNote::whereKey($note->id)->toBase()->update(['updated_at' => now()->subDays(30)]);
        $ticketBefore = $ticket->fresh()->updated_at;
        $noteBefore = TicketNote::whereKey($note->id)->value('updated_at');

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        // #1317's shape, by construction: keyed query-builder updates on both
        // tables, so the 105-row backfill jumps nothing to the top of a list.
        $this->assertSame(self::UUID, $this->pressIdOfNote($note->id));
        $this->assertEquals($ticketBefore, $ticket->fresh()->updated_at);
        $this->assertEquals($noteBefore, TicketNote::whereKey($note->id)->value('updated_at'));
    }

    public function test_backfill_skips_a_press_id_mention_that_is_not_an_hdb_url(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => 'Customer quoted a link from elsewhere: https://example.test/x?pressID='.self::UUID,
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        // The LIKE '%pressID=%' is a prefilter for the index, never the contract.
        $this->assertNull($this->pressIdOfNote($note->id));
        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($this->pressIdOfNote($note->id));
        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_never_keys_a_note_the_psa_did_not_write(): void
    {
        // GitHub #1359. The note key is the authority the report-fetch gate
        // reads, so this command may only key notes the PSA itself wrote: a
        // technician pasting a report link — or an end user quoting one in a
        // portal reply, which lands with no author at all — must not mint an
        // authority for the press behind it.
        $this->captureIdentity();
        $technician = User::factory()->create();
        $ticket = $this->buttonTicket();

        $pasted = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $technician->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        $quoted = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'body' => $this->noteBody(self::OTHER_UUID),
            'is_private' => false,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertNull($this->pressIdOfNote($pasted->id));
        $this->assertNull($this->pressIdOfNote($quoted->id));
        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_refuses_to_run_when_no_capture_identity_is_configured(): void
    {
        // T2TConfig::systemUserId() falls back to the FIRST user — a real
        // technician's account in any deployment that never configured one. A
        // run that keyed against a guessed identity would be the same defect as
        // one that keyed against none, so it refuses rather than guesses.
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(1);

        // The integrations form stores an empty string when the select is
        // cleared, so that shape is unconfigured too.
        Setting::setValue('t2t_system_user_id', '');
        $this->artisan('hdb:backfill-press-ids')->assertExitCode(1);

        $this->assertNull($this->pressIdOfNote($note->id));
        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_window_is_frozen_at_its_first_run_even_when_that_run_is_a_dry_run(): void
    {
        // GitHub #1359. The configured capture identity is an ordinary staff
        // login — under the form's "Auto (first admin user)" default it is the
        // account prod's legacy notes were written as, and in a single-tech
        // deployment the account the technician works under — so authorship is
        // not proof of capture. The window is: a note that did not exist when
        // this command first ran is never keyed by it, whoever authored it.
        //
        // The first run here is a --dry-run, because the mark is the boundary
        // of the population the command may see, not a result of writing.
        $user = $this->captureIdentity();
        $ticket = $this->buttonTicket();

        $legacy = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids', ['--dry-run' => true])->assertExitCode(0);

        $later = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::OTHER_UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertSame(self::UUID, $this->pressIdOfNote($legacy->id));
        $this->assertNull($this->pressIdOfNote($later->id));
        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);
    }
}
