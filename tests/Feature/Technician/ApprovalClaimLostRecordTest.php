<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #6324: the approval service's RunClaimLostException rollback, on the entry points the #6303
 * tests did not reach: approveMerge, approveAssetMerge, intakeMerge and approveStagedBodyAction
 * (through approveStagedPublicNote and approveStagedEmail). The claim is replaced just before
 * the close's fenced UPDATE, so advanceTo(Done) loses inside the gate transaction.
 *
 * #6311: the rollback removes the gate's own audit row, so claimLost() writes the durable
 * record outside it: one warning line with the run id and action type.
 *
 * Synthetic values only (G-13).
 */
class ApprovalClaimLostRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $this->client = Client::factory()->create();
    }

    /** Replace the claim just before the fenced close (the only technician_runs UPDATE binding Done). */
    private function loseTheClaimBeforeTheFencedClose(TechnicianRun $run): void
    {
        $lost = false;
        DB::beforeExecuting(function (string $sql, array $bindings) use ($run, &$lost): void {
            if (! $lost && str_starts_with($sql, 'update') && str_contains($sql, 'technician_runs')
                && in_array(TechnicianRunState::Done->value, $bindings, true)) {
                $lost = true;
                TechnicianRun::whereKey($run->id)->update(['claimed_at' => now()->addMinute()]);
            }
        });
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /** The claim-lost result, the durable warning line, and the run still under the other claim. */
    private function assertClaimLostAndRecorded(TechnicianRun $run, \App\Services\Technician\TechnicianApprovalResult $result, \Closure $logged): void
    {
        $this->assertSame('gate_declined', $result->status);
        $this->assertSame('Nothing was applied: this request no longer holds the run, so its changes were rolled back. Check the run\'s current state before acting on it again.', $result->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is still in place');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->count(), 'the executed row was rolled back');
        $records = array_values(array_filter($logged(), fn (MessageLogged $m) => str_contains($m->message, 'approval rolled back')));
        $this->assertCount(1, $records, '#6311: the durable record of the rollback');
        $this->assertSame('warning', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'action' => $run->action_type], $records[0]->context);
    }

    private function openTicket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(['client_id' => $this->client->id, 'status' => TicketStatus::InProgress, 'closed_at' => null] + $attributes);
    }

    public function test_approve_merge_rolls_back_and_records_a_lost_claim(): void
    {
        $primary = $this->openTicket();
        $secondary = $this->openTicket();
        $pending = TechnicianRun::create([
            'ticket_id' => $secondary->id, 'client_id' => $this->client->id, 'action_type' => 'stage_email',
            'content_hash' => hash('sha256', 'pending-6324'), 'state' => TechnicianRunState::AwaitingApproval,
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $primary->id, 'client_id' => $this->client->id, 'action_type' => 'propose_merge',
            'content_hash' => hash('sha256', 'merge-6324'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Duplicate.',
            'proposed_meta' => ['primary_ticket_id' => $primary->id, 'secondary_ticket_id' => $secondary->id],
        ]);
        $this->loseTheClaimBeforeTheFencedClose($run);
        $logged = $this->captureLogs();

        $result = app(TechnicianApprovalService::class)->approveMerge($run, $this->actor->id);

        $this->assertClaimLostAndRecorded($run, $result, $logged);
        $this->assertNull($secondary->fresh()->parent_ticket_id, 'the merge was rolled back');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $pending->fresh()->state, 'the sibling supersede was rolled back');
    }

    public function test_intake_merge_rolls_back_and_records_a_lost_claim(): void
    {
        $suggested = $this->openTicket();
        $created = $this->openTicket();
        $run = TechnicianRun::create([
            'ticket_id' => $created->id, 'client_id' => $this->client->id, 'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake-6324'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_meta' => ['decision' => 'attach', 'suggested_ticket_id' => $suggested->id, 'attached' => false, 'created_ticket_id' => $created->id],
        ]);
        $this->loseTheClaimBeforeTheFencedClose($run);
        $logged = $this->captureLogs();

        $result = app(TechnicianApprovalService::class)->intakeMerge($run, $suggested->id, $suggested->id, $this->actor->id);

        $this->assertClaimLostAndRecorded($run, $result, $logged);
        $this->assertNull($created->fresh()->parent_ticket_id, 'the merge was rolled back');
    }

    public function test_approve_asset_merge_rolls_back_and_records_a_lost_claim(): void
    {
        $ticket = $this->openTicket();
        $survivor = Asset::factory()->create(['client_id' => $this->client->id]);
        $duplicate = Asset::factory()->create(['client_id' => $this->client->id]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'action_type' => 'propose_asset_merge',
            'content_hash' => hash('sha256', 'asset-merge-6324'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Same machine.',
            'proposed_meta' => ['survivor_asset_id' => $survivor->id, 'duplicate_asset_id' => $duplicate->id],
        ]);
        $this->loseTheClaimBeforeTheFencedClose($run);
        $logged = $this->captureLogs();

        $result = app(TechnicianApprovalService::class)->approveAssetMerge($run, $this->actor->id);

        $this->assertClaimLostAndRecorded($run, $result, $logged);
        $this->assertNull(Asset::withTrashed()->find($duplicate->id)->merged_into_asset_id, 'the asset merge was rolled back');
    }

    public function test_a_staged_public_note_rolls_back_and_records_a_lost_claim(): void
    {
        $ticket = $this->openTicket();
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'action_type' => 'stage_public_note',
            'content_hash' => hash('sha256', 'note-6324'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Staged body.',
        ]);
        $this->loseTheClaimBeforeTheFencedClose($run);
        $logged = $this->captureLogs();

        $result = app(TechnicianApprovalService::class)->approveStagedPublicNote($run, 'Edited body.', $this->actor->id);

        $this->assertClaimLostAndRecorded($run, $result, $logged);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(), 'the note was rolled back');
    }

    public function test_a_staged_email_rolls_back_sends_nothing_and_records_a_lost_claim(): void
    {
        $contact = Person::create([
            'client_id' => $this->client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c6324@example.test', 'is_active' => true,
        ]);
        $ticket = $this->openTicket(['contact_id' => $contact->id]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'action_type' => 'stage_email',
            'content_hash' => hash('sha256', 'email-6324'), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Staged body.',
        ]);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldNotReceive('sendTicketReplyNote'));
        $this->loseTheClaimBeforeTheFencedClose($run);
        $logged = $this->captureLogs();

        $result = app(TechnicianApprovalService::class)->approveStagedEmail($run, 'Edited body.', $this->actor->id);

        $this->assertClaimLostAndRecorded($run, $result, $logged);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(), 'the note was rolled back');
    }
}
