<?php

namespace Tests\Feature\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Agent\FlagAttentionTool;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Increment H Task 3 — EscalationNotifier wired into flag_attention.
 *
 * Tests that:
 *  1. When escalation is enabled AND a NEW flag is created, EscalationNotifier::notify
 *     is called exactly once with the correct arguments.
 *  2. When escalation is disabled (the default), notify is NEVER called — but the
 *     flag IS still recorded (dormant = behaviour unchanged).
 *  3. A duplicate/idempotent re-flag (same reason, already Flagged) hits the
 *     early-return and does NOT re-notify — no spam.
 *  4. An empty reason is rejected before any flag or notify.
 *  5. A revived flag (same blocker, previously resolved) DOES notify again —
 *     a recurring need re-surfaces.
 */
class FlagAttentionEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function openTicketWithClient(?Client $client = null, array $attrs = []): Ticket
    {
        $client ??= Client::factory()->create();

        return Ticket::factory()->for($client)->create(array_merge(['status' => TicketStatus::InProgress], $attrs));
    }

    private function existingFlagFor(Ticket $ticket, array $meta = []): TechnicianRun
    {
        $baseMeta = ['category' => 'needs_decision', 'reason' => 'already in front of a human'];

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'flag_attention',
            'content_hash' => hash('sha256', 'existing-flag-'.$ticket->id),
            'state' => TechnicianRunState::Flagged,
            'proposed_content' => 'already in front of a human',
            'proposed_meta' => array_merge($baseMeta, $meta),
            'tokens_used' => 0,
        ]);
    }

    private function humanNote(Ticket $ticket, array $attrs = []): TicketNote
    {
        return TicketNote::create(array_merge([
            'ticket_id' => $ticket->id,
            'author_id' => User::factory()->create()->id,
            'author_name' => 'Staff Member',
            'body' => 'I am working this ticket.',
            'note_type' => NoteType::Note->value,
            'who_type' => WhoType::Agent->value,
            'ai_authored' => false,
            'noted_at' => now()->subHour(),
        ], $attrs));
    }

    private function assertSuppressed(TechnicianRun $run, string $kind): void
    {
        $escalation = $run->fresh()->proposed_meta['escalation'] ?? null;

        $this->assertIsArray($escalation, 'suppressed runs must carry escalation metadata');
        $this->assertSame('suppressed', $escalation['status'] ?? null);
        $this->assertSame($kind, $escalation['suppression_kind'] ?? null);
        $this->assertSame('duplicate_client_escalation', $escalation['noise_to_owner'] ?? null);
        $this->assertArrayHasKey('suppressed_at', $escalation);
        $this->assertArrayNotHasKey('notified_at', $escalation, 'suppressed runs must never be picked up by the re-ping sweep');
    }

    /** Test 1: enabled + new flag → notify called once with the correct args. */
    public function test_enabled_and_new_flag_notifies_once(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(function (
                Ticket $t,
                TechnicianRun $run,
                FlagAttentionCategory $cat,
                string $blocker,
            ) use ($ticket): bool {
                return $t->is($ticket)
                    && $run->action_type === 'flag_attention'
                    && $cat === FlagAttentionCategory::NeedsDecision
                    && $blocker === 'Client demands a refund I cannot authorise.';
            });

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'Client demands a refund I cannot authorise.',
            'category' => 'needs_decision',
        ]);

        // The Flagged run IS still recorded (additive — core behaviour unchanged).
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $this->assertNotNull($run, 'flag_attention run must be created');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
    }

    /** Test 2: disabled (default) → notify NEVER called, flag IS still recorded. */
    public function test_disabled_records_flag_but_does_not_notify(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        // No setting → escalationEnabled() returns false (dormant by default).

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'Need a decision on billing.',
            'category' => 'needs_decision',
        ]);

        // Dormant: flag IS recorded exactly as before, no notify.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $this->assertNotNull($run, 'flag must still be recorded when escalation is disabled');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
    }

    /** Test 3: duplicate (already Flagged) → notify exactly ONCE total, not twice. */
    public function test_duplicate_flag_does_not_re_notify(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        // Only the first call (new flag) should notify. The second is an idempotent
        // duplicate that hits the "already flagged" early-return before the wire-in.
        $notifier->shouldReceive('notify')->once();

        $tool = app(FlagAttentionTool::class);
        $input = ['reason' => 'Same blocking reason.', 'category' => 'uncertain'];

        $tool->execute($ticket, $input); // first → new flag → notify fires
        $tool->execute($ticket, $input); // second → already Flagged → early-return → NO re-notify
    }

    /** Test 4: empty reason → gate rejected, no flag, no notify. */
    public function test_empty_reason_no_flag_no_notify(): void
    {
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => '',
            'category' => 'other',
        ]);

        $this->assertSame(0, TechnicianRun::where('action_type', 'flag_attention')->count());
    }

    /** Test 5: revived flag (resolved then re-raised) → notify fires again. */
    public function test_revived_flag_notifies_again(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        // notify must fire for the first flag AND after the revive — total 2.
        $notifier->shouldReceive('notify')->twice();

        $tool = app(FlagAttentionTool::class);
        $input = ['reason' => 'Recurring need for a person.', 'category' => 'needs_decision'];

        // First flag → notify #1.
        $tool->execute($ticket, $input);

        // Resolve the flag (acknowledge → Done).
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $run->acknowledgeFlag();
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // Same flag again (same hash) → revived to Flagged → notify #2.
        $tool->execute($ticket, $input);
    }

    public function test_same_client_open_flag_suppresses_new_escalation_but_records_the_flag(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $alreadyFlaggedTicket = $this->openTicketWithClient($client, ['subject' => 'Existing flagged issue']);
        $current = $this->openTicketWithClient($client, ['subject' => 'Sibling issue']);
        $existing = $this->existingFlagFor($alreadyFlaggedTicket, [
            'escalation' => [
                'category' => 'needs_decision',
                'recipient_user_id' => User::factory()->create()->id,
                'notified_at' => now()->subMinutes(5)->toIso8601String(),
                'step' => 0,
            ],
        ]);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'Another issue for the same client needs attention.',
            'category' => 'needs_decision',
        ]);

        $run = TechnicianRun::where('ticket_id', $current->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotNull($run, 'the new flag must still be visible in the cockpit');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
        $this->assertSuppressed($run, 'open_client_flag');
        $this->assertSame($existing->id, $run->fresh()->proposed_meta['escalation']['linked_run_id'] ?? null);
        $this->assertSame($alreadyFlaggedTicket->id, $run->fresh()->proposed_meta['escalation']['linked_ticket_id'] ?? null);
    }

    public function test_same_client_open_flag_without_delivery_metadata_does_not_suppress_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $alreadyFlaggedTicket = $this->openTicketWithClient($client, ['subject' => 'Held before escalation delivery existed']);
        $current = $this->openTicketWithClient($client, ['subject' => 'Fresh escalation']);
        $this->existingFlagFor($alreadyFlaggedTicket);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')->once();

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'No durable record says the owner was already paged.',
            'category' => 'needs_decision',
        ]);

        $run = TechnicianRun::where('ticket_id', $current->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotSame('suppressed', $run->fresh()->proposed_meta['escalation']['status'] ?? null);
    }

    public function test_client_lock_timeout_notifies_instead_of_terminally_suppressing(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $lock = Mockery::mock();
        $lock->shouldReceive('betweenBlockedAttemptsSleepFor')
            ->once()
            ->with(100)
            ->andReturnSelf();
        $lock->shouldReceive('block')
            ->once()
            ->withArgs(fn (int $seconds, callable $callback): bool => $seconds === 180)
            ->andThrow(new LockTimeoutException);

        Cache::shouldReceive('lock')
            ->once()
            ->with("agent:client-escalation-noise:{$ticket->client_id}", 180)
            ->andReturn($lock);

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')->once();

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'The client lock is stale but this escalation still needs an owner page.',
            'category' => 'needs_decision',
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotSame('suppressed', $run->fresh()->proposed_meta['escalation']['status'] ?? null);
    }

    public function test_assigned_same_client_sibling_suppresses_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $assignee = User::factory()->create(['name' => 'Justin']);
        $this->openTicketWithClient($client, [
            'subject' => 'Human already owns this',
            'assignee_id' => $assignee->id,
        ]);
        $current = $this->openTicketWithClient($client, ['subject' => 'Sibling issue']);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'Same client has another possible escalation.',
            'category' => 'needs_overflow',
        ]);

        $run = TechnicianRun::where('ticket_id', $current->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotNull($run);
        $this->assertSuppressed($run, 'human_engaged_sibling_assigned');
    }

    public function test_recent_human_staff_note_on_same_client_sibling_suppresses_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $sibling = $this->openTicketWithClient($client, ['subject' => 'Staff replied here']);
        $this->humanNote($sibling);
        $current = $this->openTicketWithClient($client, ['subject' => 'Sibling issue']);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'Same client has a related issue.',
            'category' => 'uncertain',
        ]);

        $run = TechnicianRun::where('ticket_id', $current->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotNull($run);
        $this->assertSuppressed($run, 'human_engaged_sibling_note');
    }

    public function test_ai_system_stale_and_cross_client_activity_do_not_suppress_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();

        $sameClientSibling = $this->openTicketWithClient($client, ['subject' => 'Only non-qualifying notes']);
        $this->humanNote($sameClientSibling, ['ai_authored' => true]);
        $this->humanNote($sameClientSibling, ['note_type' => NoteType::System->value]);
        $this->humanNote($sameClientSibling, ['noted_at' => now()->subDays(8)]);

        $foreignTicket = $this->openTicketWithClient($otherClient, ['assignee_id' => User::factory()->create()->id]);
        $this->existingFlagFor($foreignTicket);
        $this->humanNote($foreignTicket);

        $current = $this->openTicketWithClient($client, ['subject' => 'Should still notify']);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')->once();

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'No same-client human is actually engaged.',
            'category' => 'other',
        ]);

        $run = TechnicianRun::where('ticket_id', $current->id)
            ->where('action_type', 'flag_attention')
            ->first();

        $this->assertNotSame('suppressed', $run->fresh()->proposed_meta['escalation']['status'] ?? null);
    }

    public function test_same_ticket_prior_flag_with_different_reason_does_not_suppress_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();
        $this->existingFlagFor($ticket);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')->once();

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'A second distinct blocker on the same ticket.',
            'category' => 'needs_decision',
        ]);
    }

    public function test_closed_sibling_flag_does_not_suppress_new_escalation(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $client = Client::factory()->create();
        $closedSibling = $this->openTicketWithClient($client, [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now()->subHour(),
        ]);
        $this->existingFlagFor($closedSibling);
        $current = $this->openTicketWithClient($client);

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')->once();

        app(FlagAttentionTool::class)->execute($current, [
            'reason' => 'The only prior flag is on a closed sibling.',
            'category' => 'needs_decision',
        ]);
    }

    public function test_gate_held_flag_does_not_notify_when_kill_switch_is_engaged(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');
        Setting::setValue('technician_kill_switch', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'This would normally notify.',
            'category' => 'needs_decision',
        ]);

        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'flag_attention',
            'result_status' => 'held',
        ]);
    }

    public function test_gate_held_flag_does_not_notify_when_client_is_excluded(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');
        Setting::setValue('technician_excluded_client_ids', json_encode([$ticket->client_id]));

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'This would normally notify.',
            'category' => 'needs_decision',
        ]);

        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'flag_attention',
            'result_status' => 'held',
        ]);
    }
}
