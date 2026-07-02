<?php

namespace Tests\Feature\Agent;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\SendReplyTool;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * SendReplyTool (A2) — the agent's HELD client-reply tool. It drafts the body via the
 * FENCED, output-scanned TechnicianReplyDrafter (never model free-text), records a held
 * run in DraftPipeline's exact shape (so the existing approveAndSend + cockpit arm work
 * unchanged), and routes through the gate with a THROWING tripwire executor. It must
 * NEVER auto-send — send_reply is Approve-tier always, even with a misconfigured tier map.
 */
class SendReplyToolTest extends TestCase
{
    use RefreshDatabase;

    /** A ticket whose lone activity is an unaddressed client reply (so a reply is warranted). */
    private function ticketAwaitingReply(): Ticket
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        User::factory()->create(); // AI actor fallback for the audit row

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress,
        ]);

        // A genuine (non-AI) client reply with nothing drafted yet ⇒ unaddressed.
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Client',
            'who_type' => WhoType::EndUser,
            'ai_authored' => false,
            'body' => 'Any update on this?',
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        return $ticket;
    }

    private function mockDrafter(?TechnicianDraft $draft): void
    {
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->andReturn($draft));
    }

    public function test_send_reply_records_a_held_run_in_the_draftpipeline_shape(): void
    {
        $this->mockDrafter(new TechnicianDraft('Thanks — here are the next steps.', 'c@example.com', 700));
        $ticket = $this->ticketAwaitingReply();

        $result = app(SendReplyTool::class)->execute($ticket, ['reason' => 'The client asked for an update.']);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->first();
        $this->assertNotNull($run, 'a send_reply run must be created');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('Thanks — here are the next steps.', $run->proposed_content, 'the UNDISCLOSED drafter body is held');
        $this->assertSame('c@example.com', $run->proposed_meta['to']);
        $this->assertSame(['The client asked for an update.'], $run->proposed_meta['reasons']);
        $this->assertSame('technician-drafter', $run->proposed_meta['drafted_by']);

        // content_hash is exactly what approveAndSend recomputes: sha256('send_reply:'.id.':'.body).
        $this->assertSame(
            hash('sha256', 'send_reply:'.$ticket->id.':Thanks — here are the next steps.'),
            $run->content_hash,
        );

        // Held + audited as awaiting_approval, NOT executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'send_reply',
            'result_status' => 'awaiting_approval',
        ]);
        $this->assertDatabaseMissing('technician_action_logs', [
            'action_type' => 'send_reply',
            'result_status' => 'executed',
        ]);
        $this->assertIsString($result);
    }

    public function test_send_reply_can_never_auto_send_even_when_the_tier_map_says_auto(): void
    {
        // ADVERSARIAL (the core A2 invariant): even if an operator hand-maps send_reply
        // to 'auto', a client-facing send must STILL hold — never execute. Defense in
        // depth: the classifier hard-codes Approve AND the executor is a throwing tripwire.
        Setting::setValue('technician_action_tiers', json_encode(['send_reply' => 'auto']));
        $this->mockDrafter(new TechnicianDraft('Auto? No — held.', 'c@example.com', 120));
        $ticket = $this->ticketAwaitingReply();

        app(SendReplyTool::class)->execute($ticket, ['reason' => 'adversarial']);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->first();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state, 'send_reply must NEVER auto-execute');

        // No executed audit row, and NO client-facing reply note was created (nothing sent).
        $this->assertDatabaseMissing('technician_action_logs', [
            'action_type' => 'send_reply',
            'result_status' => 'executed',
        ]);
        $this->assertSame(
            0,
            TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->where('note_type', NoteType::Reply)->count(),
            'the tool must not send (create) a client reply note — the send happens only at operator approval',
        );
    }

    public function test_the_recipient_comes_from_the_drafter_never_the_models_input(): void
    {
        // The model has no 'to' field, and even a smuggled one is ignored — the recipient
        // is the drafter's sanitized 'to' (and at SEND time approveAndSend re-derives from
        // $ticket->contact). Never the model's address.
        $this->mockDrafter(new TechnicianDraft('Body.', 'c@example.com', 100));
        $ticket = $this->ticketAwaitingReply();

        app(SendReplyTool::class)->execute($ticket, ['reason' => 'x', 'to' => 'attacker@evil.test', 'body' => 'INJECTED']);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->first();
        $this->assertSame('c@example.com', $run->proposed_meta['to'], 'the model-supplied "to" must be ignored');
        $this->assertSame('Body.', $run->proposed_content, 'the model-supplied "body" must be ignored — only the fenced drafter writes it');
    }

    public function test_no_reply_is_drafted_when_nothing_is_unaddressed(): void
    {
        // Ack-suppression carried from DraftPipeline: a prior reply run with no newer
        // client message ⇒ nothing unaddressed ⇒ the drafter is never even called.
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->never());

        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        User::factory()->create();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        // A prior reply run exists and there is NO client reply note ⇒ unaddressed = false.
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id,
            'action_type' => 'send_reply', 'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'prior', 'tokens_used' => 0,
        ]);

        app(SendReplyTool::class)->execute($ticket, ['reason' => 'should be suppressed']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count(),
            'no new reply run should be created when nothing is unaddressed');
    }

    public function test_nothing_is_recorded_when_the_drafter_declines(): void
    {
        // The fenced drafter returns null (quarantined by the output scan, or an AI error)
        // ⇒ no run, no audit row, just a "left it" string.
        $this->mockDrafter(null);
        $ticket = $this->ticketAwaitingReply();

        $result = app(SendReplyTool::class)->execute($ticket, ['reason' => 'x']);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'send_reply')->count());
        $this->assertIsString($result);
    }

    public function test_a_duplicate_reply_does_not_create_a_second_run(): void
    {
        // Idempotency (mirrors ProposeCloseTool): the same drafted body held twice = one run.
        // The drafter is consulted exactly ONCE — the second call is suppressed by
        // ack-suppression (the first held draft addresses the client reply), so no AI re-spend.
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')->once()
            ->andReturn(new TechnicianDraft('Identical body.', 'c@example.com', 100)));
        $ticket = $this->ticketAwaitingReply();
        $tool = app(SendReplyTool::class);

        $tool->execute($ticket, ['reason' => 'first']);
        $tool->execute($ticket, ['reason' => 'second']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }
}
