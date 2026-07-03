<?php

namespace Tests\Feature\Mcp;

use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class PsaActionToolsTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    private function ticketWithContact(?Client $client = null, ?string $email = 'client@example.test'): Ticket
    {
        User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) User::first()->id);

        $client ??= Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => $email,
            'is_active' => true,
        ]);

        return Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer is offline',
        ]);
    }

    private function outboundEmail(Ticket $ticket, TicketNote $note): Email
    {
        return Email::create([
            'graph_id' => null,
            'direction' => EmailDirection::Outbound,
            'from_address' => 'support@example.test',
            'from_name' => null,
            'to_recipients' => [['address' => $ticket->contact->email, 'name' => $ticket->contact->fullName ?? null]],
            'subject' => '['.$ticket->display_id.'] '.$ticket->subject,
            'body_preview' => mb_substr($note->body, 0, 500),
            'body_text' => $note->body,
            'body_html' => '<p>'.e($note->body).'</p>',
            'has_attachments' => false,
            'importance' => 'normal',
            'received_at' => now(),
            'is_read' => true,
            'client_id' => $ticket->client_id,
            'person_id' => $ticket->contact_id,
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_registry_and_runtime_require_explicit_grants_for_psa_action_tools(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_action', $groups);
        $this->assertTrue($groups['psa_action']['sensitive']);

        $actionNames = array_column($groups['psa_action']['tools'], 'name');
        foreach (['send_email', 'stage_email', 'write_public_note', 'stage_public_note', 'propose_merge'] as $name) {
            $this->assertContains($name, $actionNames);
        }

        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('send_email', $legacyNames);
        $this->assertNotContains('write_public_note', $legacyNames);

        $scopedTools = collect($this->tools($this->token(['send_email'])))->keyBy('name');
        $this->assertTrue($scopedTools->has('send_email'));
        $this->assertFalse($scopedTools->has('write_public_note'));

        $schema = $scopedTools['send_email']['inputSchema'];
        $this->assertContains('client_id', $schema['required']);
        $this->assertContains('reason', $schema['required']);
        $this->assertArrayNotHasKey('to', $schema['properties']);
        $this->assertArrayNotHasKey('cc', $schema['properties']);
        $this->assertArrayNotHasKey('subject', $schema['properties']);
    }

    public function test_send_email_directly_sends_to_derived_contact_with_audit_and_action_trail(): void
    {
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact();
        $body = 'We replaced the toner and the printer is back online.';

        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTicketReplyNote')
                ->once()
                ->andReturnUsing(function (Ticket $ticket, TicketNote $note, ?string $toEmail) {
                    $this->assertSame('client@example.test', $toEmail);
                    $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);

                    return $this->outboundEmail($ticket, $note);
                });
        });

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for final confirmation.',
            'body' => $body,
            'to' => 'attacker@example.test',
            'subject' => 'Attacker subject',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(NoteType::Reply, $note->note_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertStringContainsString($body, $note->body);
        $this->assertNotNull($note->email_id);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_email',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:opsbot',
            'approver_user_id' => null,
        ]);

        $audit = McpAuditLog::where('tool_name', 'send_email')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame(mb_strlen($body), $audit->arguments['body_length']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
        $this->assertStringNotContainsString('attacker@example.test', (string) json_encode($audit->arguments));
    }

    public function test_direct_send_email_kill_switch_and_flood_guard_fail_closed(): void
    {
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact();
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        Setting::setValue('technician_kill_switch', '1');
        $blocked = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Client requested an update.',
            'body' => 'Blocked by kill switch.',
        ]);
        $blocked->assertOk();
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('kill-switch', (string) $blocked->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        Setting::setValue('technician_kill_switch', '0');
        $first = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Client requested an update.',
            'body' => 'First body.',
        ]);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'));

        $same = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Replay of the same send.',
            'body' => 'First body.',
        ]);
        $same->assertOk();
        $this->assertFalse((bool) $same->json('result.isError'));
        $this->assertStringContainsString('already', mb_strtolower((string) $same->json('result.content.0.text')));

        $distinct = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Distinct rapid follow-up.',
            'body' => 'Second body.',
        ]);
        $distinct->assertOk();
        $this->assertTrue((bool) $distinct->json('result.isError'));
        $this->assertStringContainsString('rate', (string) $distinct->json('result.content.0.text'));

        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, TechnicianActionLog::where('ticket_id', $ticket->id)->where('action_type', 'send_email')->where('result_status', 'executed')->count());
        $this->assertSame('error', McpAuditLog::where('tool_name', 'send_email')->where('error_message', 'like', '%kill-switch%')->firstOrFail()->status);
    }

    public function test_write_public_note_directly_publishes_with_required_reason_and_rate_guard(): void
    {
        $token = $this->token(['write_public_note']);
        $ticket = $this->ticketWithContact();
        $body = 'We are monitoring this ticket publicly.';
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->never());

        $missingReason = $this->callTool($token, 'write_public_note', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'body' => 'No reason.',
        ]);
        $this->assertTrue((bool) $missingReason->json('result.isError'));
        $this->assertStringContainsString('reason is required', (string) $missingReason->json('result.content.0.text'));

        $first = $this->callTool($token, 'write_public_note', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'This should be visible to the client in the portal.',
            'body' => $body,
            'is_private' => true,
            'note_type' => 'reply',
        ]);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'));

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(NoteType::Note, $note->note_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);

        $same = $this->callTool($token, 'write_public_note', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Replay.',
            'body' => $body,
        ]);
        $this->assertFalse((bool) $same->json('result.isError'));

        $distinct = $this->callTool($token, 'write_public_note', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Rapid second note.',
            'body' => 'A second public note too soon.',
        ]);
        $this->assertTrue((bool) $distinct->json('result.isError'));
        $this->assertStringContainsString('rate', (string) $distinct->json('result.content.0.text'));

        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'write_public_note',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:opsbot',
            'approver_user_id' => null,
        ]);
    }

    public function test_direct_actions_reject_cross_client_scope_without_side_effects(): void
    {
        $token = $this->token(['send_email', 'write_public_note']);
        $ticket = $this->ticketWithContact();
        $otherClient = Client::factory()->create();

        foreach (['send_email', 'write_public_note'] as $tool) {
            $response = $this->callTool($token, $tool, [
                'client_id' => $otherClient->id,
                'ticket_id' => $ticket->id,
                'reason' => 'Cross-client attempt.',
                'body' => 'Should not write.',
            ]);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should fail.");
            $this->assertStringContainsString('different client', (string) $response->json('result.content.0.text'));
        }

        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->count());
    }

    public function test_staged_email_and_public_note_create_held_runs_without_side_effects(): void
    {
        $token = $this->token(['stage_email', 'stage_public_note']);
        $ticket = $this->ticketWithContact();

        foreach (['stage_email', 'stage_public_note'] as $tool) {
            $body = "Draft body for {$tool}.";
            $response = $this->callTool($token, $tool, [
                'client_id' => $ticket->client_id,
                'ticket_id' => $ticket->id,
                'reason' => "Stage {$tool} for review.",
                'body' => $body,
            ]);

            $response->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

            $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', $tool)->firstOrFail();
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
            $this->assertSame($body, $run->proposed_content);
            $this->assertSame('mcp-staff:opsbot', $run->proposed_meta['drafted_by']);
            $this->assertSame(["Stage {$tool} for review."], $run->proposed_meta['reasons']);

            $audit = McpAuditLog::where('tool_name', $tool)->firstOrFail();
            $this->assertSame(mb_strlen($body), $audit->arguments['body_length']);
            $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
        }

        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, Email::where('ticket_id', $ticket->id)->count());
        $this->assertSame(2, TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'awaiting_approval')->count());
    }

    public function test_staged_email_is_idempotent_and_latest_draft_wins(): void
    {
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();

        $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'First draft.',
            'body' => 'Body A.',
        ]);
        $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Replay first draft.',
            'body' => 'Body A.',
        ]);
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->count());

        $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Replacement draft.',
            'body' => 'Body B.',
        ]);

        $runs = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->get();
        $this->assertSame(2, $runs->count());
        $this->assertSame(1, $runs->where('state', TechnicianRunState::AwaitingApproval)->count());
        $this->assertSame('Body B.', $runs->firstWhere('state', TechnicianRunState::AwaitingApproval)->proposed_content);
        $this->assertSame(TechnicianRunState::Superseded, $runs->firstWhere('proposed_content', 'Body A.')->state);
    }

    public function test_propose_merge_stages_without_mutating_and_validates_ticket_pair(): void
    {
        $token = $this->token(['propose_merge']);
        $client = Client::factory()->create();
        $primary = $this->ticketWithContact($client);
        $secondary = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Duplicate printer report',
        ]);
        $other = $this->ticketWithContact(Client::factory()->create());

        $self = $this->callTool($token, 'propose_merge', [
            'client_id' => $client->id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $primary->id,
            'reason' => 'Self merge attempt.',
        ]);
        $this->assertTrue((bool) $self->json('result.isError'));

        $crossClient = $this->callTool($token, 'propose_merge', [
            'client_id' => $client->id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $other->id,
            'reason' => 'Cross-client merge attempt.',
        ]);
        $this->assertTrue((bool) $crossClient->json('result.isError'));

        $response = $this->callTool($token, 'propose_merge', [
            'client_id' => $client->id,
            'primary_ticket_id' => $primary->id,
            'secondary_ticket_id' => $secondary->id,
            'reason' => 'Same printer issue from the same contact.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $primary->id)->where('action_type', 'propose_merge')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($secondary->id, $run->proposed_meta['secondary_ticket_id']);
        $this->assertNull($secondary->fresh()->parent_ticket_id);
        $this->assertSame(TicketStatus::InProgress, $secondary->fresh()->status);
        $this->assertSame(1, TechnicianRun::where('action_type', 'propose_merge')->count());
    }
}
