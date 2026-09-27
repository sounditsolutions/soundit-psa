<?php

namespace Tests\Feature\ContactIntake;

use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ContactIntake\IntakeNotifications;
use App\Services\ContactIntake\StaffWorkflow;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function accept(): ContactSubmission
    {
        Setting::setValue('contact_intake_enabled', '1');
        app(SubmissionLedger::class)->accept('website', ['submission_id' => (string) Str::uuid(),
            'name' => 'Synthetic', 'email' => 'visitor@example.test', 'message' => '<script>SYNTHETIC</script>',
            'inquiry' => 'unknown-slug', 'submitted_at' => '2026-09-24T12:00:00Z']);

        return ContactSubmission::latest('id')->firstOrFail();
    }

    public function test_staff_queue_escapes_payload_and_refuses_nonstaff(): void
    {
        $row = $this->accept();
        $this->get(route('contact-intake.index'))->assertRedirect();
        $this->actingAs(User::factory()->billing()->create())->get(route('contact-intake.show', $row->id))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->get(route('contact-intake.show', $row->id))
            ->assertOk()->assertSee('&lt;script&gt;SYNTHETIC&lt;/script&gt;', false)->assertDontSee('<script>SYNTHETIC</script>', false);
        $this->get(route('contact-intake.index'))->assertOk()->assertSee($row->receipt);
    }

    public function test_release_is_not_verification_and_verification_is_audited_and_once(): void
    {
        Bus::fake();
        $staff = User::factory()->admin()->create(['is_active' => true]);
        $row = $this->accept();
        $workflow = app(StaffWorkflow::class);
        $workflow->act($row->id, $staff, 'quarantine', 'Synthetic hold');
        $client = Client::factory()->create();
        $workflow->act($row->id, $staff, 'resolve_client', 'Synthetic selection', $client->id);
        $row = app(SubmissionProcessor::class)->process($row->id);
        $ticket = Ticket::findOrFail($row->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertStringContainsString('UNVERIFIED CLAIMED-SENDER', $workflow->draftContext($row->id, $staff));
        $this->assertTrue(TicketNote::findOrFail($row->ticket_note_id)->isUnverifiedContactIntake());
        $workflow->act($row->id, $staff, 'verify', 'Synthetic independent verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertFalse(TicketNote::findOrFail($row->ticket_note_id)->isUnverifiedContactIntake());
        $this->assertDatabaseHas('contact_intake_audits', ['user_id' => $staff->id, 'action' => 'verify']);
        $this->actingAs($staff)->post(route('contact-intake.act', $row->id), ['action' => 'verify', 'reason' => 'Again'])->assertStatus(409);
    }

    public function test_owner_internal_outbox_is_durable_and_flag_off_preserves_it(): void
    {
        Bus::fake();
        $owner = User::factory()->create(['name' => 'Chet']);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame($owner->id, Ticket::findOrFail($row->ticket_id)->assignee_id);
        Bus::assertNothingDispatched();
        $this->assertDatabaseHas('contact_intake_notifications', ['contact_submission_id' => $row->id, 'sent_at' => null]);
        Setting::setValue('contact_intake_enabled', '0');
        $this->assertSame(0, app(IntakeNotifications::class)->drain());
        $this->assertDatabaseHas('contact_intake_notifications', ['sent_at' => null]);
        Setting::setValue('contact_intake_enabled', '1');
        $this->assertSame(1, app(IntakeNotifications::class)->drain());
        Bus::assertDispatched(SendTicketNotification::class, 1);
        $this->assertSame(0, app(IntakeNotifications::class)->drain());
    }

    public function test_several_open_form_tickets_create_new_ticket_with_references(): void
    {
        Bus::fake();
        $processor = app(SubmissionProcessor::class);
        $one = $processor->process($this->accept()->id);
        $first = Ticket::findOrFail($one->ticket_id);
        $other = $first->replicate();
        $other->saveQuietly();
        $next = $processor->process($this->accept()->id);
        $this->assertNotSame($first->id, $next->ticket_id);
        $this->assertEqualsCanonicalizing([$first->id, $other->id], json_decode($next->related_ticket_ids, true));
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tickets', 3);
    }
}
