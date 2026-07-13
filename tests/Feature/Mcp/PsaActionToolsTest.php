<?php

namespace Tests\Feature\Mcp;

use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Asset;
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
use App\Support\McpToolModes;
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

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
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

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
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

    private function ticketWithAsset(?Client $client = null): array
    {
        $client ??= Client::factory()->create();
        $ticket = $this->ticketWithContact($client);
        $asset = Asset::factory()->for($client)->create([
            'name' => 'WORKSTATION-1',
            'hostname' => 'WORKSTATION-1',
        ]);

        $ticket->assets()->attach($asset->id, ['is_primary' => true]);

        return [$ticket->fresh(['assets', 'contact']), $asset->fresh()];
    }

    /**
     * A pending (or terminal) propose_close held run for the ticket, matching what
     * the propose_close path stages. Used to exercise the direct-path dedup guard
     * (psa-y4ft): the direct set_ticket_status close/resolve must defer to an
     * already-pending held close rather than route around it.
     */
    private function pendingCloseProposal(Ticket $ticket, TechnicianRunState $state = TechnicianRunState::AwaitingApproval): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':'.$state->value),
            'state' => $state,
            'proposed_content' => 'Looks resolved; proposing close.',
            'proposed_meta' => ['confidence' => 0.8],
            'confidence' => 0.8,
            'tokens_used' => 0,
        ]);
    }

    /**
     * A recent inbound end-user note — the fail-closed signal CloseAutoEligibility
     * reads (a client who just wrote in is, by definition, not done). created_at
     * defaults to now(), inside the default 14-day quiet window.
     */
    private function recentClientReply(Ticket $ticket): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'Client Contact',
            'who_type' => WhoType::EndUser,
            'body' => 'Still not working — please keep this open.',
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
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
        foreach (['create_ticket', 'send_email', 'write_public_note', 'propose_merge', 'update_ticket', 'set_ticket_status', 'assign_ticket', 'assign_asset', 'unassign_asset', 'set_ticket_contact', 'move_ticket_to_client'] as $name) {
            $this->assertContains($name, $actionNames);
        }
        // Retired staged aliases: callable, but the catalog carries only the
        // canonical capability (send_email / write_public_note).
        foreach (['stage_email', 'stage_public_note'] as $alias) {
            $this->assertNotContains($alias, $actionNames);
            $this->assertContains(McpToolModes::canonicalForAlias($alias), $actionNames);
        }

        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('create_ticket', $legacyNames);
        $this->assertNotContains('send_email', $legacyNames);
        $this->assertNotContains('write_public_note', $legacyNames);

        $scopedTools = collect($this->tools($this->token(['create_ticket', 'send_email', 'update_ticket', 'set_ticket_status', 'assign_ticket', 'assign_asset', 'unassign_asset', 'set_ticket_contact', 'move_ticket_to_client'], 'chet')))->keyBy('name');
        $this->assertTrue($scopedTools->has('create_ticket'));
        $this->assertTrue($scopedTools->has('send_email'));
        $this->assertFalse($scopedTools->has('write_public_note'));
        $this->assertFalse($scopedTools['update_ticket']['inputSchema']['properties']['client_id'] ?? false);

        $createSchema = $scopedTools['create_ticket']['inputSchema'];
        foreach (['client_id', 'subject', 'description', 'reason'] as $required) {
            $this->assertContains($required, $createSchema['required']);
        }
        $this->assertArrayNotHasKey('ticket_id', $createSchema['properties']);

        $schema = $scopedTools['send_email']['inputSchema'];
        $this->assertContains('client_id', $schema['required']);
        $this->assertContains('reason', $schema['required']);
        // psa-kt82: send_email now accepts optional validated to/cc (still no free-text subject).
        $this->assertArrayHasKey('to', $schema['properties']);
        $this->assertArrayHasKey('cc', $schema['properties']);
        $this->assertSame('array', $schema['properties']['to']['type']);
        $this->assertSame('array', $schema['properties']['cc']['type']);
        $this->assertNotContains('to', $schema['required']);
        $this->assertNotContains('cc', $schema['required']);
        $this->assertArrayNotHasKey('subject', $schema['properties']);

        foreach (['update_ticket', 'set_ticket_status', 'assign_ticket', 'assign_asset', 'unassign_asset', 'set_ticket_contact', 'move_ticket_to_client'] as $name) {
            $this->assertArrayNotHasKey('client_id', $scopedTools[$name]['inputSchema']['properties']);
        }
    }

    public function test_granted_chet_token_creates_ticket_with_reason_audits_and_ai_actor(): void
    {
        $actor = $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket'], 'chet');
        $description = 'Please provision a replacement laptop for the new hire starting Monday.';

        $response = $this->callTool($token, 'create_ticket', [
            'client_id' => $client->id,
            'subject' => 'New hire laptop setup',
            'description' => $description,
            'priority' => 2,
            'reason' => 'Chet recognized a valid service request from the client chat.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $ticket = Ticket::findOrFail($result['ticket_id']);

        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($actor->id, $ticket->created_by);
        $this->assertSame('New hire laptop setup', $ticket->subject);
        $this->assertSame($description, $ticket->description);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(TicketSource::Assistant, $ticket->source);
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);

        $audit = McpAuditLog::where('tool_name', 'create_ticket')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-staff:chet', $audit->actor_label);
        $this->assertSame($client->id, $audit->arguments['client_id']);
        $this->assertSame('New hire laptop setup', $audit->arguments['subject']);
        $this->assertSame('[ticket description withheld]', $audit->arguments['description']);
        $this->assertSame(mb_strlen($description), $audit->arguments['description_length']);
        $this->assertSame('Chet recognized a valid service request from the client chat.', $audit->arguments['reason']);
        $this->assertStringNotContainsString($description, (string) json_encode($audit->arguments));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'create_ticket',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'actor_id' => $actor->id,
            'actor_label' => 'mcp-staff:chet',
            'approver_user_id' => null,
        ]);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) TechnicianActionLog::where('action_type', 'create_ticket')->value('content_hash'),
        );
    }

    public function test_create_ticket_requires_client_scope_and_reason_before_creating(): void
    {
        $token = $this->token(['create_ticket'], 'chet');
        $client = Client::factory()->create();

        $missingClient = $this->callTool($token, 'create_ticket', [
            'subject' => 'Missing client',
            'description' => 'This must not create a ticket.',
            'reason' => 'Boundary validation test.',
        ]);
        $missingClient->assertOk();
        $this->assertTrue((bool) $missingClient->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $missingClient->json('result.content.0.text'));

        $missingReason = $this->callTool($token, 'create_ticket', [
            'client_id' => $client->id,
            'subject' => 'Missing reason',
            'description' => 'This must not create a ticket.',
        ]);
        $missingReason->assertOk();
        $this->assertTrue((bool) $missingReason->json('result.isError'));
        $this->assertStringContainsString('reason is required', (string) $missingReason->json('result.content.0.text'));

        $this->assertSame(0, Ticket::count());
    }

    public function test_create_ticket_dedup_blocks_duplicate_within_window(): void
    {
        $this->configureAiActor();
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket'], 'chet');
        $arguments = [
            'client_id' => $client->id,
            'subject' => 'Duplicate service request',
            'description' => 'The same request appeared twice from the same client.',
            'priority' => 3,
            'reason' => 'Initial request from Chet.',
        ];

        $first = $this->callTool($token, 'create_ticket', $arguments);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, 'create_ticket', [
            ...$arguments,
            'reason' => 'Replay from the same chat transcript.',
        ]);
        $second->assertOk();
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));

        $result = $this->decodedResult($second);
        $this->assertTrue((bool) $result['idempotent']);
        $this->assertSame(1, Ticket::where('client_id', $client->id)->count());
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'create_ticket')->where('result_status', 'executed')->count());
    }

    public function test_allowlisted_but_unpublished_staff_tools_are_not_listed_or_callable(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['create_ticket', 'close_ticket', 'tactical_run_diagnostic'], 'chet');

        $names = collect($this->tools($token))->pluck('name')->all();
        $this->assertContains('create_ticket', $names);
        $this->assertNotContains('close_ticket', $names);
        $this->assertNotContains('tactical_run_diagnostic', $names);

        foreach (['close_ticket', 'tactical_run_diagnostic'] as $tool) {
            $response = $this->callTool($token, $tool, ['client_id' => $client->id]);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should fail.");
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }
    }

    public function test_direct_send_email_rejects_arbitrary_recipient_without_side_effects(): void
    {
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact(); // contact = client@example.test
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->never());

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for confirmation.',
            'body' => 'Body.',
            'cc' => ['attacker@example.test'], // not a contact, not on thread
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not a known contact or thread participant', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->where('action_type', 'send_email')->count());
    }

    public function test_direct_send_email_sends_to_contact_and_thread_participant_cc_with_redacted_audit(): void
    {
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact(); // contact = client@example.test
        // Seed an inbound thread email so vendor@thread.test is a validated thread participant.
        Email::create([
            'graph_id' => 'in-1', 'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test', 'from_name' => 'Client',
            'to_recipients' => [['address' => 'vendor@thread.test', 'name' => 'Vendor']],
            'subject' => 'Re: Printer', 'body_preview' => 'x', 'body_text' => 'x', 'body_html' => '<p>x</p>',
            'has_attachments' => false, 'importance' => 'normal', 'received_at' => now()->subMinute(),
            'is_read' => true, 'client_id' => $ticket->client_id, 'person_id' => $ticket->contact_id, 'ticket_id' => $ticket->id,
        ]);
        $body = 'The printer is back online.';

        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTicketReplyNote')->once()
                ->andReturnUsing(function (Ticket $ticket, TicketNote $note, ?string $toEmail, array $ccEmails) {
                    $this->assertSame('client@example.test', $toEmail);
                    $this->assertSame(['vendor@thread.test'], $ccEmails);
                    $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);

                    return $this->outboundEmail($ticket, $note);
                });
        });

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Confirm to the room, cc the vendor already on thread.',
            'body' => $body,
            'cc' => ['vendor@thread.test'],
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertNotNull($note->email_id);

        $log = TechnicianActionLog::where('ticket_id', $ticket->id)->where('action_type', 'send_email')->firstOrFail();
        $this->assertStringContainsString('CC 1', (string) $log->summary);      // recipient descriptor recorded
        $this->assertStringNotContainsString('vendor@thread.test', (string) $log->summary); // addresses redacted

        $audit = McpAuditLog::where('tool_name', 'send_email')->firstOrFail();
        $this->assertSame(1, $audit->arguments['cc_count']);
        $this->assertStringNotContainsString('vendor@thread.test', (string) json_encode($audit->arguments));
    }

    public function test_direct_send_email_with_no_recipients_is_contact_only_and_length_only_audit(): void
    {
        // Regression (spec §6): omitting to/cc reproduces today's behavior exactly —
        // To = ticket contact, empty CC, and the audit records only body_length.
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact();
        $body = 'Just the contact, no CC.';
        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTicketReplyNote')->once()
                ->andReturnUsing(function (Ticket $ticket, TicketNote $note, ?string $toEmail, array $ccEmails) {
                    $this->assertSame('client@example.test', $toEmail);
                    $this->assertSame([], $ccEmails);

                    return $this->outboundEmail($ticket, $note);
                });
        });

        $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'No recipients supplied.',
            'body' => $body,
        ])->assertOk();

        $audit = McpAuditLog::where('tool_name', 'send_email')->firstOrFail();
        $this->assertSame(mb_strlen($body), $audit->arguments['body_length']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
    }

    public function test_direct_send_email_redacts_addresses_in_the_audit_reason(): void
    {
        // I1: an address in `reason` must be redacted in McpAuditLog.arguments,
        // matching the redaction already applied to the action-log summary.
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact();
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')
            ->once()
            ->andReturnUsing(fn (Ticket $ticket, TicketNote $note) => $this->outboundEmail($ticket, $note)));

        $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Loop in escalations@vendor.test per the client.',
            'body' => 'Update.',
        ])->assertOk();

        $audit = McpAuditLog::where('tool_name', 'send_email')->firstOrFail();
        $this->assertArrayHasKey('reason', $audit->arguments);
        $this->assertStringNotContainsString('escalations@vendor.test', (string) json_encode($audit->arguments));
        $this->assertStringContainsString('[external address withheld]', $audit->arguments['reason']);
    }

    public function test_direct_send_email_idempotency_key_includes_recipients(): void
    {
        // M2: the same body sent to a DIFFERENT recipient set is not an idempotent
        // replay — it falls through to the rate-limit guard rather than a silent dedup.
        $token = $this->token(['send_email']);
        $ticket = $this->ticketWithContact();
        Email::create([
            'graph_id' => 'in-m2', 'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test', 'from_name' => 'Client',
            'to_recipients' => [['address' => 'vendor@thread.test', 'name' => 'Vendor']],
            'subject' => 'Re: Printer', 'body_preview' => 'x', 'body_text' => 'x', 'body_html' => '<p>x</p>',
            'has_attachments' => false, 'importance' => 'normal', 'received_at' => now()->subMinute(),
            'is_read' => true, 'client_id' => $ticket->client_id, 'person_id' => $ticket->contact_id, 'ticket_id' => $ticket->id,
        ]);
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')
            ->once()
            ->andReturnUsing(fn (Ticket $ticket, TicketNote $note) => $this->outboundEmail($ticket, $note)));

        $first = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id, 'ticket_id' => $ticket->id,
            'reason' => 'First.', 'body' => 'Same body.',
        ]);
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id, 'ticket_id' => $ticket->id,
            'reason' => 'Second, add the vendor already on thread.', 'body' => 'Same body.',
            'cc' => ['vendor@thread.test'],
        ]);
        // Rate-limited (isError=true), NOT an idempotent replay — with body-only keying
        // the second call would have returned idempotent success (isError=false) instead.
        $this->assertTrue((bool) $second->json('result.isError'));
        $this->assertStringContainsString('rate', (string) $second->json('result.content.0.text'));
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

    /**
     * bd psa-k4s0 sibling audit: StaffPsaActionToolExecutor::stageTicketAction has the
     * same "post-create supersede" SHAPE as the CIPP/Tactical staged-action bug, but its
     * "latest draft wins" content-blind supersede is INTENTIONAL here (only one held
     * reply/note draft should ever be live per ticket — see the "latest draft wins" test
     * above), not the Root A defect. It also never consults the audit log to decide
     * "still awaiting" — firstOrCreate + a direct state check on the SAME idempotency-
     * keyed row is what the CIPP/Tactical fix now does too — so it was never exposed to
     * Root B either. This pins that a restage of SUPERSEDED content revives it (a real
     * run_id, never a false idempotent hit), confirming this executor needed no fix.
     */
    public function test_staged_email_restage_of_superseded_content_revives_not_a_false_idempotent(): void
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
            'reason' => 'Replacement draft supersedes Body A.',
            'body' => 'Body B.',
        ]);
        $bodyARun = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->where('proposed_content', 'Body A.')->firstOrFail();
        $this->assertSame(TechnicianRunState::Superseded, $bodyARun->state);

        $restaged = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Chet needs to restage Body A after all.',
            'body' => 'Body A.',
        ]);
        $restaged->assertOk();
        $this->assertFalse((bool) $restaged->json('result.isError'), (string) $restaged->json('result.content.0.text'));
        $result = $this->decodedResult($restaged);

        $this->assertArrayNotHasKey('idempotent', $result);
        $this->assertNotNull($result['run_id']);
        $this->assertSame($bodyARun->id, $result['run_id'], 'restaging Body A revives the SAME idempotency-keyed row, not a null/false hit');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $bodyARun->fresh()->state);

        $this->assertSame(2, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->count());
    }

    public function test_staged_email_rejects_custom_recipient_when_staged_arbitrary_off(): void
    {
        // psa-w4e0 default-off: without the staged-arbitrary knob, stage_email keeps
        // rejecting addresses outside known contacts / thread participants at stage time.
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();

        $response = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Loop in the external consultant.',
            'body' => 'Held draft.',
            'cc' => ['outsider@partner.test'],
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not a known contact or thread participant', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->count());
    }

    public function test_staged_email_accepts_custom_recipients_when_staged_knob_on_and_direct_stays_locked(): void
    {
        // psa-w4e0: the staged knob admits syntax-valid custom To/CC on the HELD path
        // (human approval is the safeguard) and must NOT widen the immediate path.
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $token = $this->token(['stage_email', 'send_email']);
        $ticket = $this->ticketWithContact(); // contact = client@example.test
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->never());

        $staged = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Send the audit summary to the external auditor.',
            'body' => 'Audit summary attached below.',
            'to' => ['auditor@partner.test'],
            'cc' => ['client@example.test'],
        ]);

        $staged->assertOk();
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        // The resolved proposal is durably recorded for the approval card + audit trail,
        // with the outside-known-contacts subset called out for the exfil readout.
        $this->assertSame('auditor@partner.test', $run->proposed_meta['to']);
        $this->assertSame(['client@example.test'], $run->proposed_meta['cc']);
        $this->assertSame(['auditor@partner.test'], $run->proposed_meta['custom_recipients']);

        // Action-log summary carries counts + the outside-known-contacts flag, never addresses.
        $log = TechnicianActionLog::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertStringContainsString('To 1, CC 1', (string) $log->summary);
        $this->assertStringContainsString('1 outside known contacts', (string) $log->summary);
        $this->assertStringNotContainsString('auditor@partner.test', (string) $log->summary);

        // MCP audit records recipient counts only (psa-kt82 convention).
        $audit = McpAuditLog::where('tool_name', 'stage_email')->firstOrFail();
        $this->assertSame(1, $audit->arguments['to_count']);
        $this->assertSame(1, $audit->arguments['cc_count']);
        $this->assertStringNotContainsString('auditor@partner.test', (string) json_encode($audit->arguments));

        // Nothing sent, nothing written to the ticket — held only.
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        // INDEPENDENCE (load-bearing): the staged knob must not open the immediate path.
        $direct = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Try the same custom address immediately.',
            'body' => 'Should not send.',
            'cc' => ['outsider2@partner.test'],
        ]);
        $this->assertTrue((bool) $direct->json('result.isError'));
        $this->assertStringContainsString('not a known contact or thread participant', (string) $direct->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_staged_email_rejects_invalid_email_syntax_even_when_staged_knob_on(): void
    {
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();

        $response = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Custom address with a typo.',
            'body' => 'Held draft.',
            'to' => ['not-an-email'],
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not a valid email address', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }

    public function test_staged_email_person_id_refs_still_resolve_with_staged_knob_off(): void
    {
        // Known-source references keep working without any knob: person_ids resolve
        // against the ticket client's contacts exactly as before.
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();
        $bob = Person::create([
            'client_id' => $ticket->client_id,
            'person_type' => PersonType::User,
            'first_name' => 'Bob',
            'last_name' => 'Second',
            'email' => 'bob@example.test',
            'is_active' => true,
        ]);

        $response = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'CC the second site contact.',
            'body' => 'Held draft.',
            'cc' => [$bob->id],
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame('client@example.test', $run->proposed_meta['to']); // contact default
        $this->assertSame(['bob@example.test'], $run->proposed_meta['cc']);
        $this->assertSame([], $run->proposed_meta['custom_recipients']);
    }

    public function test_staged_email_content_hash_distinguishes_recipient_sets(): void
    {
        // Same body to a DIFFERENT audience is a new staged proposal (latest wins),
        // not a false idempotent replay of the earlier recipient set.
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();

        $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Draft to the contact.',
            'body' => 'Same body.',
        ]);
        $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Same body, now also to the auditor.',
            'body' => 'Same body.',
            'cc' => ['auditor@partner.test'],
        ]);

        $runs = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->get();
        $this->assertSame(2, $runs->count());
        $live = $runs->firstWhere('state', TechnicianRunState::AwaitingApproval);
        $this->assertNotNull($live);
        $this->assertSame(['auditor@partner.test'], $live->proposed_meta['cc']);
        $this->assertSame(1, $runs->where('state', TechnicianRunState::Superseded)->count());

        // An exact replay (same body + same recipients) is still idempotent.
        $replay = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Replay.',
            'body' => 'Same body.',
            'cc' => ['auditor@partner.test'],
        ]);
        $this->assertFalse((bool) $replay->json('result.isError'));
        $this->assertSame(2, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->count());
    }

    public function test_staged_email_contactless_ticket_errors_without_to_but_accepts_custom_to_when_knob_on(): void
    {
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact(email: null);

        // No To and no contact email — same guard as before this feature.
        $bare = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'No recipient available.',
            'body' => 'Held draft.',
        ]);
        $this->assertTrue((bool) $bare->json('result.isError'));
        $this->assertStringContainsString('contact email', (string) $bare->json('result.content.0.text'));

        // With the staged knob on, an explicit custom To makes the draft stageable.
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $custom = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Reach the requester at their personal address.',
            'body' => 'Held draft.',
            'to' => ['requester@personal.test'],
        ]);
        $this->assertFalse((bool) $custom->json('result.isError'), (string) $custom->json('result.content.0.text'));
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame('requester@personal.test', $run->proposed_meta['to']);
        $this->assertSame(['requester@personal.test'], $run->proposed_meta['custom_recipients']);
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

    public function test_update_ticket_assign_ticket_and_contact_mutations_use_ticket_scope_and_audit_diff(): void
    {
        $this->configureAiActor();
        $token = $this->token(['update_ticket', 'assign_ticket', 'set_ticket_contact'], 'chet');
        $ticket = $this->ticketWithContact();
        $assignee = User::factory()->create(['name' => 'Technician One']);
        $newContact = Person::create([
            'client_id' => $ticket->client_id,
            'person_type' => PersonType::User,
            'first_name' => 'New',
            'last_name' => 'Contact',
            'email' => 'new@example.test',
            'is_active' => true,
        ]);
        $otherClientContact = Person::create([
            'client_id' => Client::factory()->create()->id,
            'person_type' => PersonType::User,
            'first_name' => 'Other',
            'last_name' => 'Client',
            'email' => 'other@example.test',
            'is_active' => true,
        ]);

        $update = $this->callTool($token, 'update_ticket', [
            'ticket_id' => $ticket->id,
            'subject' => 'Updated subject',
            'description' => 'Updated body text.',
            'priority' => TicketPriority::P2->value,
            'type' => TicketType::Change->value,
        ]);
        $update->assertOk();
        $this->assertFalse((bool) $update->json('result.isError'), (string) $update->json('result.content.0.text'));

        $ticket->refresh();
        $this->assertSame('Updated subject', $ticket->subject);
        $this->assertSame('Updated body text.', $ticket->description);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(TicketType::Change, $ticket->type);

        $assign = $this->callTool($token, 'assign_ticket', [
            'ticket_id' => $ticket->id,
            'user_id' => $assignee->id,
        ]);
        $assign->assertOk();
        $ticket->refresh();
        $this->assertSame($assignee->id, $ticket->assignee_id);

        $setContact = $this->callTool($token, 'set_ticket_contact', [
            'ticket_id' => $ticket->id,
            'contact_id' => $newContact->id,
        ]);
        $setContact->assertOk();
        $ticket->refresh();
        $this->assertSame($newContact->id, $ticket->contact_id);

        $foreign = $this->callTool($token, 'set_ticket_contact', [
            'ticket_id' => $ticket->id,
            'contact_id' => $otherClientContact->id,
        ]);
        $foreign->assertOk();
        $this->assertTrue((bool) $foreign->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $foreign->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'update_ticket',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'assign_ticket',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'set_ticket_contact',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:chet',
        ]);

        $audit = McpAuditLog::where('tool_name', 'update_ticket')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('Updated subject', $audit->arguments['subject']);
        $this->assertSame(mb_strlen('Updated body text.'), $audit->arguments['description_length']);
    }

    public function test_assign_asset_and_unassign_asset_enforce_ticket_client_boundary(): void
    {
        $this->configureAiActor();
        $token = $this->token(['assign_asset', 'unassign_asset'], 'chet');
        [$ticket, $asset] = $this->ticketWithAsset();
        $otherAsset = Asset::factory()->create();

        $assign = $this->callTool($token, 'assign_asset', [
            'ticket_id' => $ticket->id,
            'asset_id' => $asset->id,
            'is_primary' => true,
        ]);
        $assign->assertOk();
        $ticket->refresh();
        $this->assertTrue($ticket->assets()->where('assets.id', $asset->id)->exists());
        $this->assertTrue((bool) $ticket->assets()->where('assets.id', $asset->id)->first()->pivot->is_primary);

        $crossClient = $this->callTool($token, 'assign_asset', [
            'ticket_id' => $ticket->id,
            'asset_id' => $otherAsset->id,
        ]);
        $crossClient->assertOk();
        $this->assertTrue((bool) $crossClient->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossClient->json('result.content.0.text'));

        $unassign = $this->callTool($token, 'unassign_asset', [
            'ticket_id' => $ticket->id,
            'asset_id' => $asset->id,
        ]);
        $unassign->assertOk();
        $this->assertFalse($ticket->fresh()->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_set_ticket_status_requires_typed_confirm_for_terminal_transitions(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();

        // Move to an auto-close-ELIGIBLE state first. PendingClient is non-terminal
        // (never gated) and is an AUTO_SAFE status, so the ticket is close-eligible
        // afterward. psa-y4ft: the ->Closed eligibility gate now runs before the
        // confirm_status ceremony, so the ticket must be eligible for this test to
        // exercise the typed-confirm requirement rather than the eligibility gate.
        $direct = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::PendingClient->value,
        ]);
        $direct->assertOk();
        $this->assertFalse((bool) $direct->json('result.isError'), (string) $direct->json('result.content.0.text'));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);

        $missingConfirm = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'reason' => 'Closing after confirmation from the client.',
        ]);
        $missingConfirm->assertOk();
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('confirm_status', (string) $missingConfirm->json('result.content.0.text'));

        $confirmed = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'The issue was resolved and the client confirmed closure.',
            'resolution' => 'Client confirmed the fix worked.',
            'note' => 'Closing after typed confirmation.',
        ]);
        $confirmed->assertOk();
        $this->assertFalse((bool) $confirmed->json('result.isError'), (string) $confirmed->json('result.content.0.text'));
        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Closed, $fresh->status);
        $this->assertSame('Client confirmed the fix worked.', $fresh->resolution);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'set_ticket_status',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    // ── psa-y4ft: auto-close safety envelope on the DIRECT set_ticket_status path ──
    //
    // Charlie enabled set_ticket_status on Chet's token — a live autonomous CLOSE
    // path that bypasses the held propose_close review + the #177 state/dedup gate.
    // "Fold it in": extend the SAME envelope to the direct path. Confirmed scope (a):
    // CloseAutoEligibility::eligible() gates ->Closed ONLY; the dedup / already-in-
    // state helper applies to BOTH terminal transitions; ->Resolved and every
    // non-terminal transition stay fully open.

    public function test_direct_close_of_an_awaiting_us_ticket_is_blocked_by_eligibility(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress = awaiting us

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Looks done to me.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('awaiting us', strtolower((string) $response->json('result.content.0.text')));
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status, 'an awaiting-us ticket must not be direct-closed');
        $this->assertDatabaseMissing('technician_action_logs', [
            'action_type' => 'set_ticket_status',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_direct_close_of_a_ticket_with_a_recent_client_reply_is_blocked(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]); // AUTO_SAFE status...
        $this->recentClientReply($ticket);                          // ...but the client just wrote in

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Closing the stale ticket.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('recent client activity', strtolower((string) $response->json('result.content.0.text')));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status, 'a ticket with a live client reply must not be direct-closed');
    }

    public function test_direct_close_of_an_eligible_quiet_ticket_succeeds(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]); // eligible, no client note

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'No client reply in weeks; closing.',
            'resolution' => 'Auto-resolved; no response.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'set_ticket_status',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_direct_close_of_an_already_closed_ticket_is_blocked_with_a_specific_message(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::Closed]);

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Closing again.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('already closed', strtolower((string) $response->json('result.content.0.text')));
    }

    public function test_direct_resolve_of_an_awaiting_us_ticket_is_allowed_resolve_is_not_gated(): void
    {
        // The safety target is autonomous CLOSING, not resolving. Resolving an active
        // (awaiting-us) ticket is a legitimate everyday action and MUST stay open —
        // eligible() would wrongly block it (its allow-list requires an already-safe
        // current status). This is the crux of the confirmed scope-(a) correction.
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress
        $this->recentClientReply($ticket);    // even with a live client note, resolve stays open

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Resolved->value,
            'confirm_status' => 'resolved',
            'reason' => 'Fixed the printer driver; resolving.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_direct_resolve_of_an_already_resolved_ticket_is_blocked(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::Resolved]);

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Resolved->value,
            'confirm_status' => 'resolved',
            'reason' => 'Resolving again.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('already resolved', strtolower((string) $response->json('result.content.0.text')));
    }

    public function test_direct_close_is_blocked_when_a_held_close_proposal_is_pending(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]); // eligible + quiet
        $this->pendingCloseProposal($ticket);                       // ...but a close is already staged

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Closing directly.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('awaiting approval', strtolower((string) $response->json('result.content.0.text')));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status, 'the direct path must defer to the pending held close, not preempt it');
    }

    public function test_direct_resolve_is_blocked_when_a_held_close_proposal_is_pending(): void
    {
        // The dedup applies to BOTH terminal transitions: with a close already staged
        // and awaiting a human, a direct resolve is redundant churn on the same ticket.
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress
        $this->pendingCloseProposal($ticket);

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Resolved->value,
            'confirm_status' => 'resolved',
            'reason' => 'Resolving directly.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('awaiting approval', strtolower((string) $response->json('result.content.0.text')));
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_direct_close_is_allowed_when_the_only_prior_proposal_is_terminal(): void
    {
        // Dedup blocks only a PENDING (awaiting_approval) proposal. A terminal outcome
        // (denied/superseded/done) must not permanently bar the direct path — mirrors
        // the propose_close "allowed after denied" rule.
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]);
        $this->pendingCloseProposal($ticket, TechnicianRunState::Denied);

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Prior proposal denied; closing on fresh evidence.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_non_terminal_transition_is_never_gated_even_with_client_activity_and_a_pending_proposal(): void
    {
        // Non-terminal transitions carry NO new gate (Charlie): a live client note and
        // a pending held close must not block moving the ticket to a non-terminal state.
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress
        $this->recentClientReply($ticket);
        $this->pendingCloseProposal($ticket);

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::PendingClient->value,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(TicketStatus::PendingClient, $ticket->fresh()->status);
    }

    // psa-y4ft.1: an autonomous DIRECT close (set_ticket_status → Closed) must be as
    // trivially reversible as an operator-approved held close. Each executed direct
    // close records a Done direct_close run anchored on its status-change note id;
    // the cockpit reads these into a one-click Reopen lane. Only ->Closed records a
    // card — resolve and non-terminal transitions are everyday actions, and a
    // refused close must leave nothing behind.

    public function test_direct_close_records_a_done_direct_close_run_for_one_click_reopen(): void
    {
        $actor = $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]); // eligible + quiet

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'No client reply in weeks; closing.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $statusNoteId = TicketNote::query()
            ->where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::Closed->value)
            ->where('author_id', $actor->id)
            ->latest('id')
            ->value('id');
        $this->assertNotNull($statusNoteId, 'the direct close must have written a status-change note to anchor the undo on');

        $run = TechnicianRun::query()->where('action_type', 'direct_close')->sole();
        $this->assertSame($ticket->id, $run->ticket_id);
        $this->assertSame($ticket->client_id, $run->client_id);
        $this->assertSame(TechnicianRunState::Done, $run->state);
        $this->assertSame('No client reply in weeks; closing.', $run->proposed_content);
        $this->assertSame((int) $statusNoteId, (int) data_get($run->proposed_meta, 'status_note_id'));
        $this->assertSame('mcp-staff:chet', data_get($run->proposed_meta, 'drafted_by'));
        $this->assertSame(hash('sha256', 'direct_close:'.$ticket->id.':'.$statusNoteId), $run->content_hash);
    }

    public function test_direct_resolve_records_no_direct_close_run(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress — resolve is ungated

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Resolved->value,
            'confirm_status' => 'resolved',
            'reason' => 'Fixed; resolving.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::query()->where('action_type', 'direct_close')->count());
    }

    public function test_blocked_direct_close_records_no_direct_close_run(): void
    {
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact(); // InProgress = awaiting us → refused

        $response = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Looks done to me.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(0, TechnicianRun::query()->where('action_type', 'direct_close')->count());
    }

    public function test_direct_close_after_cockpit_reopen_is_blocked_and_does_not_resurrect_the_undo_card(): void
    {
        // The undo must STICK: a cockpit reopen lands the ticket InProgress, which the
        // eligibility backstop reads as awaiting-us — so the agent cannot immediately
        // re-close over the human's reversal, and the reversed (Denied) run keeps its
        // veto signal instead of being resurrected.
        $this->configureAiActor();
        $token = $this->token(['set_ticket_status'], 'chet');
        $ticket = $this->ticketWithContact();
        $ticket->update(['status' => TicketStatus::PendingClient]);

        $close = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'No client reply in weeks; closing.',
        ]);
        $this->assertFalse((bool) $close->json('result.isError'), (string) $close->json('result.content.0.text'));

        $run = TechnicianRun::query()->where('action_type', 'direct_close')->sole();
        $operator = User::factory()->create();
        $this->actingAs($operator)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);

        $reclose = $this->callTool($token, 'set_ticket_status', [
            'ticket_id' => $ticket->id,
            'status' => TicketStatus::Closed->value,
            'confirm_status' => 'closed',
            'reason' => 'Closing it again.',
        ]);

        $reclose->assertOk();
        $this->assertTrue((bool) $reclose->json('result.isError'), 'a direct re-close over a human reopen must be refused by eligibility');
        $this->assertStringContainsString('awaiting us', strtolower((string) $reclose->json('result.content.0.text')));
        $this->assertSame(1, TechnicianRun::query()->where('action_type', 'direct_close')->count());
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }

    public function test_move_ticket_to_client_requires_typed_confirm_and_rehomes_assets(): void
    {
        $this->configureAiActor();
        $token = $this->token(['move_ticket_to_client'], 'chet');
        [$ticket, $asset] = $this->ticketWithAsset();
        $newClient = Client::factory()->create();
        $newContact = Person::create([
            'client_id' => $newClient->id,
            'person_type' => PersonType::User,
            'first_name' => 'Move',
            'last_name' => 'Target',
            'email' => 'move-target@example.test',
            'is_active' => true,
        ]);
        $oldContactId = $ticket->contact_id;

        $missingConfirm = $this->callTool($token, 'move_ticket_to_client', [
            'ticket_id' => $ticket->id,
            'new_client_id' => $newClient->id,
            'new_contact_id' => $newContact->id,
            'reason' => 'Move after client merge.',
        ]);
        $missingConfirm->assertOk();
        $this->assertTrue((bool) $missingConfirm->json('result.isError'));
        $this->assertStringContainsString('confirm_client_name', (string) $missingConfirm->json('result.content.0.text'));

        $confirmed = $this->callTool($token, 'move_ticket_to_client', [
            'ticket_id' => $ticket->id,
            'new_client_id' => $newClient->id,
            'new_contact_id' => $newContact->id,
            'confirm_client_name' => $newClient->name,
            'reason' => 'Move after client merger to the target account.',
        ]);
        $confirmed->assertOk();
        $this->assertFalse((bool) $confirmed->json('result.isError'), (string) $confirmed->json('result.content.0.text'));

        $fresh = $ticket->fresh(['assets']);
        $this->assertSame($newClient->id, $fresh->client_id);
        $this->assertSame($newContact->id, $fresh->contact_id);
        $this->assertFalse($fresh->assets()->where('assets.id', $asset->id)->exists());
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'move_ticket_to_client',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
            'client_id' => $newClient->id,
            'actor_label' => 'mcp-staff:chet',
        ]);
    }
}
