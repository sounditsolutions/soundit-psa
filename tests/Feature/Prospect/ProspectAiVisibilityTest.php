<?php

namespace Tests\Feature\Prospect;

use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTechnicianLoop;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendTicketNotification;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Models\WikiRun;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\ReplyDraftService;
use App\Services\TicketResolutionDrafter;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Prospects are non-billing/non-portal records, but their tickets are real staff
 * work. AI surfaces should see and act on prospect tickets the same way they do
 * active-client tickets.
 */
class ProspectAiVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function enableAutoTriage(): void
    {
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_new_tickets', '1');
    }

    private function enableAutoMine(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
        // Wiki mining + resolution drafting are AI-driven and now hard-gate on a configured
        // provider; set the encrypted key so these jobs actually run under test.
        Setting::setEncrypted('ai_api_key', 'test-key');
    }

    public function test_prospect_ticket_creation_dispatches_triage_notifications_and_technician_loop(): void
    {
        $this->enableAutoTriage();
        Setting::setValue('technician_enabled', '1');
        User::factory()->create();

        $prospect = Client::factory()->prospect()->create();
        Ticket::factory()->create([
            'client_id' => $prospect->id,
            'created_by' => null,
        ]);

        Bus::assertDispatched(RunTriagePipeline::class);
        Bus::assertDispatched(SendTicketNotification::class);
        Bus::assertDispatched(RunTechnicianLoop::class);
    }

    public function test_resolved_prospect_ticket_dispatches_wiki_mining_and_resolution_drafting(): void
    {
        $this->enableAutoMine();
        $prospect = Client::factory()->prospect()->create();

        $withResolution = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);
        $withoutResolution = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $withResolution->update([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Resolved the prospect issue.',
        ]);
        $withoutResolution->update([
            'status' => TicketStatus::Resolved,
        ]);

        Bus::assertDispatched(MineTicketKnowledge::class, fn ($job) => (fn () => $this->ticketId)->call($job) === $withResolution->id);
        Bus::assertDispatched(GenerateTicketResolution::class, fn (GenerateTicketResolution $job) => $job->ticketId === $withoutResolution->id);
    }

    public function test_run_triage_pipeline_job_runs_for_prospect_client(): void
    {
        $this->enableAutoTriage();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $pipeline = $this->createMock(TriagePipeline::class);
        $pipeline->expects($this->once())->method('run');

        (new RunTriagePipeline($ticket->id, 'triage'))->handle($pipeline);
    }

    public function test_mine_ticket_knowledge_job_runs_for_prospect_client(): void
    {
        $this->enableAutoMine();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::Resolved,
            'resolution' => 'Fixed the prospect onboarding issue.',
        ]);

        $mock = $this->mock(\App\Services\Ai\AiClient::class);
        $mock->shouldReceive('completeJson')->andReturn(['facts' => []]);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(100);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(50);
        $mock->shouldReceive('cumulativeTotalTokens')->andReturn(150);

        app()->call([(new MineTicketKnowledge($ticket->id, 'backfill')), 'handle']);

        $this->assertSame(1, WikiRun::count());
    }

    public function test_generate_ticket_resolution_job_runs_for_prospect_client(): void
    {
        $this->enableAutoMine();

        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::Resolved,
            'resolution' => null,
        ]);

        $drafter = $this->createMock(TicketResolutionDrafter::class);
        $drafter->expects($this->once())
            ->method('draft')
            ->willReturn('Replaced the failed drive.');

        (new GenerateTicketResolution($ticket->id))->handle($drafter);

        $this->assertSame('Replaced the failed drive.', $ticket->fresh()->resolution);
    }

    public function test_manual_triage_review_and_bulk_triage_dispatch_for_prospect_tickets(): void
    {
        $this->enableAutoTriage();

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $triageTicket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $reviewTicket = Ticket::factory()->create(['client_id' => $prospect->id]);
        $bulkTicket = Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::fake();

        $this->actingAs($user)
            ->post(route('tickets.triage', $triageTicket))
            ->assertRedirect(route('tickets.show', $triageTicket))
            ->assertSessionHas('success', 'AI Triage started. Results will appear shortly.');

        $this->actingAs($user)
            ->post(route('tickets.review', $reviewTicket))
            ->assertRedirect(route('tickets.show', $reviewTicket))
            ->assertSessionHas('success', 'AI Review started. Results will appear shortly.');

        $this->actingAs($user)->post(route('tickets.bulk-action'), [
            'action' => 'triage',
            'ticket_ids' => [$triageTicket->id, $bulkTicket->id],
        ])->assertRedirect(route('tickets.index'))
            ->assertSessionHas('success', 'AI Triage queued for 2 ticket(s).');

        Bus::assertDispatchedTimes(RunTriagePipeline::class, 4);
    }

    public function test_manual_draft_reply_and_resolution_endpoints_allow_prospect_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
        ]);

        $this->mock(ReplyDraftService::class)
            ->shouldReceive('generateDraft')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $ticket->id), 'Be concise.', $user->name)
            ->andReturn([
                'draft' => 'Thanks, we are checking this now.',
                'to' => null,
                'cc' => [],
                'status' => TicketStatus::InProgress->value,
                'input_tokens' => 10,
                'output_tokens' => 5,
            ]);

        $this->actingAs($user)
            ->postJson(route('tickets.draft-reply', $ticket), ['instructions' => 'Be concise.'])
            ->assertOk()
            ->assertJson(['draft' => 'Thanks, we are checking this now.']);

        $this->mock(TicketResolutionDrafter::class)
            ->shouldReceive('draft')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $ticket->id), 'manual')
            ->andReturn('Prospect issue documented.');

        $this->actingAs($user)
            ->postJson(route('tickets.draft-resolution', $ticket))
            ->assertOk()
            ->assertJson(['resolution' => 'Prospect issue documented.']);
    }

    public function test_assistant_conversations_and_save_note_allow_prospect_context(): void
    {
        // psa-uw2o: the assistant routes are now gated on AssistantConfig::isEnabled(),
        // which requires a configured Anthropic provider. This test's subject is
        // "prospect context is accepted", not "the endpoint is reachable while the
        // Assistant is off" — so supply the precondition the surface legitimately
        // needs. It still fails if prospect context is ever rejected.
        //
        // assistant_enabled is set EXPLICITLY rather than leaning on the current
        // default-on behaviour: psa-98dq may yet rule that the Assistant defaults
        // off, and this test is about prospects, so it must not be the one place
        // that silently depends on that ruling (psa-uw2o.2 / psa-uw2o.3).
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Setting::setValue('assistant_enabled', '1');

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);

        $this->actingAs($user)
            ->postJson(route('assistant.create'), [
                'context_type' => 'ticket',
                'context_id' => $ticket->id,
            ])
            ->assertOk()
            ->assertJson(['context_type' => 'ticket', 'context_id' => $ticket->id]);

        $this->actingAs($user)
            ->postJson(route('assistant.create'), [
                'context_type' => 'client',
                'context_id' => $prospect->id,
            ])
            ->assertOk()
            ->assertJson(['context_type' => 'client', 'context_id' => $prospect->id]);

        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'AI output that should become a private note.',
        ]);

        $this->actingAs($user)
            ->postJson(route('assistant.save-note', $conversation), [
                'message_id' => $message->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());

        $note = app(AssistantService::class)->saveAsNote($message, $ticket, $user->id);
        $this->assertTrue($note->exists);
        $this->assertSame(2, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_chet_tool_executor_can_read_and_write_prospect_tickets(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create(['name' => 'Prospect Tool Client']);
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'assignee_id' => $user->id,
            'status' => TicketStatus::InProgress,
            'subject' => 'Shared tool keyword prospect',
        ]);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Prospect Caller',
            'body' => 'Prospect note body',
            'noted_at' => now(),
        ]);

        $general = new AssistantToolExecutor(userId: $user->id);
        $clientScoped = new AssistantToolExecutor(clientId: $prospect->id, userId: $user->id);

        $this->assertSame($ticket->id, $general->execute('get_ticket_detail', ['ticket_id' => $ticket->id])['id']);
        $this->assertTrue(
            collect($general->execute('list_open_tickets', []))->pluck('id')->contains($ticket->id),
            'Prospect ticket missing from Chet open-ticket list.',
        );
        $this->assertTrue(
            collect($general->execute('find_clients', ['query' => 'Prospect Tool'])['clients'])->pluck('id')->contains($prospect->id),
            'Prospect client missing from Chet client search.',
        );

        $this->assertTrue(
            collect($clientScoped->execute('search_tickets', ['query' => 'Shared tool keyword']))->pluck('id')->contains($ticket->id),
            'Prospect ticket missing from client-scoped Chet search.',
        );
        $this->assertSame('Prospect note body', $clientScoped->execute('get_ticket_notes', ['ticket_id' => $ticket->id])[0]['body']);

        $created = $clientScoped->execute('create_ticket', [
            'subject' => 'AI-created prospect ticket',
            'description' => 'A prospect-visible Chet-created ticket.',
        ]);
        $this->assertTrue($created['success']);
        $this->assertDatabaseHas('tickets', [
            'id' => $created['ticket_id'],
            'client_id' => $prospect->id,
        ]);

        $note = $clientScoped->execute('add_ticket_note', [
            'ticket_id' => $ticket->id,
            'body' => 'AI-authored prospect note.',
        ]);
        $this->assertTrue($note['success']);
        $this->assertDatabaseHas('ticket_notes', [
            'id' => $note['note_id'],
            'ticket_id' => $ticket->id,
            'ai_authored' => true,
        ]);
    }

    public function test_ticket_show_shows_manual_ai_and_triage_controls_for_prospect_ticket(): void
    {
        config(['services.ai.api_key' => 'sk-test-key']);
        $this->enableAutoTriage();

        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $prospect->id,
            'status' => TicketStatus::InProgress,
        ]);
        $conversation = AssistantConversation::create([
            'user_id' => $user->id,
            'context_type' => 'ticket',
            'context_id' => $ticket->id,
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Previous AI question.',
        ]);

        $response = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('id="askAiBtn"', false);
        $response->assertSee('id="draftReplyBtn"', false);
        $response->assertSee('id="draftResolutionBtn"', false);
        $response->assertSee('id="ai-chat-input-'.$conversation->id.'"', false);
        $response->assertSee(route('tickets.triage', $ticket), false);
        $response->assertSee(route('tickets.review', $ticket), false);
    }

    public function test_assistant_tool_status_schema_uses_pending_third_party_status(): void
    {
        $tools = collect(AssistantToolDefinitions::getTools(false));

        foreach (['search_all_tickets', 'list_my_tickets'] as $toolName) {
            $status = $tools->firstWhere('name', $toolName)['input_schema']['properties']['status'];

            $this->assertContains('pending_third_party', $status['enum']);
            $this->assertStringContainsString('pending_third_party', $status['description']);
            $this->assertNotContains('pending_vendor', $status['enum']);
            $this->assertStringNotContainsString('pending_vendor', $status['description']);
        }
    }
}
