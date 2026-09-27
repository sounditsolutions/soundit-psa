<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContactIntake\StaffWorkflow;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** r2 must-fix controls for the intake core (card sPMnZ1l4, r1 review 01a0d620). */
class IntakeR2CoreTest extends TestCase
{
    use RefreshDatabase;

    private function accept(string $message = 'SYNTHETIC_UNTRUSTED_TEXT'): ContactSubmission
    {
        Setting::setValue('contact_intake_enabled', '1');
        app(SubmissionLedger::class)->accept('website', [
            'submission_id' => (string) Str::uuid(), 'name' => 'Synthetic Visitor',
            'email' => 'visitor@example.test', 'message' => $message,
            'submitted_at' => '2026-09-24T12:00:00Z',
        ]);

        return ContactSubmission::latest('id')->firstOrFail();
    }

    /** diff:1 — two inquiries accepted before one drain run: one prospect, TWO tickets. */
    public function test_two_submissions_accepted_before_one_drain_get_separate_tickets_on_one_prospect(): void
    {
        Bus::fake();
        $first = $this->accept('FIRST');
        $second = $this->accept('SECOND');
        $this->artisan('contact-intake:drain')->assertSuccessful();
        $first->refresh();
        $second->refresh();
        $this->assertSame('processed', $first->state);
        $this->assertSame('processed', $second->state);
        $this->assertNotSame($first->ticket_id, $second->ticket_id);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertSame(Ticket::findOrFail($first->ticket_id)->client_id, Ticket::findOrFail($second->ticket_id)->client_id);
        // A later inquiry, received after both exist, follows the 15:59 PT ruling: several
        // open tickets -> a new ticket with the others linked (the sequential single-ticket
        // follow-up is SubmissionProcessorTest::test_later_submission_reuses_the_sole_open_form_ticket).
        $third = app(SubmissionProcessor::class)->process($this->accept('THIRD')->id);
        $this->assertSame('processed', $third->state);
        $this->assertDatabaseCount('tickets', 3);
        $related = json_decode($third->related_ticket_ids, true);
        sort($related);
        $this->assertSame([$first->ticket_id, $second->ticket_id], $related);
    }

    /** diff:9 — visitor text is the client's voice, never an Agent (staff) note. */
    public function test_form_note_is_recorded_as_end_user_not_agent(): void
    {
        Bus::fake();
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $note = TicketNote::findOrFail($row->ticket_note_id);
        $this->assertSame(WhoType::EndUser, $note->who_type);
        $this->assertSame(NoteType::Reply, $note->note_type);
    }

    /** diff:2 / diff:7 / diff:8 — only the intake writer stamps provenance. */
    public function test_staff_note_on_unverified_ticket_keeps_time_billing_email_and_does_not_block_verification(): void
    {
        Bus::fake();
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $ticket = Ticket::findOrFail($row->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $staffNote = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'Tech investigation',
            'note_type' => NoteType::Reply, 'who_type' => WhoType::Agent, 'is_private' => false,
            'time_minutes' => 30, 'is_billable' => true, 'noted_at' => now()]);
        $staffNote->refresh();
        $this->assertFalse((bool) $staffNote->contact_intake_origin);
        $this->assertSame(30, $staffNote->time_minutes);
        $this->assertTrue((bool) $staffNote->is_billable);
        $this->assertFalse((bool) $staffNote->is_private);
        $staff = User::factory()->admin()->create(['is_active' => true]);
        app(StaffWorkflow::class)->act($row->id, $staff, 'verify', 'Synthetic verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
    }

    /** diff:7 — after verification a form note may carry staff time like any internal note. */
    public function test_verified_form_note_is_no_longer_forced_to_zero_time(): void
    {
        Bus::fake();
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $staff = User::factory()->admin()->create(['is_active' => true]);
        app(StaffWorkflow::class)->act($row->id, $staff, 'verify', 'Synthetic verification');
        $note = TicketNote::findOrFail($row->ticket_note_id);
        $note->update(['time_minutes' => 15, 'is_billable' => true]);
        $note->refresh();
        $this->assertSame(15, $note->time_minutes);
        $this->assertTrue((bool) $note->is_billable);
        $this->assertTrue((bool) $note->contact_intake_origin);
    }

    /** contract-replacement:3 — a soft-deleted form note must not hold the ticket forever. */
    public function test_trashed_unverified_form_note_does_not_block_ticket_verification(): void
    {
        Bus::fake();
        $one = app(SubmissionProcessor::class)->process($this->accept('ONE')->id);
        $two = app(SubmissionProcessor::class)->process($this->accept('TWO')->id);
        $this->assertSame($one->ticket_id, $two->ticket_id);
        TicketNote::findOrFail($two->ticket_note_id)->delete();
        $staff = User::factory()->admin()->create(['is_active' => true]);
        app(StaffWorkflow::class)->act($one->id, $staff, 'verify', 'Synthetic verification');
        $this->assertFalse(Ticket::findOrFail($one->ticket_id)->isUnverifiedContactIntake());
    }

    /** diff:4 — a poison row leaves the pending queue after bounded failures. */
    public function test_poison_row_is_quarantined_after_bounded_failures_and_does_not_starve_new_rows(): void
    {
        Bus::fake();
        $poison = $this->accept('POISON');
        $poison->update(['resolved_client_id' => null]);
        DB::table('contact_submissions')->where('id', $poison->id)->update(['payload' => 'not-decryptable']);
        // Literal 5, not the constant: the control must fail on BEHAVIOUR at the unfixed byte.
        for ($i = 0; $i < 5; $i++) {
            $this->artisan('contact-intake:drain');
        }
        $poison->refresh();
        $this->assertSame('quarantined', $poison->state);
        $this->assertSame('processing_failed', $poison->exception_reason);
        $this->assertSame(5, $poison->attempts);
        $good = $this->accept('GOOD');
        $this->artisan('contact-intake:drain')->assertSuccessful();
        $this->assertSame('processed', $good->fresh()->state);
    }

    /** diff:6 — 100+ undeliverable alerts must not hide a deliverable one behind them. */
    public function test_undeliverable_alerts_do_not_block_later_deliverable_alerts(): void
    {
        Bus::fake();
        Setting::setValue('contact_intake_enabled', '1');
        $row = $this->accept();
        $now = now();
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['contact_submission_id' => $row->id, 'event' => 'undeliverable_'.$i, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('contact_intake_notifications')->insert($rows);
        $owner = User::factory()->admin()->create(['is_active' => true]);
        $ticket = Ticket::factory()->create(['assignee_id' => $owner->id]);
        $other = $this->accept('OTHER');
        $other->update(['ticket_id' => $ticket->id]);
        DB::table('contact_intake_notifications')->insert(['contact_submission_id' => $other->id,
            'event' => 'processed', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(1, app(\App\Services\ContactIntake\IntakeNotifications::class)->drain());
        $this->assertNotNull(DB::table('contact_intake_notifications')->where('contact_submission_id', $other->id)->value('sent_at'));
    }
}
