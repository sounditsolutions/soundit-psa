<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
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

        app(TechnicianApprovalService::class)->deny($run);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
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
}
