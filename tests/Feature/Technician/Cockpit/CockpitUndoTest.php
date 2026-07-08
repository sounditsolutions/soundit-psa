<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\PhoneDirectoryListType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitUndoToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CockpitUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function cockpitRun(string $actionType, TechnicianRunState $state): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => $actionType === 'propose_close' ? TicketStatus::Closed : TicketStatus::InProgress,
        ]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', $actionType.uniqid('', true)),
            'state' => $state,
            'proposed_content' => 'undo me',
            'updated_at' => now(),
        ]);
    }

    private function undoUrl(string $targetType, int $targetId, string $action, ?User $user = null, array $extra = []): string
    {
        return app(CockpitUndoToken::class)->issue(
            $targetType,
            $targetId,
            $action,
            ($user ?? $this->user)->id,
            $extra,
        )['url'];
    }

    private function runUndoUrl(TechnicianRun $run, string $action, ?User $user = null, array $extra = []): string
    {
        $run->refresh();

        return $this->undoUrl('run', $run->id, $action, $user, array_merge([
            'run_updated_at' => $run->getRawOriginal('updated_at'),
        ], $extra));
    }

    private function callUndoUrl(PhoneCall $call, string $action, ?User $user = null, array $extra = []): string
    {
        $call->refresh();

        return $this->undoUrl('call', $call->id, $action, $user, array_merge([
            'call_followed_up_at' => $call->getRawOriginal('followed_up_at'),
        ], $extra));
    }

    private function closeStatusNote(TechnicianRun $run): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $run->ticket_id,
            'author_id' => $this->user->id,
            'body' => 'Closed by AI Technician (operator-approved).',
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::InProgress,
            'status_to' => TicketStatus::Closed,
            'noted_at' => now(),
        ]);
    }

    public function test_undo_reopens_an_approved_close_and_restages_the_run(): void
    {
        $run = $this->cockpitRun('propose_close', TechnicianRunState::Done);
        $note = $this->closeStatusNote($run);

        $this->actingAs($this->user)
            ->postJson($this->runUndoUrl($run, 'approve-close', extra: ['status_note_id' => $note->id]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'undone')
            ->assertJsonPath('counts.closures', 1);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(TicketStatus::InProgress, $run->ticket->fresh()->status);
    }

    public function test_undo_close_requires_the_matching_status_change_marker(): void
    {
        $run = $this->cockpitRun('propose_close', TechnicianRunState::Done);

        $this->actingAs($this->user)
            ->postJson($this->runUndoUrl($run, 'approve-close'))
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(TicketStatus::Closed, $run->ticket->fresh()->status);
    }

    public function test_undo_close_rejects_when_a_later_status_change_exists(): void
    {
        $run = $this->cockpitRun('propose_close', TechnicianRunState::Done);
        $note = $this->closeStatusNote($run);
        TicketNote::create([
            'ticket_id' => $run->ticket_id,
            'author_id' => $this->user->id,
            'body' => 'Later status change.',
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::Closed,
            'status_to' => TicketStatus::InProgress,
            'noted_at' => now()->addSecond(),
        ]);

        $this->actingAs($this->user)
            ->postJson($this->runUndoUrl($run, 'approve-close', extra: ['status_note_id' => $note->id]))
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(TicketStatus::Closed, $run->ticket->fresh()->status);
    }

    public function test_undo_restages_denied_flag_and_intake_actions(): void
    {
        $denied = $this->cockpitRun('send_reply', TechnicianRunState::Denied);
        $ackedFlag = $this->cockpitRun('flag_attention', TechnicianRunState::Done);
        $dismissedFlag = $this->cockpitRun('flag_attention', TechnicianRunState::Denied);
        $dismissedIntake = $this->cockpitRun('intake_route', TechnicianRunState::Done);

        foreach ([
            [$denied, 'hold'],
            [$ackedFlag, 'ack-flag'],
            [$dismissedFlag, 'dismiss-flag'],
            [$dismissedIntake, 'dismiss-intake'],
        ] as [$run, $action]) {
            $this->actingAs($this->user)
                ->postJson($this->runUndoUrl($run, $action))
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('status', 'undone');
        }

        $this->assertSame(TechnicianRunState::AwaitingApproval, $denied->fresh()->state);
        $this->assertSame(TechnicianRunState::Flagged, $ackedFlag->fresh()->state);
        $this->assertSame(TechnicianRunState::Flagged, $dismissedFlag->fresh()->state);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $dismissedIntake->fresh()->state);
    }

    public function test_stale_run_undo_token_does_not_undo_later_transition(): void
    {
        $run = $this->cockpitRun('send_reply', TechnicianRunState::Denied);
        $url = $this->runUndoUrl($run, 'hold');

        $run->forceFill(['updated_at' => now()->addMinute()])->save();

        $this->actingAs($this->user)
            ->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }

    public function test_undo_spam_call_clears_followup_and_removes_operator_block(): void
    {
        $call = PhoneCall::create([
            'call_uuid' => 'undo-call',
            'from_number' => '+12223334444',
            'status' => CallStatus::Completed,
            'intake_spam_score' => 0.89,
        ]);
        $call->followed_up_at = now();
        $call->followed_up_by = $this->user->id;
        $call->save();

        PhoneDirectoryEntry::create([
            'phone_number' => '+12223334444',
            'list_type' => PhoneDirectoryListType::Blocked,
            'reason' => 'AI intake: marked spam by operator',
            'added_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->callUndoUrl($call, 'block-number', extra: ['directory_entry_id' => PhoneDirectoryEntry::where('phone_number', '+12223334444')->value('id')]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'undone')
            ->assertJsonPath('counts.intake', 1);

        $this->assertNull($call->fresh()->followed_up_at);
        $this->assertSame(0, PhoneDirectoryEntry::where('phone_number', '+12223334444')->count());
    }

    public function test_undo_spam_call_does_not_delete_pre_existing_directory_entry(): void
    {
        $call = PhoneCall::create([
            'call_uuid' => 'undo-call-existing-directory',
            'from_number' => '+12223335555',
            'status' => CallStatus::Completed,
            'intake_spam_score' => 0.89,
        ]);

        $entry = PhoneDirectoryEntry::create([
            'phone_number' => '+12223335555',
            'list_type' => PhoneDirectoryListType::Blocked,
            'reason' => 'AI intake: marked spam by operator',
            'added_by_user_id' => $this->user->id,
        ]);
        $entry->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();

        $call->followed_up_at = now();
        $call->followed_up_by = $this->user->id;
        $call->save();

        $this->actingAs($this->user)
            ->postJson($this->callUndoUrl($call, 'block-number'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($call->fresh()->followed_up_at);
        $this->assertTrue($entry->fresh()->exists);
    }

    public function test_undo_rejects_non_optimistic_and_expired_actions(): void
    {
        $sent = $this->cockpitRun('send_reply', TechnicianRunState::Done);
        $expired = $this->cockpitRun('send_reply', TechnicianRunState::Denied);
        $expired->forceFill(['updated_at' => now()->subMinutes(6)])->save();

        $this->actingAs($this->user)
            ->postJson($this->runUndoUrl($sent, 'send-reply'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->actingAs($this->user)
            ->postJson($this->runUndoUrl($expired, 'hold'))
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(TechnicianRunState::Denied, $expired->fresh()->state);
    }

    public function test_undo_rejects_unsigned_or_wrong_user_requests(): void
    {
        $run = $this->cockpitRun('send_reply', TechnicianRunState::Denied);
        $otherUser = User::factory()->create();

        $this->actingAs($this->user)
            ->postJson(route('cockpit.undo'), [
                'target_type' => 'run',
                'target_id' => $run->id,
                'action' => 'hold',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        $this->actingAs($otherUser)
            ->postJson($this->runUndoUrl($run, 'hold', $this->user))
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }

    public function test_undo_spam_call_requires_same_operator(): void
    {
        $otherUser = User::factory()->create();
        $call = PhoneCall::create([
            'call_uuid' => 'undo-call-other-user',
            'from_number' => '+12223336666',
            'status' => CallStatus::Completed,
            'intake_spam_score' => 0.89,
        ]);
        $call->followed_up_at = now();
        $call->followed_up_by = $otherUser->id;
        $call->save();

        $this->actingAs($this->user)
            ->postJson($this->callUndoUrl($call, 'not-spam'))
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertNotNull($call->fresh()->followed_up_at);
    }
}
