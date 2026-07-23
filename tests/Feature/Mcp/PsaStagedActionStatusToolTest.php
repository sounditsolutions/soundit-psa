<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * psa-gq7by: get_staged_action_status — a READ over the existing staged/held
 * action lane (TechnicianRun), so an agent can see what is awaiting a human
 * without guessing. Core to propose-don't-execute VISIBILITY while Charlie is
 * away. Dormant + grant-gated in the psa_read group (Charlie grants, never us).
 *
 * PENDING deliberately mirrors CockpitQuery::pendingCount() — awaiting_approval,
 * flagged, queued_offline, expired — so this tool can never drift from the
 * number the cockpit badge shows a human.
 */
class PsaStagedActionStatusToolTest extends TestCase
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

    /** @param  array<string, mixed>  $arguments */
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

    private function stagedRun(Ticket $ticket, string $actionType, TechnicianRunState $state, string $seed, array $overrides = []): TechnicianRun
    {
        return TechnicianRun::create(array_merge([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', $seed),
            'state' => $state,
            'proposed_content' => 'SECRET DRAFT BODY '.$seed,
            'proposed_meta' => ['drafted_by_token' => 'chet'],
            'tokens_used' => 0,
        ], $overrides));
    }

    // ── dormancy / grant gate ────────────────────────────────────────────────

    public function test_registry_lists_the_tool_in_psa_read_and_it_is_dormant(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $names = array_column($groups['psa_read']['tools'], 'name');
        $this->assertContains('get_staged_action_status', $names);

        // Dormant: a legacy (no-grant) token must not even see it advertised.
        $legacyNames = array_column($this->tools($this->legacyToken()), 'name');
        $this->assertNotContains('get_staged_action_status', $legacyNames);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_it(): void
    {
        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            $response = $this->callTool($token, 'get_staged_action_status');
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }
    }

    // ── behaviour ────────────────────────────────────────────────────────────

    public function test_lists_every_pending_state_and_excludes_terminal_ones(): void
    {
        $ticket = Ticket::factory()->create();

        $awaiting = $this->stagedRun($ticket, 'propose_close', TechnicianRunState::AwaitingApproval, 'a');
        $flagged = $this->stagedRun($ticket, 'flag_attention', TechnicianRunState::Flagged, 'b');
        $queued = $this->stagedRun($ticket, 'tactical_stage_reboot', TechnicianRunState::QueuedOffline, 'c');
        $expired = $this->stagedRun($ticket, 'stage_email', TechnicianRunState::Expired, 'd');
        // Terminal — must NOT be reported as pending.
        $done = $this->stagedRun($ticket, 'propose_close', TechnicianRunState::Done, 'e');
        $denied = $this->stagedRun($ticket, 'stage_email', TechnicianRunState::Denied, 'f');
        $superseded = $this->stagedRun($ticket, 'send_reply', TechnicianRunState::Superseded, 'g');

        $result = $this->decodedResult($this->callTool($this->token(['get_staged_action_status']), 'get_staged_action_status'));

        $ids = array_column($result['staged_actions'], 'id');
        foreach ([$awaiting, $flagged, $queued, $expired] as $pending) {
            $this->assertContains($pending->id, $ids, "pending run {$pending->action_type} must be listed");
        }
        foreach ([$done, $denied, $superseded] as $terminal) {
            $this->assertNotContains($terminal->id, $ids, "terminal run {$terminal->action_type} must not be listed");
        }
        $this->assertSame(4, $result['pending_total'], 'pending_total must match the cockpit badge definition');
    }

    public function test_rows_carry_the_state_and_routing_metadata(): void
    {
        $ticket = Ticket::factory()->create();
        $run = $this->stagedRun($ticket, 'propose_close', TechnicianRunState::AwaitingApproval, 'meta');

        $result = $this->decodedResult($this->callTool($this->token(['get_staged_action_status']), 'get_staged_action_status'));
        $row = collect($result['staged_actions'])->firstWhere('id', $run->id);

        $this->assertNotNull($row);
        $this->assertSame('propose_close', $row['action_type']);
        $this->assertSame('awaiting_approval', $row['state']);
        $this->assertSame($ticket->id, $row['ticket_id']);
        $this->assertArrayHasKey('created_at', $row);
    }

    /** The draft BODY is client-facing text for the cockpit, not for a status read. */
    public function test_never_leaks_the_draft_body(): void
    {
        $ticket = Ticket::factory()->create();
        $this->stagedRun($ticket, 'stage_email', TechnicianRunState::AwaitingApproval, 'body');

        $response = $this->callTool($this->token(['get_staged_action_status']), 'get_staged_action_status');

        $this->assertStringNotContainsString('SECRET DRAFT BODY', (string) $response->json('result.content.0.text'));
    }

    public function test_filters_by_state_and_action_type(): void
    {
        $ticket = Ticket::factory()->create();
        $close = $this->stagedRun($ticket, 'propose_close', TechnicianRunState::AwaitingApproval, 'h');
        $flag = $this->stagedRun($ticket, 'flag_attention', TechnicianRunState::Flagged, 'i');

        $token = $this->token(['get_staged_action_status']);

        $byState = $this->decodedResult($this->callTool($token, 'get_staged_action_status', ['state' => 'flagged']));
        $this->assertSame([$flag->id], array_column($byState['staged_actions'], 'id'));

        $byType = $this->decodedResult($this->callTool($token, 'get_staged_action_status', ['action_type' => 'propose_close']));
        $this->assertSame([$close->id], array_column($byType['staged_actions'], 'id'));
    }

    public function test_client_id_argument_fences_results_to_that_client(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $myTicket = Ticket::factory()->create(['client_id' => $mine->id]);
        $otherTicket = Ticket::factory()->create(['client_id' => $other->id]);

        $keep = $this->stagedRun($myTicket, 'propose_close', TechnicianRunState::AwaitingApproval, 'j');
        $hide = $this->stagedRun($otherTicket, 'propose_close', TechnicianRunState::AwaitingApproval, 'k');

        $result = $this->decodedResult($this->callTool(
            $this->token(['get_staged_action_status']),
            'get_staged_action_status',
            ['client_id' => $mine->id]
        ));

        $ids = array_column($result['staged_actions'], 'id');
        $this->assertContains($keep->id, $ids);
        $this->assertNotContains($hide->id, $ids, "another client's staged action must never appear under a client fence");
    }
}
