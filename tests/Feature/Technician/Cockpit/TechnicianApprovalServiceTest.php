<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
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
use App\Services\Email\EmailRecipientResolver;
use App\Services\EmailService;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianApprovalService;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function heldReplyRun(User $actor): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([])); // send_reply default-denies to Approve
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.',
        ]);
    }

    private function heldStageRun(User $actor, string $actionType): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer down',
        ]);
        $body = 'Original staged body.';

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', $actionType.':'.$ticket->id.':'.$body),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $body,
            'proposed_meta' => ['drafted_by' => 'mcp-staff:opsbot', 'reasons' => ['Needs a public update.']],
        ]);
    }

    public function test_approve_sends_disclosed_ai_note_through_the_gate_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->with(\Mockery::any(), \Mockery::any(), 'c@example.com', \Mockery::any())->andReturnNull());

        $result = app(TechnicianApprovalService::class)->approveAndSend($run, 'Edited reply body.', $actor->id);

        $this->assertSame('sent', $result->status);
        $note = TicketNote::find($result->noteId);
        $this->assertSame(WhoType::Agent, $note->who_type);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertSame(NoteType::Reply, $note->note_type);
        $this->assertStringContainsString('Edited reply body.', $note->body);             // the EDITED body was sent
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body); // disclosure appended
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'send_reply', 'result_status' => 'executed']);
    }

    public function test_double_approve_sends_once(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        $first = app(TechnicianApprovalService::class)->approveAndSend($run, 'Body.', $actor->id);
        $second = app(TechnicianApprovalService::class)->approveAndSend($run->fresh(), 'Body.', $actor->id);

        $this->assertSame('sent', $first->status);
        $this->assertSame('already_handled', $second->status); // the run-state latch rejected the replay
        $this->assertSame(1, TicketNote::where('ticket_id', $run->ticket_id)->where('ai_authored', true)->count());
    }

    public function test_deny_moves_the_run_out_of_the_queue(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $denied = app(TechnicianApprovalService::class)->deny($run);

        $this->assertTrue($denied);
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }

    public function test_deny_is_cas_guarded_to_awaiting_approval(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $run->advanceTo(TechnicianRunState::Done);

        $denied = app(TechnicianApprovalService::class)->deny($run);

        $this->assertFalse($denied);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_gate_throw_reverts_run_to_awaiting_approval(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        $this->mock(TechnicianActionGate::class, fn (MockInterface $m) => $m->shouldReceive('dispatch')->andThrow(new \RuntimeException('boom')));

        $caught = null;
        try {
            app(TechnicianApprovalService::class)->approveAndSend($run, 'Draft body.', $actor->id);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_gate_declined_reverts_run_to_awaiting_approval_in_db(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        // Engage the kill-switch so the gate returns 'held' (a non-executed status).
        Setting::setValue('technician_kill_switch', '1');

        $result = app(TechnicianApprovalService::class)->approveAndSend($run, 'Draft body.', $actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state); // NOT stuck at Executing
        $this->assertSame(0, TicketNote::where('ticket_id', $run->ticket_id)->where('ai_authored', true)->count());
    }

    public function test_approve_staged_email_sends_edited_body_and_records_approver(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldStageRun($actor, 'stage_email');
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')
            ->once()
            ->with(\Mockery::any(), \Mockery::any(), 'c@example.com', \Mockery::any())
            ->andReturnNull());

        $result = app(TechnicianApprovalService::class)->approveStagedEmail($run, 'Edited staged email.', $actor->id);

        $this->assertSame('sent', $result->status);
        $note = TicketNote::find($result->noteId);
        $this->assertSame(NoteType::Reply, $note->note_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertStringContainsString('Edited staged email.', $note->body);
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'stage_email',
            'result_status' => 'executed',
            'ticket_id' => $run->ticket_id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_approve_staged_public_note_publishes_without_email_and_releases_on_gate_decline(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldStageRun($actor, 'stage_public_note');
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->never());

        $result = app(TechnicianApprovalService::class)->approveStagedPublicNote($run, 'Edited public note.', $actor->id);

        $this->assertSame('published', $result->status);
        $note = TicketNote::find($result->noteId);
        $this->assertSame(NoteType::Note, $note->note_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertStringContainsString('Edited public note.', $note->body);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $declined = $this->heldStageRun($actor, 'stage_public_note');
        Setting::setValue('technician_kill_switch', '1');
        $retryable = app(TechnicianApprovalService::class)->approveStagedPublicNote($declined, 'Held by switch.', $actor->id);
        $this->assertSame('gate_declined', $retryable->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $declined->fresh()->state);
        $this->assertSame(0, TicketNote::where('ticket_id', $declined->ticket_id)->count());
    }

    public function test_approve_merge_executes_merge_and_supersedes_secondary_pending_runs(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $primary = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer offline',
        ]);
        $secondary = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Duplicate printer issue',
        ]);
        TicketNote::create([
            'ticket_id' => $secondary->id,
            'author_id' => $actor->id,
            'body' => 'Secondary diagnostic note.',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);
        $secondaryPending = TechnicianRun::create([
            'ticket_id' => $secondary->id,
            'client_id' => $client->id,
            'action_type' => 'stage_email',
            'content_hash' => hash('sha256', 'secondary-pending'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Dangling draft.',
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $primary->id,
            'client_id' => $client->id,
            'action_type' => 'propose_merge',
            'content_hash' => hash('sha256', 'propose_merge:'.$primary->id.':'.$secondary->id.':duplicate'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Duplicate ticket.',
            'proposed_meta' => [
                'primary_ticket_id' => $primary->id,
                'secondary_ticket_id' => $secondary->id,
                'drafted_by' => 'mcp-staff:opsbot',
            ],
        ]);

        $result = app(TechnicianApprovalService::class)->approveMerge($run, $actor->id);

        $this->assertSame('merged', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(TechnicianRunState::Superseded, $secondaryPending->fresh()->state);
        $this->assertSame($primary->id, $secondary->fresh()->parent_ticket_id);
        $this->assertSame(TicketStatus::Closed, $secondary->fresh()->status);
        $this->assertSame(1, TicketNote::where('ticket_id', $primary->id)->where('body', 'Secondary diagnostic note.')->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'propose_merge')
            ->where('result_status', 'executed')
            ->where('run_id', $run->id)
            ->where('approver_user_id', $actor->id)
            ->count());
    }

    /** @return array{0: TechnicianRun, 1: Ticket} */
    private function seedSendReplyRunWithThread(User $actor): array
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'Contact', 'email' => 'client@thread.test', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress, 'closed_at' => null,
        ]);
        // Set graph_mailbox AFTER the ticket create so TicketObserver::created
        // (notifyTicketCreated) has no mailbox to send through in tests — we only need
        // graph_mailbox for the resolver's own-address self-exclusion.
        Setting::setValue('graph_mailbox', 'support@msp.test');
        \App\Models\Email::create([
            'graph_id' => 'thr-1', 'direction' => \App\Enums\EmailDirection::Inbound,
            'from_address' => 'client@thread.test', 'from_name' => 'Client',
            'to_recipients' => [['address' => 'support@msp.test', 'name' => 'Support'], ['address' => 'vendor@thread.test', 'name' => 'Vendor']],
            'subject' => 'Re: Issue', 'body_preview' => 'x', 'body_text' => 'x', 'body_html' => '<p>x</p>',
            'has_attachments' => false, 'importance' => 'normal', 'received_at' => now()->subMinutes(3),
            'is_read' => true, 'client_id' => $client->id, 'person_id' => $contact->id, 'ticket_id' => $ticket->id,
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('b', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.',
        ]);

        return [$run, $ticket];
    }

    public function test_approve_and_send_re_resolves_recipients_and_fails_closed_on_invalid(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        [$run, $ticket] = $this->seedSendReplyRunWithThread($actor);

        // GATE 3 — invalid: an off-thread arbitrary CC with the knob OFF must fail closed
        // (run not consumed, no note written, no send).
        $bad = app(TechnicianApprovalService::class)->approveAndSend($run->fresh(), 'Body.', $actor->id, [], ['stranger@evil.test']);
        $this->assertSame('recipient_invalid', $bad->status);
        $this->assertNotNull($bad->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());

        // GATE 3 — valid: a thread-participant CC resolves and is sent (To defaults to contact).
        $captured = null;
        $this->mock(EmailService::class, function (MockInterface $m) use (&$captured) {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnUsing(
                function ($t, $n, $to, $cc) use (&$captured) {
                    $captured = [$to, $cc];

                    return null;
                });
        });
        $ok = app(TechnicianApprovalService::class)->approveAndSend($run->fresh(), 'Body.', $actor->id, [], ['vendor@thread.test']);
        $this->assertSame('sent', $ok->status);
        $this->assertSame(['client@thread.test', ['vendor@thread.test']], $captured);
    }

    public function test_approve_and_send_releases_the_claim_when_recipient_resolution_errors_unexpectedly(): void
    {
        // M1: a NON-validation throwable during resolve (e.g. a DB error inside candidates())
        // must still release the CAS claim — otherwise the run is stranded in Executing with
        // no reaper. It fails closed (no send) and stays retryable.
        $actor = User::factory()->create(['name' => 'Chet']);
        [$run] = $this->seedSendReplyRunWithThread($actor);

        $this->mock(EmailRecipientResolver::class, function (MockInterface $m) {
            $m->shouldReceive('resolve')->andThrow(new \RuntimeException('db exploded'));
        });

        try {
            app(TechnicianApprovalService::class)->approveAndSend($run->fresh(), 'Body.', $actor->id, [], ['vendor@thread.test']);
            $this->fail('expected the throwable to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('db exploded', $e->getMessage());
        }

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    private function executedLogForRun(TechnicianRun $run): TechnicianActionLog
    {
        return TechnicianActionLog::where('run_id', $run->id)
            ->where('result_status', 'executed')
            ->firstOrFail();
    }

    public function test_approved_content_hash_binds_final_recipients_for_send_reply(): void
    {
        // psa-w4e0 revise (recipient-swap TOCTOU): the SAME approved body to a DIFFERENT
        // final To/CC must sign + audit as a DIFFERENT action. Body-only hashes let an
        // approved payload be recipient-swapped without changing the signed grant hash.
        $actor = User::factory()->create(['name' => 'Chet']);
        [$runA, $ticket] = $this->seedSendReplyRunWithThread($actor);
        $runB = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('c', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.',
        ]);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->twice()->andReturnNull());

        $service = app(TechnicianApprovalService::class);
        $this->assertSame('sent', $service->approveAndSend($runA, 'Same body.', $actor->id)->status);
        $this->assertSame('sent', $service->approveAndSend($runB, 'Same body.', $actor->id, [], ['vendor@thread.test'])->status);

        $logA = $this->executedLogForRun($runA);
        $logB = $this->executedLogForRun($runB);

        // Different final audience ⇒ different executed content hash (grant + audit bind it).
        $this->assertNotSame($logA->content_hash, $logB->content_hash);

        // Pin the canonical resolved payload (action:ticket:body|to:...|cc:...) so the
        // approval-time format can never drift from the stage/direct idempotency keys.
        $this->assertSame(hash('sha256', 'send_reply:'.$ticket->id.':Same body.|to:client@thread.test|cc:'), $logA->content_hash);
        $this->assertSame(hash('sha256', 'send_reply:'.$ticket->id.':Same body.|to:client@thread.test|cc:vendor@thread.test'), $logB->content_hash);
    }

    public function test_approved_content_hash_binds_final_recipients_for_stage_email(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Bob', 'last_name' => 'Second', 'email' => 'bob@example.test', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress, 'closed_at' => null,
        ]);
        $makeRun = fn (string $seed) => TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'stage_email',
            'content_hash' => hash('sha256', $seed), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original staged body.',
        ]);
        $runA = $makeRun('seed-a');
        $runB = $makeRun('seed-b');
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->twice()->andReturnNull());

        $service = app(TechnicianApprovalService::class);
        $this->assertSame('sent', $service->approveStagedEmail($runA, 'Same body.', $actor->id)->status);
        $this->assertSame('sent', $service->approveStagedEmail($runB, 'Same body.', $actor->id, [], ['bob@example.test'])->status);

        $logA = $this->executedLogForRun($runA);
        $logB = $this->executedLogForRun($runB);

        $this->assertNotSame($logA->content_hash, $logB->content_hash);
        $this->assertSame(hash('sha256', 'stage_email:'.$ticket->id.':Same body.|to:c@example.com|cc:'), $logA->content_hash);
        $this->assertSame(hash('sha256', 'stage_email:'.$ticket->id.':Same body.|to:c@example.com|cc:bob@example.test'), $logB->content_hash);
    }

    public function test_stage_email_approval_records_final_recipients_durably_before_send_even_on_delivery_failure(): void
    {
        // psa-w4e0 revise: the exact approved audience — including the operator-added
        // custom address — must survive on the append-only audit row even when the
        // external send fails, so exfil forensics never depend on Graph accepting.
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldStageRun($actor, 'stage_email');
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')
            ->once()->andThrow(new \RuntimeException('graph down')));

        $result = app(TechnicianApprovalService::class)
            ->approveStagedEmail($run, 'Audit summary.', $actor->id, [], ['auditor@partner.test']);

        // The note is committed and the run is Done; only delivery failed (logged).
        $this->assertSame('sent', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $log = $this->executedLogForRun($run);
        $this->assertSame(
            ['to' => 'c@example.com', 'cc' => ['auditor@partner.test'], 'custom' => ['auditor@partner.test']],
            $log->approved_recipients,
        );
        // The executed hash binds that same audience.
        $this->assertSame(hash('sha256', 'stage_email:'.$run->ticket_id.':Audit summary.|to:c@example.com|cc:auditor@partner.test'), $log->content_hash);
        // Counts-only summary is unchanged: addresses live in approved_recipients, never the summary.
        $this->assertStringContainsString('To 1, CC 1', (string) $log->summary);
        $this->assertStringContainsString('1 outside known contacts', (string) $log->summary);
        $this->assertStringNotContainsString('auditor@partner.test', (string) $log->summary);
    }

    public function test_send_reply_approval_records_final_recipients_durably_before_send_even_on_delivery_failure(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        [$run, $ticket] = $this->seedSendReplyRunWithThread($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')
            ->once()->andThrow(new \RuntimeException('graph down')));

        $result = app(TechnicianApprovalService::class)
            ->approveAndSend($run, 'Body.', $actor->id, [], ['vendor@thread.test']);

        $this->assertSame('sent', $result->status);

        $log = $this->executedLogForRun($run);
        $this->assertSame(
            ['to' => 'client@thread.test', 'cc' => ['vendor@thread.test'], 'custom' => []],
            $log->approved_recipients,
        );
        $this->assertStringNotContainsString('client@thread.test', (string) $log->summary);
        $this->assertStringNotContainsString('vendor@thread.test', (string) $log->summary);
    }
}
