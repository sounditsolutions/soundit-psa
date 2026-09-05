<?php

namespace Tests\Feature\T2T;

use App\Enums\TicketSource;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\T2T\T2TService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 1 of GitHub #340: the press id is the single key for every later report
 * fetch, so it is parsed and persisted at the inbound write (addNoteFromCw) and
 * backfilled for the tickets already in prod. No fetching happens here — that is
 * a later slice and needs a credential that does not exist yet.
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

    public function test_inbound_note_persists_the_press_id_on_the_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        app(T2TService::class)->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);

        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_note_without_a_press_id_leaves_the_column_null_and_is_not_an_error(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        $result = app(T2TService::class)->addNoteFromCw($ticket, 'Customer called back, all good.', true, $user->id);

        $this->assertNull($ticket->fresh()->hdb_press_id);
        // The note itself must still be written — capture is fail-soft, never a gate.
        $this->assertNotNull($result['id']);
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_later_conflicting_press_id_refuses_the_ticket_like_the_backfill_does(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();
        $service = app(T2TService::class);

        $service->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);
        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);

        $service->addNoteFromCw($ticket->fresh(), $this->noteBody(self::OTHER_UUID), true, $user->id);

        // Same evidence, same answer as test_backfill_refuses_a_ticket_whose_notes
        // _disagree: two press ids on one ticket is a REFUSAL, not a pick, so the
        // already-stamped id is cleared rather than left keying the ticket to an
        // endpoint we cannot show is the right one. Arrival time must not decide
        // whether a ticket is usable.
        $this->assertNull($ticket->fresh()->hdb_press_id);
        $this->assertSame(2, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_the_same_press_id_repeated_on_a_later_note_is_not_a_conflict(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();
        $service = app(T2TService::class);

        $service->addNoteFromCw($ticket, $this->noteBody(self::UUID), true, $user->id);
        $service->addNoteFromCw($ticket->fresh(), $this->noteBody(self::UUID), true, $user->id);

        $this->assertSame(self::UUID, $ticket->fresh()->hdb_press_id);
    }

    public function test_press_id_is_not_mass_assignable(): void
    {
        // Same reasoning as category_source: the press id is stamped by the
        // system from the vendor's own note, so no mass-assignment path (request
        // input, tool input) may forge it. Asserted through fill(), which honours
        // $fillable — factories deliberately bypass it and would prove nothing.
        $ticket = $this->buttonTicket();

        $ticket->fill(['hdb_press_id' => self::UUID, 'subject' => 'Changed']);

        $this->assertNull($ticket->hdb_press_id);
        $this->assertSame('Changed', $ticket->subject);
    }

    public function test_backfill_sets_press_ids_from_existing_notes(): void
    {
        $user = User::factory()->create();
        $withLink = $this->buttonTicket();
        $withoutLink = $this->buttonTicket();

        TicketNote::create([
            'ticket_id' => $withLink->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        TicketNote::create([
            'ticket_id' => $withoutLink->id,
            'author_id' => $user->id,
            'body' => 'No report on this one.',
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertSame(self::UUID, $withLink->fresh()->hdb_press_id);
        $this->assertNull($withoutLink->fresh()->hdb_press_id);
    }

    public function test_backfill_refuses_a_ticket_whose_notes_disagree(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::OTHER_UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids')->assertExitCode(0);

        $this->assertNull($ticket->fresh()->hdb_press_id);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $user = User::factory()->create();
        $ticket = $this->buttonTicket();

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'body' => $this->noteBody(self::UUID),
            'is_private' => true,
        ]);

        $this->artisan('hdb:backfill-press-ids', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($ticket->fresh()->hdb_press_id);
    }
}
