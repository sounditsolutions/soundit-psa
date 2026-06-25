<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CockpitQueryTest extends TestCase
{
    use RefreshDatabase;

    private function heldRun(): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Hello, we can help.',
        ]);
    }

    public function test_claim_for_execution_is_won_once(): void
    {
        $run = $this->heldRun();

        $this->assertTrue($run->claimForExecution());
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);

        // A second claim (replay / double-tap) loses — the run is no longer awaiting.
        $this->assertFalse($run->fresh()->claimForExecution());
    }

    public function test_deny_and_supersede_transitions(): void
    {
        $a = $this->heldRun();
        $a->deny();
        $this->assertSame(TechnicianRunState::Denied, $a->fresh()->state);

        $b = $this->heldRun();
        $b->markSuperseded();
        $this->assertSame(TechnicianRunState::Superseded, $b->fresh()->state);
    }

    public function test_pending_drafts_are_urgency_sorted_and_count_matches(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $this->assertSame(0, $query->pendingCount());

        $client = Client::factory()->create();
        $old = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => now()->addDays(5)]);
        $overdue = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => now()->subDay()]);

        foreach ([$old, $overdue] as $t) {
            TechnicianRun::create([
                'ticket_id' => $t->id, 'client_id' => $client->id,
                'action_type' => 'send_reply', 'content_hash' => hash('sha256', 'r'.$t->id),
                'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'draft',
            ]);
        }

        $drafts = $query->pendingDrafts();
        $this->assertSame(2, $query->pendingCount());
        // Overdue ticket's draft sorts first.
        $this->assertSame($overdue->id, $drafts->first()->ticket_id);
    }

    public function test_needs_attention_lists_acked_but_undrafted_active_client_tickets(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $client = Client::factory()->create(); // active
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => \App\Enums\TicketStatus::New]);

        // The AI acked it (ai_authored Reply note) but produced no held draft → needs a human.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => \App\Enums\WhoType::Agent,
            'ai_authored' => true, 'body' => 'ack', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        $needs = $query->needsAttention();
        $this->assertTrue($needs->contains('id', $ticket->id));

        // Once a held draft exists, it leaves the "needs you" lane (it's in the queue instead).
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('b', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);
        $this->assertFalse($query->needsAttention()->contains('id', $ticket->id));
    }

    // ── Fix 4: Lane-ordering — propose_close sorts AFTER client-facing actions ──

    /**
     * A propose_close run (even an older/overdue one) must sort AFTER a send_reply run.
     * Client-facing approvals (send_reply, propose_resolution) are time-sensitive;
     * a stale-close proposal can wait.
     */
    public function test_propose_close_sorts_after_send_reply_even_when_older_and_overdue(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $client = Client::factory()->create();

        // Close ticket: overdue AND created first (both criteria that would sort it first
        // under the old overdue/age ordering alone).
        $closeTicket = Ticket::factory()->create([
            'client_id' => $client->id,
            'due_at' => now()->subDay(), // overdue
        ]);

        // Reply ticket: not overdue, created second (newer).
        $replyTicket = Ticket::factory()->create([
            'client_id' => $client->id,
            'due_at' => null,
        ]);

        $closeRun = TechnicianRun::create([
            'ticket_id' => $closeTicket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'close'.$closeTicket->id),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'stale close proposal',
            'created_at' => now()->subHour(), // older
        ]);

        $replyRun = TechnicianRun::create([
            'ticket_id' => $replyTicket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
            'content_hash' => hash('sha256', 'reply'.$replyTicket->id),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'reply draft',
            'created_at' => now(), // newer
        ]);

        $drafts = $query->pendingDrafts();

        // The send_reply must come first regardless of age/overdue status.
        $this->assertSame($replyRun->id, $drafts->first()->id,
            'send_reply must sort before propose_close.');
        $this->assertSame($closeRun->id, $drafts->last()->id,
            'propose_close must sort last.');
    }

    /**
     * A reply-only set must remain ordered by overdue-first, then oldest-first
     * (within-lane ordering preserved — no regression).
     */
    public function test_reply_only_set_preserves_overdue_then_age_ordering(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $client = Client::factory()->create();

        // Older, non-overdue reply.
        $oldTicket = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => null]);
        $oldRun = TechnicianRun::create([
            'ticket_id' => $oldTicket->id, 'client_id' => $client->id,
            'action_type' => 'send_reply', 'content_hash' => hash('sha256', 'a'.$oldTicket->id),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'old',
            'created_at' => now()->subHour(),
        ]);

        // Newer, overdue reply — must sort first (overdue beats age within lane).
        $overdueTicket = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => now()->subDay()]);
        $overdueRun = TechnicianRun::create([
            'ticket_id' => $overdueTicket->id, 'client_id' => $client->id,
            'action_type' => 'send_reply', 'content_hash' => hash('sha256', 'b'.$overdueTicket->id),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'overdue',
            'created_at' => now(),
        ]);

        $drafts = $query->pendingDrafts();

        $this->assertSame($overdueRun->id, $drafts->first()->id,
            'Overdue send_reply must still sort before non-overdue send_reply (within-lane ordering preserved).');
        $this->assertSame($oldRun->id, $drafts->last()->id);
    }

    public function test_needs_attention_temporal_anchor_pre_ack_human_reply_does_not_suppress(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => \App\Enums\TicketStatus::New]);

        // Human (non-AI agent) replied BEFORE the AI ack.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Alice', 'who_type' => \App\Enums\WhoType::Agent,
            'ai_authored' => false, 'body' => 'On it', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now()->subHour(),
        ]);

        // AI ack posted after the human reply.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => \App\Enums\WhoType::Agent,
            'ai_authored' => true, 'body' => 'ack', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        // Pre-ack human reply must NOT suppress — ticket still needs attention.
        $this->assertTrue($query->needsAttention()->contains('id', $ticket->id));

        // Now a human replies AFTER the ack — ticket should leave the lane.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Bob', 'who_type' => \App\Enums\WhoType::Agent,
            'ai_authored' => false, 'body' => 'Following up', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now()->addMinute(),
        ]);

        $this->assertFalse($query->needsAttention()->contains('id', $ticket->id));
    }
}
