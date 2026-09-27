<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\NoteType;
use App\Enums\NotificationEventType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Jobs\SendTicketNotification;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\CloseAutoEligibility;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\Triage\ConversationReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** r2 layer-1 rework controls: contain a note's CONTENT, not the fact that a client wrote. */
class IntakeR2ReworkContainmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function containedNote(Ticket $ticket, string $body = 'FORM_BODY_MARKER'): TicketNote
    {
        $note = new TicketNote(['ticket_id' => $ticket->id, 'body' => $body, 'note_type' => NoteType::Reply,
            'who_type' => WhoType::EndUser, 'is_private' => false, 'noted_at' => now()]);
        $note->forceFill(['contact_intake_origin' => true])->save();

        return $note;
    }

    /** diff:1 — a contained client note on an ordinary ticket still blocks auto-close and AI review. */
    public function test_contained_client_note_still_counts_as_client_engagement(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);
        $recentClient = new \ReflectionMethod(CloseAutoEligibility::class, 'hasRecentInboundClientNote');
        $humanTouched = new \ReflectionMethod(ConversationReviewer::class, 'wasRecentlyHumanTouched');
        $this->assertFalse($recentClient->invoke(null, $ticket));
        $this->assertFalse($humanTouched->invoke(null, $ticket));

        $this->containedNote($ticket);

        $this->assertTrue($recentClient->invoke(null, $ticket));
        $this->assertTrue($humanTouched->invoke(null, $ticket));
    }

    /** diff:1 — the assignee is told a contained note exists, without its body; ordinary notes keep theirs. */
    public function test_contained_note_notifies_assignee_without_its_body(): void
    {
        $assignee = User::factory()->create(['is_active' => true]);
        $author = User::factory()->create(['is_active' => true]);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New, 'assignee_id' => $assignee->id]);

        app(NotificationService::class)->notifyNoteAdded($ticket, $this->containedNote($ticket), $author->id);

        Bus::assertDispatched(SendTicketNotification::class, fn ($job) => str_contains(serialize($job), NotificationEventType::TicketNoteAdded->value)
            && str_contains(serialize($job), 'content withheld'));
        Bus::assertNotDispatched(SendTicketNotification::class, fn ($job) => str_contains(serialize($job), 'FORM_BODY_MARKER'));

        // Positive control: an ordinary note still carries its body.
        $ordinary = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'ORDINARY_BODY_MARKER', 'note_type' => NoteType::Note,
            'who_type' => WhoType::Agent, 'is_private' => true, 'noted_at' => now()]);
        app(NotificationService::class)->notifyNoteAdded($ticket, $ordinary, $author->id);
        Bus::assertDispatched(SendTicketNotification::class, fn ($job) => str_contains(serialize($job), 'ORDINARY_BODY_MARKER'));
    }

    /** diff:7 — a verified intake note that staff make public is portal-visible like any note. */
    public function test_verified_intake_note_made_public_is_portal_visible(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);
        $note = $this->containedNote($ticket);
        $this->assertFalse(TicketNote::portalVisible()->whereKey($note->id)->exists());

        $note->forceFill(['contact_intake_verified_at' => now()])->save();
        $note->update(['is_private' => false]);

        $this->assertTrue($note->fresh()->contact_intake_origin);
        $this->assertTrue(TicketNote::portalVisible()->whereKey($note->id)->exists());
    }

    /** diff:2 — the reply-mail seam refuses a held ticket; an ordinary ticket reaches the normal path. */
    public function test_reply_email_seam_refuses_held_ticket(): void
    {
        Setting::setValue('graph_mailbox', '');
        $ordinary = Ticket::factory()->create(['status' => TicketStatus::New]);
        $held = Ticket::factory()->create(['status' => TicketStatus::New]);
        $held->forceFill(['contact_intake_origin' => true])->save();
        $reply = fn (Ticket $t) => TicketNote::create(['ticket_id' => $t->id, 'body' => 'staff reply', 'note_type' => NoteType::Reply,
            'who_type' => WhoType::Agent, 'is_private' => false, 'noted_at' => now()]);

        $this->assertNull(app(EmailService::class)->sendTicketReplyNote($ordinary, $reply($ordinary), 'client@example.test'));
        $this->expectException(\DomainException::class);
        app(EmailService::class)->sendTicketReplyNote($held, $reply($held), 'visitor@example.test');
    }
}
