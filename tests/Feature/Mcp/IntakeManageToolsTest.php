<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Email;
use App\Models\McpAuditLog;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the W2 Task 2/3 MANAGE tools (link_email_to_ticket,
 * create_ticket_from_email, dismiss_email_item, link_call_to_ticket,
 * create_ticket_from_call) in the new, dormant, grant-gated intake_manage
 * group. Each verb is a thin reuse of a native EmailService/PhoneCallService
 * path — no reimplementation. Audit rows (technician_action_logs AND
 * mcp_audit_logs) carry ids + reason only; the email body / call transcript
 * must never appear in either. Mirrors the PsaRecordsToolsTest /
 * IntakeReadToolsTest harness conventions.
 */
class IntakeManageToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_TOOLS = [
        'link_email_to_ticket',
        'create_ticket_from_email',
        'dismiss_email_item',
        'link_call_to_ticket',
        'create_ticket_from_call',
    ];

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

    /** @param  array<string, mixed>  $arguments */
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

    /** PhoneCall::client_id is NOT fillable (unlike Email) — create() then direct-assign + save(). */
    private function makeCall(array $attributes, ?int $clientId = null): PhoneCall
    {
        $call = PhoneCall::create($attributes);
        if ($clientId !== null) {
            $call->client_id = $clientId;
            $call->save();
        }

        return $call;
    }

    // ── Grant-gating ─────────────────────────────────────────────────────────

    public function test_intake_manage_tools_are_grant_gated_and_absent_from_legacy_token(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('intake_manage', $groups);
        $this->assertTrue($groups['intake_manage']['sensitive']);

        $groupNames = array_column($groups['intake_manage']['tools'], 'name');
        foreach (self::ALL_TOOLS as $name) {
            $this->assertContains($name, $groupNames);
            $this->assertContains($name, McpToolRegistry::allToolNames());
        }

        // Dormant by default: a legacy full-surface token cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (self::ALL_TOOLS as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        // A granted token sees exactly what it was granted.
        $scoped = collect($this->tools($this->token(['link_email_to_ticket', 'dismiss_email_item'], 'chet')))->keyBy('name');
        $this->assertTrue($scoped->has('link_email_to_ticket'));
        $this->assertTrue($scoped->has('dismiss_email_item'));
        $this->assertFalse($scoped->has('create_ticket_from_email'));
        $this->assertFalse($scoped->has('link_call_to_ticket'));
        $this->assertFalse($scoped->has('create_ticket_from_call'));

        // Every schema requires reason and none carries a client_id property —
        // scope lives on the targeted email/call/ticket ids themselves.
        $granted = collect($this->tools($this->token(self::ALL_TOOLS, 'chet')))->keyBy('name');
        foreach (self::ALL_TOOLS as $name) {
            $schema = $granted[$name]['inputSchema'];
            $this->assertContains('reason', $schema['required'], "{$name} must require reason");
            $this->assertArrayNotHasKey('client_id', $schema['properties'], "{$name} must not carry client_id");
        }
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_intake_manage_tools(): void
    {
        $ticket = Ticket::factory()->create();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@example.test',
            'subject' => 'Denied call test',
            'received_at' => now(),
            'client_id' => $ticket->client_id,
        ]);
        $call = $this->makeCall([
            'call_uuid' => 'call-denied-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ], $ticket->client_id);

        $calls = [
            ['link_email_to_ticket', ['email_id' => $email->id, 'ticket_id' => $ticket->id, 'reason' => 'x']],
            ['create_ticket_from_email', ['email_id' => $email->id, 'reason' => 'x']],
            ['dismiss_email_item', ['email_id' => $email->id, 'reason' => 'x']],
            ['link_call_to_ticket', ['phone_call_id' => $call->id, 'ticket_id' => $ticket->id, 'reason' => 'x']],
            ['create_ticket_from_call', ['phone_call_id' => $call->id, 'reason' => 'x']],
        ];

        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }

        // Nothing mutated.
        $this->assertNull($email->fresh()->ticket_id);
        $this->assertNull($email->fresh()->dismissed_at);
        $this->assertNull($call->fresh()->ticket_id);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    // ── link_email_to_ticket ─────────────────────────────────────────────────

    public function test_link_email_to_ticket_reuses_native_path_and_audits_ids_only(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test',
            'subject' => 'Need help',
            'body_text' => 'SECRET BODY',
            'received_at' => now(),
            'client_id' => $ticket->client_id,
        ]);
        $token = $this->token(['link_email_to_ticket'], 'chet');

        $response = $this->callTool($token, 'link_email_to_ticket', [
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'reason' => 'triage',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        // Native side-effects: EmailService::linkEmailToTicket ran for real.
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame(
            1,
            TicketNote::where('ticket_id', $ticket->id)->where('note_type', NoteType::Reply)->count(),
            'linkEmailToTicket must have created the client reply note'
        );

        // technician_action_logs: ids + reason only, never the body.
        $log = TechnicianActionLog::where('action_type', 'link_email_to_ticket')->firstOrFail();
        $this->assertStringContainsString((string) $email->id, (string) $log->summary);
        $this->assertStringContainsString((string) $ticket->id, (string) $log->summary);
        $this->assertStringContainsString('triage', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET BODY', (string) $log->summary);

        // mcp_audit_logs: the raw call arguments never carried a body, so the
        // secret cannot appear anywhere in the audit trail.
        $this->assertStringNotContainsString('SECRET BODY', (string) json_encode(McpAuditLog::all()->toArray()));
    }

    public function test_link_email_to_ticket_succeeds_for_a_client_less_ticket(): void
    {
        $this->configureAiActor();
        // A client-less ticket is a real, live state — the audit client_id must stay
        // null, not be cast to 0 (which would violate the technician_action_logs FK
        // and leave a mutated-but-unaudited state).
        $ticket = Ticket::factory()->create(['client_id' => null]);
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test',
            'subject' => 'Need help',
            'received_at' => now(),
        ]);
        $token = $this->token(['link_email_to_ticket'], 'chet');

        $response = $this->callTool($token, 'link_email_to_ticket', [
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'reason' => 'triage a client-less ticket',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);

        $log = TechnicianActionLog::where('action_type', 'link_email_to_ticket')->firstOrFail();
        $this->assertNull($log->client_id, 'audit client_id must stay null for a client-less ticket');
    }

    public function test_link_email_to_ticket_rejects_unknown_ids(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'client@example.test',
            'subject' => 'Need help',
            'received_at' => now(),
        ]);
        $token = $this->token(['link_email_to_ticket'], 'chet');

        $badEmail = $this->callTool($token, 'link_email_to_ticket', ['email_id' => 999999, 'ticket_id' => $ticket->id, 'reason' => 'x']);
        $badEmail->assertOk();
        $this->assertTrue((bool) $badEmail->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $badEmail->json('result.content.0.text'));

        $badTicket = $this->callTool($token, 'link_email_to_ticket', ['email_id' => $email->id, 'ticket_id' => 999999, 'reason' => 'x']);
        $badTicket->assertOk();
        $this->assertTrue((bool) $badTicket->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $badTicket->json('result.content.0.text'));
    }

    // ── create_ticket_from_email ─────────────────────────────────────────────

    public function test_create_ticket_from_email_requires_client(): void
    {
        $this->configureAiActor();
        $token = $this->token(['create_ticket_from_email'], 'chet');

        $orphan = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'nobody@example.test',
            'subject' => 'No client yet',
            'body_text' => 'SECRET BODY 2',
            'received_at' => now(),
        ]);

        $blocked = $this->callTool($token, 'create_ticket_from_email', [
            'email_id' => $orphan->id,
            'reason' => 'new issue',
        ]);
        $blocked->assertOk();
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('client', (string) $blocked->json('result.content.0.text'));
        $this->assertNull($orphan->fresh()->ticket_id);
        $this->assertSame(0, Ticket::query()->count());

        $client = Client::factory()->create();
        $resolved = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@example.test',
            'subject' => 'Printer is down',
            'body_text' => 'SECRET BODY 3',
            'received_at' => now(),
            'client_id' => $client->id,
        ]);

        $ok = $this->callTool($token, 'create_ticket_from_email', [
            'email_id' => $resolved->id,
            'reason' => 'new issue',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));

        $result = $this->decodedResult($ok);
        $this->assertNotNull($result['ticket_id'] ?? null);
        $ticket = Ticket::findOrFail($result['ticket_id']);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($ticket->id, $resolved->fresh()->ticket_id);

        // Audit: ids + reason only.
        $log = TechnicianActionLog::where('action_type', 'create_ticket_from_email')->firstOrFail();
        $this->assertStringContainsString((string) $resolved->id, (string) $log->summary);
        $this->assertStringContainsString((string) $ticket->id, (string) $log->summary);
        $this->assertStringNotContainsString('SECRET BODY 3', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET BODY 3', (string) json_encode(McpAuditLog::all()->toArray()));
    }

    // ── dismiss_email_item ───────────────────────────────────────────────────

    public function test_dismiss_email_item_sets_dismissed_and_audits_reason(): void
    {
        $actor = $this->configureAiActor();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'spammer@example.test',
            'subject' => 'Not actionable',
            'body_text' => 'SECRET BODY 4',
            'received_at' => now(),
        ]);
        $token = $this->token(['dismiss_email_item'], 'chet');

        $response = $this->callTool($token, 'dismiss_email_item', [
            'email_id' => $email->id,
            'reason' => 'duplicate of T-100',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $email->refresh();
        $this->assertNotNull($email->dismissed_at);
        $this->assertSame($actor->id, $email->dismissed_by);

        $log = TechnicianActionLog::where('action_type', 'dismiss_email_item')->firstOrFail();
        $this->assertStringContainsString('duplicate of T-100', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET BODY 4', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET BODY 4', (string) json_encode(McpAuditLog::all()->toArray()));
    }

    // ── link_call_to_ticket ──────────────────────────────────────────────────

    public function test_link_call_to_ticket_reuses_service(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $call = $this->makeCall([
            'call_uuid' => 'call-manage-link-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550300',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'transcription' => 'SECRET TRANSCRIPT',
        ], $ticket->client_id);
        $token = $this->token(['link_call_to_ticket'], 'chet');

        $response = $this->callTool($token, 'link_call_to_ticket', [
            'phone_call_id' => $call->id,
            'ticket_id' => $ticket->id,
            'reason' => 'same issue',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $this->assertSame($ticket->id, $call->fresh()->ticket_id);

        $log = TechnicianActionLog::where('action_type', 'link_call_to_ticket')->firstOrFail();
        $this->assertStringContainsString((string) $call->id, (string) $log->summary);
        $this->assertStringContainsString((string) $ticket->id, (string) $log->summary);
        $this->assertStringContainsString('same issue', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET TRANSCRIPT', (string) $log->summary);

        $this->assertStringNotContainsString('SECRET TRANSCRIPT', (string) json_encode(McpAuditLog::all()->toArray()));
    }

    public function test_link_call_to_ticket_rejects_unknown_ids(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $call = $this->makeCall([
            'call_uuid' => 'call-manage-link-2',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550301',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ]);
        $token = $this->token(['link_call_to_ticket'], 'chet');

        $badCall = $this->callTool($token, 'link_call_to_ticket', ['phone_call_id' => 999999, 'ticket_id' => $ticket->id, 'reason' => 'x']);
        $badCall->assertOk();
        $this->assertTrue((bool) $badCall->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $badCall->json('result.content.0.text'));

        $badTicket = $this->callTool($token, 'link_call_to_ticket', ['phone_call_id' => $call->id, 'ticket_id' => 999999, 'reason' => 'x']);
        $badTicket->assertOk();
        $this->assertTrue((bool) $badTicket->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $badTicket->json('result.content.0.text'));
    }

    // ── create_ticket_from_call ──────────────────────────────────────────────

    public function test_create_ticket_from_call_requires_client(): void
    {
        $this->configureAiActor();
        $token = $this->token(['create_ticket_from_call'], 'chet');

        $orphan = $this->makeCall([
            'call_uuid' => 'call-manage-create-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550302',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'transcription' => 'SECRET TRANSCRIPT 2',
        ]);

        $blocked = $this->callTool($token, 'create_ticket_from_call', [
            'phone_call_id' => $orphan->id,
            'reason' => 'voicemail follow-up',
        ]);
        $blocked->assertOk();
        $this->assertTrue((bool) $blocked->json('result.isError'));
        $this->assertStringContainsString('client', (string) $blocked->json('result.content.0.text'));
        $this->assertNull($orphan->fresh()->ticket_id);
        $this->assertSame(0, Ticket::query()->count());

        $client = Client::factory()->create();
        $resolved = $this->makeCall([
            'call_uuid' => 'call-manage-create-2',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550303',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(2),
            'transcription' => 'SECRET TRANSCRIPT 3',
        ], $client->id);

        $ok = $this->callTool($token, 'create_ticket_from_call', [
            'phone_call_id' => $resolved->id,
            'reason' => 'voicemail follow-up',
        ]);
        $ok->assertOk();
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));

        $result = $this->decodedResult($ok);
        $ticket = Ticket::findOrFail($result['ticket_id']);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($ticket->id, $resolved->fresh()->ticket_id);

        $log = TechnicianActionLog::where('action_type', 'create_ticket_from_call')->firstOrFail();
        $this->assertStringContainsString((string) $resolved->id, (string) $log->summary);
        $this->assertStringContainsString((string) $ticket->id, (string) $log->summary);
        $this->assertStringNotContainsString('SECRET TRANSCRIPT 3', (string) $log->summary);
        $this->assertStringNotContainsString('SECRET TRANSCRIPT 3', (string) json_encode(McpAuditLog::all()->toArray()));
    }

    // ── cross-cutting: reason + kill-switch ──────────────────────────────────

    public function test_manage_verbs_require_reason(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'x@example.test',
            'subject' => 'x',
            'received_at' => now(),
            'client_id' => $ticket->client_id,
        ]);
        $call = $this->makeCall([
            'call_uuid' => 'call-manage-reason-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550304',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ], $ticket->client_id);

        $token = $this->token(self::ALL_TOOLS, 'chet');

        $argumentsByTool = [
            'link_email_to_ticket' => ['email_id' => $email->id, 'ticket_id' => $ticket->id],
            'create_ticket_from_email' => ['email_id' => $email->id],
            'dismiss_email_item' => ['email_id' => $email->id],
            'link_call_to_ticket' => ['phone_call_id' => $call->id, 'ticket_id' => $ticket->id],
            'create_ticket_from_call' => ['phone_call_id' => $call->id],
        ];

        foreach ($argumentsByTool as $tool => $arguments) {
            $response = $this->callTool($token, $tool, $arguments);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} without reason should error.");
            $this->assertStringContainsString('reason is required', (string) $response->json('result.content.0.text'));
        }

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertNull($email->fresh()->dismissed_at);
        $this->assertNull($call->fresh()->ticket_id);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_kill_switch_blocks_manage_verbs(): void
    {
        $this->configureAiActor();
        $ticket = Ticket::factory()->create();
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'x@example.test',
            'subject' => 'x',
            'received_at' => now(),
            'client_id' => $ticket->client_id,
        ]);
        $call = $this->makeCall([
            'call_uuid' => 'call-manage-kill-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550305',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ], $ticket->client_id);

        Setting::setValue('technician_kill_switch', '1');

        $token = $this->token(self::ALL_TOOLS, 'chet');

        $argumentsByTool = [
            'link_email_to_ticket' => ['email_id' => $email->id, 'ticket_id' => $ticket->id, 'reason' => 'x'],
            'create_ticket_from_email' => ['email_id' => $email->id, 'reason' => 'x'],
            'dismiss_email_item' => ['email_id' => $email->id, 'reason' => 'x'],
            'link_call_to_ticket' => ['phone_call_id' => $call->id, 'ticket_id' => $ticket->id, 'reason' => 'x'],
            'create_ticket_from_call' => ['phone_call_id' => $call->id, 'reason' => 'x'],
        ];

        foreach ($argumentsByTool as $tool => $arguments) {
            $response = $this->callTool($token, $tool, $arguments);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be blocked by kill switch.");
            $this->assertStringContainsString('kill-switch', (string) $response->json('result.content.0.text'));
        }

        $this->assertNull($email->fresh()->ticket_id);
        $this->assertNull($email->fresh()->dismissed_at);
        $this->assertNull($call->fresh()->ticket_id);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }
}
