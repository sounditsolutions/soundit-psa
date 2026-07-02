<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\EmailDirection;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientReplyReopensDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reply_dispatches_the_loop_when_enabled(): void
    {
        // Create ticket while DISABLED so TicketObserver::created does NOT dispatch the Loop.
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        // Enable + fake the bus AFTER ticket creation, so the ONLY dispatch captured is the reply hook's.
        Setting::setValue('technician_enabled', '1');
        Bus::fake();

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update on this?');

        Bus::assertDispatched(RunTechnicianLoop::class);
    }

    public function test_portal_reply_does_not_dispatch_when_disabled(): void
    {
        Bus::fake(); // technician_enabled unset → disabled
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update?');

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_portal_reply_does_not_dispatch_when_only_emergency_backstop_is_enabled(): void
    {
        Setting::setValue('technician_enabled', '0');
        Setting::setValue('technician_emergency_enabled', '1');
        Bus::fake();

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update?');

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_email_reply_does_not_dispatch_when_only_emergency_backstop_is_enabled(): void
    {
        Setting::setValue('technician_enabled', '0');
        Setting::setValue('technician_emergency_enabled', '1');

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'c@example.com',
            'from_name' => 'Client User',
            'subject' => 'Re: Existing ticket',
            'body_preview' => 'Any update?',
            'body_text' => 'Any update?',
            'received_at' => now(),
            'is_read' => false,
        ]);

        Bus::fake();

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_a_new_client_reply_redrafts_and_supersedes_the_stale_draft(): void
    {
        // A2b: the redraft+supersede behavior moved from DraftPipeline to the agent's
        // SendReplyTool (the SOLE producer of held replies). A new client reply → a fresh
        // held draft; the stale one is superseded so the cockpit shows only the newest.
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        \App\Models\User::factory()->create(); // AI actor for the audit row
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'C', 'last_name' => 'U', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        // An existing held draft from an earlier turn (backdated so it is seen as older).
        $stale = \App\Models\TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => \App\Enums\TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'old draft',
        ]);
        $stale->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        // A NEW client reply arrives (newer than the stale draft).
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'C', 'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false, 'body' => 'Still broken!', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        // The fenced drafter returns a fresh (different) body.
        $this->mock(\App\Services\Technician\TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')
            ->andReturn(new \App\Services\Technician\TechnicianDraft('fresh draft', 'c@example.com', 50)));

        app(\App\Services\Agent\SendReplyTool::class)->execute($ticket->fresh(), ['reason' => 'the client replied again']);

        // The stale draft is superseded; exactly one fresh held draft exists.
        $this->assertSame(\App\Enums\TechnicianRunState::Superseded, $stale->fresh()->state);
        $this->assertSame(1, \App\Models\TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', \App\Enums\TechnicianRunState::AwaitingApproval->value)->count());
    }

    /**
     * Regression (carried to A2b's SendReplyTool): identical-body nudge ("any update?") was
     * silenced because firstOrCreate found the just-superseded row (same hash) and skipped
     * the gate. The fix revives the stale run rather than leaving the cockpit empty.
     */
    public function test_identical_body_nudge_revives_the_superseded_draft(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        \App\Models\User::factory()->create(); // AI actor for the audit row
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'C', 'last_name' => 'U', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        $body = 'Thank you for reaching out. I am looking into this and will follow up shortly.';

        // Pre-existing AwaitingApproval run with the SAME body hash the tool will produce
        // (backdated so the upcoming client reply is seen as newer).
        $hash = hash('sha256', 'send_reply:'.$ticket->id.':'.$body);
        $existing = \App\Models\TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => $hash, 'state' => \App\Enums\TechnicianRunState::AwaitingApproval,
            'proposed_content' => $body,
        ]);
        $existing->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        // Supersede it (simulating an earlier turn).
        $existing->markSuperseded();
        $this->assertSame(\App\Enums\TechnicianRunState::Superseded, $existing->fresh()->state);

        // A NEW client reply arrives (newer than the now-superseded run).
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'C', 'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false, 'body' => 'Any update?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        // The drafter returns the SAME body → same hash → firstOrCreate finds the superseded row.
        $this->mock(\App\Services\Technician\TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')
            ->andReturn(new \App\Services\Technician\TechnicianDraft($body, 'c@example.com', 20)));

        app(\App\Services\Agent\SendReplyTool::class)->execute($ticket->fresh(), ['reason' => 'identical nudge']);

        // Must NOT be zero — the cockpit must show exactly ONE AwaitingApproval draft.
        $this->assertSame(1, \App\Models\TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', \App\Enums\TechnicianRunState::AwaitingApproval->value)->count());

        // The revived run should have AwaitingApproval state.
        $this->assertSame(\App\Enums\TechnicianRunState::AwaitingApproval, $existing->fresh()->state);

        // An awaiting_approval audit row must exist in the action log.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'send_reply',
            'result_status' => 'awaiting_approval',
        ]);
    }
}
