<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The portal MCP server (`/api/mcp/portal`) — the client-facing sibling of the
 * staff server. Its safety rests on: a bearer token authenticates the bridge,
 * an Entra Object ID resolves ONE portal Person (fail-closed), and every tool
 * is locked to that Person's client (honouring company-wide access) with scope
 * taken from identity, never from tool input.
 */
class McpPortalServerTest extends TestCase
{
    use RefreshDatabase;

    private const OBJECT_ID = '11111111-1111-1111-1111-111111111111';

    private function configureToken(): string
    {
        return McpConfig::rotatePortalToken();
    }

    private function activeClient(string $name): Client
    {
        // clients.stage defaults to 'active' at the DB level (and is not
        // mass-assignable), so a plain create yields an Active client.
        return Client::create(['name' => $name]);
    }

    private function portalPerson(
        Client $client,
        string $objectId = self::OBJECT_ID,
        bool $companyWide = false,
        bool $portalEnabled = true,
        bool $active = true,
    ): Person {
        return Person::create([
            'client_id' => $client->id,
            'cipp_user_id' => $objectId,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Portal',
            'email' => 'pat'.uniqid().'@example.test',
            'is_active' => $active,
            'portal_enabled' => $portalEnabled,
            'company_wide_access' => $companyWide,
            'password' => 'secret-portal-pw',
        ]);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], ?string $token = null, ?string $objectId = null): TestResponse
    {
        $headers = [];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        if ($objectId !== null) {
            $headers['X-Mcp-Portal-Object-Id'] = $objectId;
        }

        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
        if ($params !== []) {
            $payload['params'] = $params;
        }

        return $this->postJson('/api/mcp/portal', $payload, $headers);
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $tool, array $arguments, string $token, ?string $objectId): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $token, $objectId);
    }

    /** Decode the JSON payload a tool returned inside the MCP content envelope. */
    private function toolResult(TestResponse $response): array
    {
        return json_decode($response->json('result.content.0.text'), true);
    }

    // ── Transport / auth gates ────────────────────────────────────────────

    public function test_returns_503_when_no_token_configured(): void
    {
        // No token set — the server is dormant regardless of the bearer sent.
        $this->rpc('initialize', [], 'psa-mcp-portal-anything')
            ->assertStatus(503)
            ->assertJsonPath('error.message', 'Portal MCP server not configured');
    }

    public function test_rejects_missing_and_invalid_bearer_tokens(): void
    {
        $this->configureToken();

        $this->rpc('initialize')->assertStatus(401);
        $this->rpc('initialize', [], 'wrong-token')->assertStatus(401);
    }

    public function test_initialize_returns_server_info(): void
    {
        $token = $this->configureToken();

        $this->rpc('initialize', ['protocolVersion' => '2025-03-26'], $token)
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'PSA Portal')
            ->assertJsonPath('result.protocolVersion', '2025-03-26');
    }

    public function test_tools_list_returns_the_six_portal_tools_without_client_scope_inputs(): void
    {
        $token = $this->configureToken();

        $response = $this->rpc('tools/list', [], $token)->assertOk();

        $names = array_column($response->json('result.tools'), 'name');
        sort($names);
        $this->assertSame([
            'add_my_ticket_reply',
            'create_ticket',
            'get_my_ticket',
            'list_my_assets',
            'list_my_open_tickets',
            'search_my_tickets',
        ], $names);

        // No tool may expose a client/tenant selector — scope is identity-bound.
        foreach ($response->json('result.tools') as $tool) {
            $props = array_keys($tool['inputSchema']['properties'] ?? []);
            $this->assertNotContains('client_id', $props);
            $this->assertNotContains('tenant_id', $props);
        }
    }

    // ── Identity resolution ───────────────────────────────────────────────

    public function test_tool_call_without_object_id_header_is_error(): void
    {
        $token = $this->configureToken();

        $response = $this->callTool('list_my_open_tickets', [], $token, null)->assertOk();

        $this->assertTrue($this->rpcIsError($response));
        // The no-person path returns a plain-text message (not a JSON tool result).
        $this->assertStringContainsString('portal user', (string) $response->json('result.content.0.text'));
    }

    public function test_unknown_object_id_is_rejected(): void
    {
        $token = $this->configureToken();
        $this->activeClient('Acme'); // exists, but nobody has this object id

        $response = $this->callTool('list_my_open_tickets', [], $token, 'no-such-object-id')->assertOk();
        $this->assertTrue($this->rpcIsError($response));
    }

    public function test_non_portal_enabled_person_is_rejected(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $this->portalPerson($client, portalEnabled: false);

        $response = $this->callTool('list_my_open_tickets', [], $token, self::OBJECT_ID)->assertOk();
        $this->assertTrue($this->rpcIsError($response));
    }

    public function test_prospect_client_contact_is_rejected(): void
    {
        $token = $this->configureToken();
        // Factory prospect() state force-fills stage (which is not mass-assignable).
        $prospect = Client::factory()->prospect()->create(['name' => 'Prospect Co']);
        $this->portalPerson($prospect);

        $response = $this->callTool('list_my_open_tickets', [], $token, self::OBJECT_ID)->assertOk();
        $this->assertTrue($this->rpcIsError($response));
    }

    // ── Read tools: scoping ───────────────────────────────────────────────

    public function test_list_my_open_tickets_is_client_scoped_and_open_only(): void
    {
        $token = $this->configureToken();
        $mine = $this->activeClient('Mine Inc');
        $other = $this->activeClient('Other Inc');
        $this->portalPerson($mine, companyWide: true);

        Ticket::factory()->create(['client_id' => $mine->id, 'status' => TicketStatus::New, 'subject' => 'My open ticket']);
        Ticket::factory()->create(['client_id' => $mine->id, 'status' => TicketStatus::Closed, 'subject' => 'My closed ticket']);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New, 'subject' => 'SECRET other client ticket']);

        $result = $this->toolResult($this->callTool('list_my_open_tickets', [], $token, self::OBJECT_ID)->assertOk());

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertSame(['My open ticket'], $subjects);
    }

    public function test_without_company_wide_access_only_own_tickets_are_visible(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $me = $this->portalPerson($client, companyWide: false);
        $colleague = $this->portalPerson($client, objectId: 'colleague-oid', companyWide: false);

        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $me->id, 'status' => TicketStatus::New, 'subject' => 'Mine']);
        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $colleague->id, 'status' => TicketStatus::New, 'subject' => 'Colleague only']);

        $result = $this->toolResult($this->callTool('list_my_open_tickets', [], $token, self::OBJECT_ID)->assertOk());

        $this->assertSame(['Mine'], array_column($result['tickets'], 'subject'));
    }

    public function test_get_my_ticket_denies_cross_client_and_returns_own_with_notes(): void
    {
        $token = $this->configureToken();
        $mine = $this->activeClient('Mine Inc');
        $other = $this->activeClient('Other Inc');
        $me = $this->portalPerson($mine, companyWide: true);

        $ownTicket = Ticket::factory()->create(['client_id' => $mine->id, 'status' => TicketStatus::New, 'subject' => 'Printer down']);
        TicketNote::create([
            'ticket_id' => $ownTicket->id,
            'author_id' => null,
            'author_name' => 'Support',
            'who_type' => WhoType::Agent,
            'body' => 'We are on it',
            'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);
        // A private, system-generated note must never surface to the portal.
        TicketNote::create([
            'ticket_id' => $ownTicket->id,
            'author_id' => null,
            'author_name' => 'System',
            'who_type' => WhoType::System,
            'body' => 'internal triage only',
            'note_type' => \App\Enums\NoteType::AiTriage,
            'is_private' => true,
            'noted_at' => now(),
        ]);
        $otherTicket = Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New]);

        // Own ticket resolves with visible notes.
        $result = $this->toolResult($this->callTool('get_my_ticket', ['ticket_id' => $ownTicket->id], $token, self::OBJECT_ID)->assertOk());
        $this->assertSame('Printer down', $result['subject']);
        $messages = array_column($result['notes'], 'message');
        $this->assertContains('We are on it', $messages);
        $this->assertNotContains('internal triage only', $messages);

        // Cross-client ticket is not found (never a 403 leak of existence-by-error-shape).
        $cross = $this->toolResult($this->callTool('get_my_ticket', ['ticket_id' => $otherTicket->id], $token, self::OBJECT_ID)->assertOk());
        $this->assertArrayHasKey('error', $cross);
    }

    public function test_search_my_tickets_is_scoped(): void
    {
        $token = $this->configureToken();
        $mine = $this->activeClient('Mine Inc');
        $other = $this->activeClient('Other Inc');
        $this->portalPerson($mine, companyWide: true);

        Ticket::factory()->create(['client_id' => $mine->id, 'status' => TicketStatus::New, 'subject' => 'VPN connection failing']);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New, 'subject' => 'VPN issue for other client']);

        $result = $this->toolResult($this->callTool('search_my_tickets', ['query' => 'VPN'], $token, self::OBJECT_ID)->assertOk());

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertSame(['VPN connection failing'], $subjects);
    }

    public function test_search_my_tickets_requires_a_query(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $this->portalPerson($client, companyWide: true);

        $result = $this->toolResult($this->callTool('search_my_tickets', ['query' => '  '], $token, self::OBJECT_ID)->assertOk());
        $this->assertArrayHasKey('error', $result);
    }

    public function test_list_my_assets_returns_active_client_assets_only(): void
    {
        $token = $this->configureToken();
        $mine = $this->activeClient('Mine Inc');
        $other = $this->activeClient('Other Inc');
        $this->portalPerson($mine, companyWide: false);

        Asset::factory()->create(['client_id' => $mine->id, 'is_active' => true, 'hostname' => 'MINE-PC']);
        Asset::factory()->create(['client_id' => $mine->id, 'is_active' => false, 'hostname' => 'MINE-RETIRED']);
        Asset::factory()->create(['client_id' => $other->id, 'is_active' => true, 'hostname' => 'OTHER-PC']);

        $result = $this->toolResult($this->callTool('list_my_assets', [], $token, self::OBJECT_ID)->assertOk());

        $hostnames = array_column($result['devices'], 'hostname');
        $this->assertSame(['MINE-PC'], $hostnames);
    }

    // ── Write tools ───────────────────────────────────────────────────────

    public function test_create_ticket_opens_a_portal_ticket_for_the_caller(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $me = $this->portalPerson($client, companyWide: false);

        $result = $this->toolResult($this->callTool('create_ticket', [
            'subject' => 'New laptop request',
            'body' => 'Please provision a laptop for the new hire.',
            'urgency' => 'urgent',
        ], $token, self::OBJECT_ID)->assertOk());

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('ticket_display_id', $result);

        $ticket = Ticket::findOrFail($result['ticket_id']);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($me->id, $ticket->contact_id);
        $this->assertSame(TicketSource::Portal, $ticket->source);
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority); // urgent → P2
        $this->assertSame(TicketStatus::New, $ticket->status);
    }

    public function test_create_ticket_defaults_to_normal_priority(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $this->portalPerson($client);

        $result = $this->toolResult($this->callTool('create_ticket', [
            'subject' => 'Password help',
            'body' => 'I forgot my password.',
        ], $token, self::OBJECT_ID)->assertOk());

        $ticket = Ticket::findOrFail($result['ticket_id']);
        $this->assertSame(TicketPriority::P3, $ticket->priority); // default → P3
    }

    public function test_create_ticket_requires_subject_and_body(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $this->portalPerson($client);

        $result = $this->toolResult($this->callTool('create_ticket', ['subject' => 'x', 'body' => '  '], $token, self::OBJECT_ID)->assertOk());
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Ticket::count());
    }

    public function test_add_my_ticket_reply_adds_an_end_user_reply(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $me = $this->portalPerson($client, companyWide: false);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $me->id, 'status' => TicketStatus::PendingClient]);

        $result = $this->toolResult($this->callTool('add_my_ticket_reply', [
            'ticket_id' => $ticket->id,
            'body' => 'Here is the extra information you asked for.',
        ], $token, self::OBJECT_ID)->assertOk());

        $this->assertTrue($result['success']);

        $note = TicketNote::where('ticket_id', $ticket->id)->where('note_type', \App\Enums\NoteType::Reply)->firstOrFail();
        $this->assertSame(WhoType::EndUser, $note->who_type);
        $this->assertNull($note->author_id);
        $this->assertFalse($note->is_private);
        // PendingClient auto-transitions to InProgress when the client replies.
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_add_my_ticket_reply_denies_a_ticket_outside_scope(): void
    {
        $token = $this->configureToken();
        $mine = $this->activeClient('Mine Inc');
        $other = $this->activeClient('Other Inc');
        $this->portalPerson($mine, companyWide: true);
        $foreign = Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New]);

        $result = $this->toolResult($this->callTool('add_my_ticket_reply', [
            'ticket_id' => $foreign->id,
            'body' => 'trying to reach another client ticket',
        ], $token, self::OBJECT_ID)->assertOk());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, TicketNote::where('ticket_id', $foreign->id)->count());
    }

    // ── Audit ─────────────────────────────────────────────────────────────

    public function test_calls_are_audited_under_the_portal_server_with_redacted_bodies(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $me = $this->portalPerson($client);

        $this->callTool('create_ticket', [
            'subject' => 'Sensitive subject text',
            'body' => 'Confidential body text',
        ], $token, self::OBJECT_ID)->assertOk();

        $log = McpAuditLog::where('server_name', 'portal')->where('tool_name', 'create_ticket')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame('portal:'.$me->id, $log->actor_label);
        // The customer's words are not written verbatim to the audit trail.
        $this->assertStringNotContainsString('Confidential body text', json_encode($log->arguments));
        $this->assertStringNotContainsString('Sensitive subject text', json_encode($log->arguments));
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $token = $this->configureToken();
        $client = $this->activeClient('Acme');
        $this->portalPerson($client);

        $response = $this->callTool('delete_everything', [], $token, self::OBJECT_ID)->assertOk();
        $this->assertTrue($this->rpcIsError($response));
    }

    private function rpcIsError(TestResponse $response): bool
    {
        return (bool) $response->json('result.isError');
    }
}
