<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CockpitActionJsonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
    }

    private function ticketWithContact(array $ticketAttrs = []): Ticket
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'contact@example.com',
            'is_active' => true,
        ]);

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
        ], $ticketAttrs));
    }

    private function cockpitRun(string $actionType, array $attrs = []): TechnicianRun
    {
        $ticket = $attrs['ticket'] ?? $this->ticketWithContact();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', $actionType.uniqid('', true)),
            'state' => $attrs['state'] ?? TechnicianRunState::AwaitingApproval,
            'proposed_content' => $attrs['proposed_content'] ?? 'Draft body.',
            'proposed_meta' => $attrs['proposed_meta'] ?? [],
            'confidence' => $attrs['confidence'] ?? 0.9,
        ]);
    }

    public function test_approve_returns_json_payload_with_counts_when_requested(): void
    {
        $run = $this->cockpitRun('stage_public_note', ['proposed_content' => 'Original note.']);
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->never());

        $this->actingAs($this->user)
            ->postJson(route('cockpit.approve', $run), ['body' => 'Edited public note.'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('message', 'Public note published.')
            ->assertJsonPath('counts.replies', 0)
            ->assertJsonStructure(['ok', 'status', 'message', 'counts' => ['replies', 'closures', 'actions', 'intake', 'flagged', 'needs', 'pending', 'total']]);
    }

    public function test_reversible_json_actions_return_signed_undo_url(): void
    {
        $run = $this->cockpitRun('send_reply');

        $response = $this->actingAs($this->user)
            ->postJson(route('cockpit.deny', $run))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('undo.action', 'hold')
            ->assertJsonStructure(['undo' => ['url', 'action']]);

        $undoUrl = $response->json('undo.url');
        $this->assertIsString($undoUrl);
        $this->assertStringContainsString('signature=', $undoUrl);
        $this->assertStringContainsString('user_id='.$this->user->id, $undoUrl);
    }

    public function test_deny_json_does_not_override_already_handled_run(): void
    {
        $run = $this->cockpitRun('send_reply', ['state' => TechnicianRunState::Done]);

        $response = $this->actingAs($this->user)
            ->postJson(route('cockpit.deny', $run))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_deny_json_does_not_handle_intake_runs(): void
    {
        $run = $this->cockpitRun('intake_route');

        $response = $this->actingAs($this->user)
            ->postJson(route('cockpit.deny', $run))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_already_closed_ticket_approval_returns_no_close_undo(): void
    {
        $ticket = $this->ticketWithContact([
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
            'resolved_at' => now(),
        ]);
        $run = $this->cockpitRun('propose_close', ['ticket' => $ticket]);

        $response = $this->actingAs($this->user)
            ->postJson(route('cockpit.approve', $run))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_deny_keeps_no_js_redirect_fallback(): void
    {
        $run = $this->cockpitRun('send_reply');

        $this->actingAs($this->user)
            ->post(route('cockpit.deny', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionHas('success', 'Draft dismissed; the ticket is back with your team.');
    }

    public function test_optimistic_routes_return_json_counts(): void
    {
        $flag = $this->cockpitRun('flag_attention', ['state' => TechnicianRunState::Flagged]);
        $intake = $this->cockpitRun('intake_route');
        $call = PhoneCall::create([
            'call_uuid' => 'json-spam',
            'from_number' => '+12223334444',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.87,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.acknowledge', $flag))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('counts.flagged', 0);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.intake-dismiss', $intake))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'done');

        $this->actingAs($this->user)
            ->postJson(route('cockpit.intake-spam-block', $call))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'done')
            ->assertJsonStructure(['undo' => ['url', 'action']])
            ->assertJsonPath('counts.intake', 0);
    }

    public function test_spam_block_json_does_not_overwrite_already_handled_call(): void
    {
        $otherUser = User::factory()->create();
        $call = PhoneCall::create([
            'call_uuid' => 'json-spam-already-handled',
            'from_number' => '+12223337777',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.87,
        ]);
        $call->followed_up_at = now();
        $call->followed_up_by = $otherUser->id;
        $call->save();

        $response = $this->actingAs($this->user)
            ->postJson(route('cockpit.intake-spam-block', $call))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertSame($otherUser->id, $call->fresh()->followed_up_by);
        $this->assertSame(0, \App\Models\PhoneDirectoryEntry::where('phone_number', '+12223337777')->count());
    }

    public function test_not_spam_json_does_not_overwrite_already_handled_call(): void
    {
        $otherUser = User::factory()->create();
        $call = PhoneCall::create([
            'call_uuid' => 'json-not-spam-already-handled',
            'from_number' => '+12223338888',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.87,
        ]);
        $call->followed_up_at = now();
        $call->followed_up_by = $otherUser->id;
        $call->save();

        $response = $this->actingAs($this->user)
            ->postJson(route('prospects.dismiss', $call))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertSame($otherUser->id, $call->fresh()->followed_up_by);
    }

    public function test_not_spam_json_does_not_handle_call_already_linked_to_ticket(): void
    {
        $ticket = $this->ticketWithContact();
        $call = PhoneCall::create([
            'call_uuid' => 'json-not-spam-linked-ticket',
            'from_number' => '+12223337700',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.87,
        ]);
        $call->ticket_id = $ticket->id;
        $call->client_id = $ticket->client_id;
        $call->save();

        $response = $this->actingAs($this->user)
            ->postJson(route('prospects.dismiss', $call))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertArrayNotHasKey('undo', $response->json());
        $this->assertNull($call->fresh()->followed_up_at);
    }

    public function test_second_spam_call_undo_does_not_delete_first_call_block(): void
    {
        $first = PhoneCall::create([
            'call_uuid' => 'json-spam-first',
            'from_number' => '+12223339999',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.87,
        ]);
        $second = PhoneCall::create([
            'call_uuid' => 'json-spam-second',
            'from_number' => '+12223339999',
            'status' => \App\Enums\CallStatus::Completed,
            'intake_spam_score' => 0.88,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.intake-spam-block', $first))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $undoUrl = $this->actingAs($this->user)
            ->postJson(route('cockpit.intake-spam-block', $second))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('undo.url');

        $this->actingAs($this->user)
            ->postJson($undoUrl)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($second->fresh()->followed_up_at);
        $this->assertSame(1, \App\Models\PhoneDirectoryEntry::where('phone_number', '+12223339999')->count());
    }
}
