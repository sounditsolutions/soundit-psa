<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\DraftPipeline;
use App\Services\Technician\TechnicianAssessment;
use App\Services\Technician\TechnicianClassifier;
use App\Services\TicketResolutionDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DraftPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(): Ticket
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key'); // make AiConfig::isConfigured() true (encrypted path)
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        return Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);
    }

    private function ownable(): void
    {
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.85, true, ['known-runbook'], 160)));
    }

    public function test_ownable_ticket_with_client_substance_records_a_held_resolution_and_no_reply(): void
    {
        // A2b: DraftPipeline no longer drafts client REPLIES (the reactive agent's
        // send_reply tool owns that now). It records ONLY the held propose_resolution.
        $this->ownable();
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn('Reset the print spooler; printer back online.'));

        $ticket = $this->ticket();
        // A genuine (non-AI) client reply so the resolution-substance gate (v2) is satisfied.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Client',
            'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false,
            'body' => 'Any update on this?',
            'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);
        app(DraftPipeline::class)->run($ticket);

        // No send_reply is produced here anymore — that is the agent's job now (A2b).
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count(),
            'DraftPipeline must no longer produce held replies — the agent is the sole producer');

        $resolution = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->first();
        $this->assertNotNull($resolution);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $resolution->state);
        $this->assertStringContainsString('print spooler', $resolution->proposed_content);
        $this->assertEqualsWithDelta(0.85, $resolution->confidence, 0.001);

        // Held + audited as awaiting_approval, never executed.
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'propose_resolution', 'result_status' => 'awaiting_approval']);
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'propose_resolution', 'result_status' => 'executed']);
    }

    public function test_not_ownable_records_nothing_and_does_not_draft(): void
    {
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.2, false, ['novel'], 90)));
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());

        $ticket = $this->ticket();
        // A client reply so the substance gate passes and we actually reach the ownable check.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Client',
            'who_type' => \App\Enums\WhoType::EndUser, 'ai_authored' => false,
            'body' => 'Any update on this?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);
        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->whereIn('action_type', ['send_reply', 'propose_resolution'])->count());
    }

    public function test_at_intake_with_no_client_substance_it_does_not_even_classify(): void
    {
        // A2b idempotency: a staff-created ticket at intake (no real client reply) has nothing
        // to resolve — the substance gate short-circuits BEFORE any AI classify, even across a
        // job retry, so there is no wasted re-spend.
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')->never());
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());

        $ticket = $this->ticket(); // no client reply note

        app(DraftPipeline::class)->run($ticket);
        app(DraftPipeline::class)->run($ticket); // retry — still no classify

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->whereIn('action_type', ['send_reply', 'propose_resolution'])->count());
    }

    public function test_pipeline_is_idempotent_and_does_not_re_spend_ai_on_re_run(): void
    {
        // classify + resolution draft must each run EXACTLY ONCE across two runs: the pre-AI
        // gate short-circuits the retry (the propose_resolution run is now newer than the
        // client reply) before any model call.
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')->once()
            ->andReturn(new TechnicianAssessment(0.85, true, ['known-runbook'], 160)));
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->once()
            ->andReturn('Reset the print spooler; printer back online.'));

        $ticket = $this->ticket();
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Client',
            'who_type' => \App\Enums\WhoType::EndUser, 'ai_authored' => false,
            'body' => 'Any update on this?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        app(DraftPipeline::class)->run($ticket);
        app(DraftPipeline::class)->run($ticket); // retry → guard short-circuits BEFORE any AI call

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->count());
        $this->assertSame(1, \App\Models\TechnicianActionLog::where('action_type', 'propose_resolution')->where('result_status', 'awaiting_approval')->count());
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }

    public function test_budget_reached_holds_before_any_ai_call(): void
    {
        Setting::setValue('technician_daily_token_limit', '1');
        $ticket = $this->ticket();
        // Pre-spend the budget with a same-day run.
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'send_ack', 'content_hash' => str_repeat('z', 64),
            'state' => 'done', 'tokens_used' => 100,
        ]);

        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')->never());

        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->count());
    }
}
