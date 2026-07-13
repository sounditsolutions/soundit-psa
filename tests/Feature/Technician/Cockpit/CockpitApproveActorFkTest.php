<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * psa-3s7a — the cockpit approve ENDPOINT, which had no coverage at all: the suite
 * only ever called TechnicianApprovalService at the service level, so the controller
 * path — and crucially the audit write inside TechnicianActionGate::dispatch() — was
 * never exercised. That is how a 500 reached production.
 *
 * The bug: TechnicianConfig::aiActorUserId() returned the configured
 * triage_system_user_id WITHOUT checking the user still exists, and that value is
 * written into technician_action_logs.actor_id — a FOREIGN KEY to users. A stale id
 * (deleted staff member, id carried in from another environment) made the audit
 * INSERT violate the FK, and since audit() runs on EVERY gate dispatch, NOTHING
 * could be approved.
 *
 * The invariant under test: a stale/misconfigured actor setting must degrade the
 * audit ATTRIBUTION (actor_id → null, actor_label still 'ai-technician'), and must
 * NEVER take down the approval surface.
 */
class CockpitApproveActorFkTest extends TestCase
{
    use RefreshDatabase;

    /** An id that is guaranteed not to exist in `users`. */
    private const STALE_ACTOR_ID = '999999';

    /** @return array{0: TechnicianRun, 1: Ticket, 2: Ticket} [$run, $primary, $secondary] */
    private function heldMergeRun(User $noteAuthor): array
    {
        Setting::setValue('technician_action_tiers', json_encode([])); // default-deny → Approve tier
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
            'author_id' => $noteAuthor->id,
            'body' => 'Secondary diagnostic note.',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
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

        return [$run, $primary, $secondary];
    }

    private function heldCloseRun(): TechnicianRun
    {
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Password reset',
        ]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':Resolved.'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Resolved.',
        ]);
    }

    /**
     * (a) THE BUG. A stale triage_system_user_id must not 500 the approve endpoint.
     * The merge must still happen, and the audit row must still be written — with a
     * NULL actor (attribution degraded, never re-attributed to some arbitrary user).
     */
    public function test_approve_merge_endpoint_survives_a_stale_ai_actor_setting(): void
    {
        Log::spy();

        $approver = User::factory()->create(['name' => 'Charlie']);
        Setting::setValue('triage_system_user_id', self::STALE_ACTOR_ID); // points at a user that does not exist
        [$run, $primary, $secondary] = $this->heldMergeRun($approver);

        $response = $this->actingAs($approver)->postJson("/cockpit/runs/{$run->id}/approve");

        // The approval surface stays up.
        $response->assertOk();
        $response->assertJson(['ok' => true, 'status' => 'merged']);

        // The side effect actually happened — this is not a "swallowed the error" pass.
        $this->assertSame($primary->id, $secondary->fresh()->parent_ticket_id);
        $this->assertSame(TicketStatus::Closed, $secondary->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // The audit row exists, with attribution degraded to NULL — not re-pointed at
        // another user, and not silently dropped.
        $log = TechnicianActionLog::where('run_id', $run->id)
            ->where('action_type', 'propose_merge')
            ->where('result_status', 'executed')
            ->sole();
        $this->assertNull($log->actor_id, 'A stale actor must degrade to a NULL actor_id, never a resurrected/arbitrary user.');
        $this->assertSame('ai-technician', $log->actor_label, 'The actor IDENTITY is still on the row via actor_label.');
        $this->assertSame($approver->id, $log->approver_user_id, 'The human approver is still recorded.');

        // Loud: this is a misconfiguration an operator has to fix.
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'AI actor')
                && (int) ($context['configured_user_id'] ?? 0) === (int) self::STALE_ACTOR_ID;
        });
    }

    /**
     * (a2) propose_close is an approval too, and it routes the actor into
     * TicketService::changeStatus() as well as the audit row. A stale actor must not
     * 500 it either — guarding only the audit write would just trade the FK violation
     * for a TypeError on this path.
     */
    public function test_approve_close_endpoint_survives_a_stale_ai_actor_setting(): void
    {
        $approver = User::factory()->create(['name' => 'Charlie']);
        Setting::setValue('triage_system_user_id', self::STALE_ACTOR_ID);
        $run = $this->heldCloseRun();
        $ticketId = $run->ticket_id;

        $response = $this->actingAs($approver)->postJson("/cockpit/runs/{$run->id}/approve");

        $response->assertOk();
        $response->assertJson(['ok' => true, 'status' => 'closed']);

        $this->assertSame(TicketStatus::Closed, Ticket::find($ticketId)->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // The status-change note is authored by nobody rather than by a stale//wrong id.
        $statusNote = TicketNote::where('ticket_id', $ticketId)
            ->where('note_type', NoteType::StatusChange->value)
            ->sole();
        $this->assertNull($statusNote->author_id);

        $log = TechnicianActionLog::where('run_id', $run->id)
            ->where('result_status', 'executed')
            ->sole();
        $this->assertNull($log->actor_id);
    }

    /**
     * (b) The guard must not break CORRECT attribution: a configured actor that really
     * exists is still recorded, exactly. A fix that nulled the actor unconditionally
     * would pass test (a) and destroy the audit trail — this is what catches that.
     */
    public function test_approve_merge_endpoint_records_the_configured_actor_when_it_exists(): void
    {
        $aiActor = User::factory()->create(['name' => 'Chet']);
        $approver = User::factory()->create(['name' => 'Charlie']);
        Setting::setValue('triage_system_user_id', (string) $aiActor->id); // valid
        [$run] = $this->heldMergeRun($approver);

        $response = $this->actingAs($approver)->postJson("/cockpit/runs/{$run->id}/approve");

        $response->assertOk();

        $log = TechnicianActionLog::where('run_id', $run->id)
            ->where('result_status', 'executed')
            ->sole();
        $this->assertSame($aiActor->id, $log->actor_id, 'A valid configured actor must still be attributed exactly.');
        $this->assertSame($approver->id, $log->approver_user_id);
        $this->assertNotSame($log->actor_id, $log->approver_user_id, 'AI actor and human approver are distinct columns.');
    }

    /**
     * (c) The endpoint happy path, which had no coverage: an authenticated user POSTs
     * approve on a held propose_merge run and the tickets are really merged.
     */
    public function test_approve_merge_endpoint_happy_path_merges_the_tickets(): void
    {
        $aiActor = User::factory()->create(['name' => 'Chet']);
        $approver = User::factory()->create(['name' => 'Charlie']);
        Setting::setValue('triage_system_user_id', (string) $aiActor->id);
        [$run, $primary, $secondary] = $this->heldMergeRun($approver);

        $response = $this->actingAs($approver)->postJson("/cockpit/runs/{$run->id}/approve");

        $response->assertOk();
        $response->assertJson(['ok' => true, 'status' => 'merged']);

        $this->assertSame($primary->id, $secondary->fresh()->parent_ticket_id);
        $this->assertSame(TicketStatus::Closed, $secondary->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // The secondary's notes really moved onto the primary.
        $this->assertSame(1, TicketNote::where('ticket_id', $primary->id)
            ->where('body', 'Secondary diagnostic note.')->count());
    }

    /** The endpoint is staff-only. */
    public function test_approve_endpoint_requires_authentication(): void
    {
        $approver = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $approver->id);
        [$run] = $this->heldMergeRun($approver);

        $this->postJson("/cockpit/runs/{$run->id}/approve")->assertUnauthorized();

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }
}
