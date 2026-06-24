<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
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

    public function test_a_new_client_reply_redrafts_and_supersedes_the_stale_draft(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'C', 'last_name' => 'U', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        // An existing held draft from an earlier turn.
        $stale = \App\Models\TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => \App\Enums\TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'old draft', 'created_at' => now()->subHour(),
        ]);

        // A NEW client reply arrives (newer than the stale draft).
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'C', 'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false, 'body' => 'Still broken!', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        // Mock the collaborators so the pipeline produces a fresh held reply.
        $this->mock(\App\Services\Technician\TechnicianClassifier::class, fn ($m) => $m->shouldReceive('classify')
            ->andReturn(new \App\Services\Technician\TechnicianAssessment(0.8, true, ['x'], 10)));
        $this->mock(\App\Services\Technician\TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')
            ->andReturn(new \App\Services\Technician\TechnicianDraft('fresh draft', 'c@example.com', 50)));
        $this->mock(\App\Services\TicketResolutionDrafter::class, fn ($m) => $m->shouldReceive('draft')->andReturnNull());

        app(\App\Services\Technician\DraftPipeline::class)->run($ticket->fresh());

        // The stale draft is superseded; a fresh held draft exists.
        $this->assertSame(\App\Enums\TechnicianRunState::Superseded, $stale->fresh()->state);
        $this->assertSame(1, \App\Models\TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', \App\Enums\TechnicianRunState::AwaitingApproval->value)->count());
    }
}
