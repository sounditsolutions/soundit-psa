<?php

namespace Tests\Feature\Chet;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\TicketService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class AddTicketNoteToolTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(array $tools = ['find_staff', 'get_staff', 'add_ticket_note']): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
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

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array<int, string> */
    private function listToolNames(string $token): array
    {
        return collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools'))->pluck('name')->all();
    }

    private function configureAiActor(): User
    {
        User::factory()->create(['name' => 'Human First']);
        $chet = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $chet->id);

        return $chet;
    }

    public function test_chet_token_can_add_private_note_attributed_to_configured_ai_actor(): void
    {
        $actor = $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $body = 'Chet internal findings: DNS records are consistent; waiting on endpoint telemetry.';

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => $body,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $note = TicketNote::findOrFail($result['note_id']);

        $this->assertSame($ticket->id, $note->ticket_id);
        $this->assertSame($actor->id, $note->author_id);
        $this->assertSame('Chet', $note->author->name);
        $this->assertSame($body, $note->body);
        $this->assertSame(NoteType::Note, $note->note_type);
        $this->assertTrue((bool) $note->is_private);
        // fc0y item 2: Chet's notes are AI-authored — the flag keeps the
        // Technician's human-touch signals (e.g. EmergencySweep::hasHumanTouch)
        // from misreading a Chet note as "a human already engaged".
        $this->assertTrue((bool) $note->ai_authored);

        $audit = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'add_ticket_note')
            ->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-staff:chet', $audit->actor_label);
        $this->assertSame($ticket->id, $audit->arguments['ticket_id']);
        $this->assertSame('[note body withheld]', $audit->arguments['body']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
    }

    public function test_chet_note_write_requires_explicit_ai_actor_configuration(): void
    {
        User::factory()->create(['name' => 'Human First']);
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => 'This must not be attributed to the first random user.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString(
            'AI actor user is not configured',
            (string) $response->json('result.content.0.text'),
        );
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'add_ticket_note',
            'status' => 'error',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_chet_note_write_rejects_stale_ai_actor_configuration(): void
    {
        User::factory()->create(['name' => 'Human First']);
        $staleActor = User::factory()->create(['name' => 'Deleted Chet Actor']);
        Setting::setValue('triage_system_user_id', (string) $staleActor->id);
        $staleActor->delete();

        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => 'This must not fall back to the first remaining user.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString(
            'Configured AI actor user does not exist',
            (string) $response->json('result.content.0.text'),
        );
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'add_ticket_note',
            'status' => 'error',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_token_without_add_ticket_note_scope_is_denied_in_list_and_call(): void
    {
        $token = $this->chetToken(['find_staff', 'get_staff']);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->assertNotContains('add_ticket_note', $this->listToolNames($token));

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => 'Denied note',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_chet_token_does_not_publish_nonexistent_write_tools_even_if_accidentally_scoped(): void
    {
        $token = $this->chetToken(['find_staff', 'get_staff', 'add_ticket_note', 'close_ticket', 'tactical_run_diagnostic', 'propose_close']);

        $names = $this->listToolNames($token);
        $this->assertContains('add_ticket_note', $names);
        $this->assertNotContains('close_ticket', $names);
        $this->assertNotContains('tactical_run_diagnostic', $names);
        // Spike-2: propose_close is allowed-when-scoped (held-by-construction);
        // full behavior is covered in ChetProposeCloseTest.
        $this->assertContains('propose_close', $names);

        $client = Client::factory()->create();
        foreach (['close_ticket', 'tactical_run_diagnostic'] as $tool) {
            $response = $this->callTool($token, $tool, ['client_id' => $client->id]);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should fail.");
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $propose = $this->callTool($token, 'propose_close', [
            'ticket_id' => $ticket->id,
            'reason' => 'Chet must not propose closes through this data-surface token.',
            'confidence' => 0.99,
        ]);

        $propose->assertOk();
        $this->assertTrue((bool) $propose->json('result.isError'));
        // Spike-2: propose_close is scope-allowed for chet tokens but stays
        // client-scoped — without client_id the write guard rejects it.
        $this->assertStringContainsString('client_id is required', (string) $propose->json('result.content.0.text'));
        $this->assertSame(0, \App\Models\TechnicianRun::count());
    }

    public function test_chet_private_note_is_not_visible_in_client_portal(): void
    {
        Setting::setValue('portal_enabled', '1');
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'description' => 'Client-visible original ticket description.',
        ]);
        $privateBody = 'Chet private note: possible stale RMM telemetry, do not show client.';

        $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => $privateBody,
        ])->assertOk();

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-user@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Client-visible original ticket description.')
            ->assertDontSee($privateBody);
    }

    public function test_chet_note_write_respects_client_scope(): void
    {
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherTicket = Ticket::factory()->create(['client_id' => $otherClient->id]);

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $otherTicket->id,
            'body' => 'Cross-client write must be rejected.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $otherTicket->id)->count());
    }

    public function test_chet_note_write_requires_client_id_at_the_mcp_boundary(): void
    {
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $body = 'This should not reach the executor without explicit client scope.';

        $response = $this->callTool($token, 'add_ticket_note', [
            'ticket_id' => $ticket->id,
            'body' => $body,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        $audit = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'add_ticket_note')
            ->where('status', 'error')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('[note body withheld]', $audit->arguments['body']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
    }

    public function test_chet_note_write_rejects_malformed_client_id_at_the_mcp_boundary(): void
    {
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        foreach ([0, -1, 1.5, '1.5', 'abc'] as $clientId) {
            $response = $this->callTool($token, 'add_ticket_note', [
                'client_id' => $clientId,
                'ticket_id' => $ticket->id,
                'body' => 'Malformed client id should not be coerced.',
            ]);

            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), 'client_id='.var_export($clientId, true));
            $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
        }

        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_add_ticket_note_audit_withholds_mis_cased_body_argument(): void
    {
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $body = 'Mis-cased body argument must not persist in audit logs.';

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'Body' => $body,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());

        $audit = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'add_ticket_note')
            ->where('status', 'error')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('[note body withheld]', $audit->arguments['body']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
    }

    public function test_add_ticket_note_failure_does_not_persist_body_in_error_message(): void
    {
        $this->configureAiActor();
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $body = 'Failure-path note body must not be reflected into immutable audit errors.';

        $this->mock(TicketService::class, function (MockInterface $mock) use ($body): void {
            $mock->shouldReceive('addNote')
                ->once()
                ->andThrow(new \RuntimeException('simulated driver message containing '.$body));
        });

        $response = $this->callTool($token, 'add_ticket_note', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'body' => $body,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringNotContainsString($body, (string) $response->json('result.content.0.text'));

        $audit = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'add_ticket_note')
            ->where('status', 'error')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString($body, (string) $audit->error_message);
        $this->assertSame('[note body withheld]', $audit->arguments['body']);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }
}
