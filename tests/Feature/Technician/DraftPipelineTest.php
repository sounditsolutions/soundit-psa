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
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
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

    public function test_ownable_ticket_records_held_reply_and_resolution(): void
    {
        $this->ownable();
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn(new TechnicianDraft('Hello, we can help.', 'c@example.com', 700)));
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

        $reply = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->first();
        $this->assertNotNull($reply);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $reply->state);
        $this->assertSame('Hello, we can help.', $reply->proposed_content);
        $this->assertSame('c@example.com', $reply->proposed_meta['to']);
        $this->assertEqualsWithDelta(0.85, $reply->confidence, 0.001);

        $resolution = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->first();
        $this->assertNotNull($resolution);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $resolution->state);
        $this->assertStringContainsString('print spooler', $resolution->proposed_content);

        // Nothing executed; the held actions are audited as awaiting_approval.
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'send_reply', 'result_status' => 'awaiting_approval']);
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'send_reply', 'result_status' => 'executed']);
    }

    public function test_not_ownable_records_nothing_and_does_not_draft(): void
    {
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.2, false, ['novel'], 90)));
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());

        $ticket = $this->ticket();
        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->whereIn('action_type', ['send_reply', 'propose_resolution'])->count());
    }

    public function test_pipeline_is_idempotent_and_does_not_re_spend_ai_on_re_run(): void
    {
        // classify + draft must each be called EXACTLY ONCE across two runs (v2: the
        // pre-AI idempotency guard short-circuits the retry before any model call).
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')->once()
            ->andReturn(new TechnicianAssessment(0.85, true, ['known-runbook'], 160)));
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->once()
            ->andReturn(new TechnicianDraft('Hello, we can help.', 'c@example.com', 700)));
        // No client reply at intake → resolution-substance gate skips it entirely.
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());

        $ticket = $this->ticket();
        app(DraftPipeline::class)->run($ticket);
        app(DraftPipeline::class)->run($ticket); // retry → guard short-circuits BEFORE any AI call

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
        $this->assertSame(1, \App\Models\TechnicianActionLog::where('action_type', 'send_reply')->where('result_status', 'awaiting_approval')->count());
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

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }
}
