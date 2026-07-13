<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The unified staged/immediate action surface (bead psa-aob9): one tool per
 * capability with a `staged` parameter, per-tool per-token mode grants
 * (name:staged / name:immediate), retired stage_* names as thin call-time
 * aliases, and auto-downgrade to staged when immediate is not granted.
 */
class McpStagedModeUnificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
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
    private function listTools(string $token): array
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

    private function ticketWithContact(): Ticket
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);

        return Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress,
            'closed_at' => null,
            'subject' => 'Printer is offline',
        ]);
    }

    public function test_grant_grammar_parses_modes_aliases_and_legacy_entries(): void
    {
        // Bare canonical = legacy immediate grant; bare alias = staged grant.
        $this->assertSame(['send_email', McpToolModes::MODE_IMMEDIATE], McpToolModes::parseGrantEntry('send_email'));
        $this->assertSame(['send_email', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('stage_email'));
        $this->assertSame(['tactical_run_script', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('tactical_stage_script'));

        // Explicit mode suffixes.
        $this->assertSame(['send_email', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('send_email:staged'));
        $this->assertSame(['send_email', McpToolModes::MODE_IMMEDIATE], McpToolModes::parseGrantEntry('send_email:immediate'));

        // A suffixed alias is still a staged grant, and non-stageable tools
        // never carry a mode.
        $this->assertSame(['send_email', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('stage_email:immediate'));
        $this->assertSame(['find_clients', null], McpToolModes::parseGrantEntry('find_clients'));
        $this->assertSame(['find_clients:staged', null], McpToolModes::parseGrantEntry('find_clients:staged'));

        // Immediate wins when the same capability is granted in both modes.
        $grants = McpToolModes::parseGrants(['stage_email', 'send_email', 'find_clients', 'tactical_run_script:staged']);
        $this->assertSame(['send_email', 'find_clients', 'tactical_run_script'], $grants['tools']);
        $this->assertSame(McpToolModes::MODE_IMMEDIATE, $grants['modes']['send_email']);
        $this->assertSame(McpToolModes::MODE_STAGED, $grants['modes']['tactical_run_script']);

        // Storage normalization: stageable tools always carry an explicit
        // mode; aliases collapse onto their canonical; unknowns are reported.
        $normalized = McpToolModes::normalizeGrantEntries(['stage_email', 'find_clients', 'send_email', 'bogus_tool']);
        $this->assertSame(['find_clients', 'send_email:immediate'], $normalized['entries']);
        $this->assertSame(['bogus_tool'], $normalized['unknown']);

        $stagedOnly = McpToolModes::normalizeGrantEntries(['stage_public_note']);
        $this->assertSame(['write_public_note:staged'], $stagedOnly['entries']);
    }

    public function test_staged_grant_stages_via_the_canonical_tool_with_staged_true(): void
    {
        $token = $this->token(['send_email:staged']);
        $ticket = $this->ticketWithContact();

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'staged' => true,
            'reason' => 'Draft for review.',
            'body' => 'Draft body.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertArrayNotHasKey('downgraded_to_staged', $result);

        // Dispatched under the internal staged name: same run action_type,
        // same audit tool_name as a legacy stage_email call.
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('Draft body.', $run->proposed_content);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, Email::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('mcp_audit_logs', ['tool_name' => 'stage_email', 'status' => 'success']);
    }

    public function test_staged_only_grant_auto_downgrades_an_immediate_call(): void
    {
        $token = $this->token(['send_email:staged']);
        $ticket = $this->ticketWithContact();

        // The token never gets to send directly, downgrade or not.
        $this->mock(EmailService::class, fn (MockInterface $mock) => $mock->shouldReceive('sendTicketReplyNote')->never());

        $response = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Attempted direct send.',
            'body' => 'Please execute now.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) ($result['downgraded_to_staged'] ?? false));
        $this->assertStringContainsString('downgraded to a staged proposal', (string) ($result['message'] ?? ''));

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('Please execute now.', $run->proposed_content);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_immediate_grant_executes_directly_and_can_still_stage(): void
    {
        $token = $this->token(['send_email:immediate']);
        $ticket = $this->ticketWithContact();

        $this->mock(EmailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        $direct = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Confirm fix to the contact.',
            'body' => 'All fixed.',
        ]);

        $direct->assertOk();
        $this->assertFalse((bool) $direct->json('result.isError'), (string) $direct->json('result.content.0.text'));
        $this->assertArrayNotHasKey('downgraded_to_staged', $this->decodedResult($direct));
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());

        // Immediate implies staged: the same token may still park a proposal.
        $stagedCall = $this->callTool($token, 'send_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'staged' => true,
            'reason' => 'Hold this one for review.',
            'body' => 'Held draft.',
        ]);

        $stagedCall->assertOk();
        $this->assertFalse((bool) $stagedCall->json('result.isError'), (string) $stagedCall->json('result.content.0.text'));
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame('Held draft.', $run->proposed_content);
    }

    public function test_retired_alias_names_remain_callable_for_migration_compat(): void
    {
        $token = $this->token(['stage_email']);
        $ticket = $this->ticketWithContact();

        $response = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'reason' => 'Legacy client still calls the alias.',
            'body' => 'Alias draft.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'stage_email')->firstOrFail();
        $this->assertSame('Alias draft.', $run->proposed_content);

        // An alias call never executes immediately, even with a stray
        // staged=false argument.
        $forced = $this->callTool($token, 'stage_email', [
            'client_id' => $ticket->client_id,
            'ticket_id' => $ticket->id,
            'staged' => false,
            'reason' => 'Alias with a stray staged flag.',
            'body' => 'Second alias draft.',
        ]);
        $forced->assertOk();
        $this->assertFalse((bool) $forced->json('result.isError'), (string) $forced->json('result.content.0.text'));
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_ungranted_stageable_tool_is_denied_under_both_names(): void
    {
        $token = $this->token(['find_clients']);
        $ticket = $this->ticketWithContact();

        foreach (['send_email', 'stage_email'] as $name) {
            $response = $this->callTool($token, $name, [
                'client_id' => $ticket->client_id,
                'ticket_id' => $ticket->id,
                'reason' => 'Should be denied.',
                'body' => 'Nope.',
            ]);

            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString("Tool not allowed for this token: {$name}", (string) $response->json('result.content.0.text'));
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_whoami_reports_per_tool_modes_for_stageable_grants(): void
    {
        User::factory()->create();
        $token = $this->token(['send_email:staged', 'tactical_run_script', 'find_staff'], 'chet');

        $payload = $this->decodedResult($this->callTool($token, 'whoami'));

        $this->assertSame(['whoami', 'list_tool_surface', 'send_email', 'tactical_run_script', 'find_staff'], $payload['allowed_tools']);
        $this->assertSame([
            'send_email' => 'staged',
            'tactical_run_script' => 'immediate',
        ], $payload['tool_modes']);

        // Tokens with no stageable grants keep the lean payload.
        $plain = $this->token(['find_staff'], 'reader');
        $this->assertArrayNotHasKey('tool_modes', $this->decodedResult($this->callTool($plain, 'whoami')));
    }

    public function test_tools_list_shapes_the_unified_schema_by_granted_mode(): void
    {
        User::factory()->create();
        $ticket = $this->ticketWithContact();

        // Staged-only grant: the canonical tool is advertised with the staged
        // variant's schema — ticket_id required — plus the staged marker. Since
        // psa-w4e0 the staged variant carries its own optional to/cc (held
        // proposals may name recipients, knob-gated server-side).
        $stagedTools = collect($this->listTools($this->token(['send_email:staged'], 'stager')))->keyBy('name');
        $this->assertFalse($stagedTools->has('stage_email'));
        $sendEmail = $stagedTools['send_email'];
        $this->assertContains('ticket_id', $sendEmail['inputSchema']['required']);
        $this->assertArrayHasKey('to', $sendEmail['inputSchema']['properties']);
        $this->assertArrayHasKey('cc', $sendEmail['inputSchema']['properties']);
        $this->assertNotContains('to', $sendEmail['inputSchema']['required'] ?? []);
        $this->assertArrayHasKey('staged', $sendEmail['inputSchema']['properties']);
        $this->assertStringContainsString('staged mode only', $sendEmail['description']);

        // Immediate grant: the direct schema plus the staged parameter and
        // the fields the staged path needs, marked conditional.
        $immediateTools = collect($this->listTools($this->token(['send_email:immediate'], 'runner')))->keyBy('name');
        $this->assertFalse($immediateTools->has('stage_email'));
        $sendEmail = $immediateTools['send_email'];
        $this->assertArrayHasKey('to', $sendEmail['inputSchema']['properties']);
        $this->assertArrayHasKey('staged', $sendEmail['inputSchema']['properties']);
        $this->assertStringContainsString('Supports staged=true', $sendEmail['description']);
        // psa-w4e0: the direct to/cc descriptions win the unification fold, so they
        // must describe BOTH modes — immediate rejection and the staged knob-gated
        // acceptance — or the advertised contract lies to immediate-granted tokens.
        $this->assertStringContainsString('staged custom recipients', $sendEmail['inputSchema']['properties']['to']['description']);
        $this->assertStringContainsString('staged=true', $sendEmail['inputSchema']['properties']['cc']['description']);
    }
}
