<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice B (psa-lulgh) calendar WRITE wiring, end-to-end through the real surfaces: the staff MCP
 * endpoint (mode gate: a staged-only grant parks a run and never calls Graph; an immediate grant
 * executes) AND the cockpit approval endpoint (a parked run, once approved, runs the identical
 * Graph write). Proves the controller routes the rewritten staged dispatch name
 * (calendar_stage_create_event) to the calendar executor and that the cockpit match arm reaches
 * approveStagedCalendarAction — the two seams that live outside the executor unit tests.
 */
class StaffCalendarWriteWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function enableCalendarLive(array $allowed = ['charlie@soundit.co']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
        // Complete graph config (incl. timeouts) — staging a run fires the AwaitingApproval
        // observer -> NotifyStagedActionAwaitingApproval, which constructs a GraphClient; a partial
        // config would crash its constructor on the missing request_timeout (not the write path).
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'request_timeout' => 15, 'token_timeout' => 10]]);
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
    }

    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function createArgs(Ticket $ticket): array
    {
        return [
            'user_upn' => 'charlie@soundit.co',
            'subject' => 'Onsite: printer swap',
            'start' => '2026-07-29T15:00:00',
            'end' => '2026-07-29T16:00:00',
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for an onsite.',
        ];
    }

    public function test_a_staged_grant_parks_a_run_via_the_endpoint_then_the_cockpit_approves_and_executes_it(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $approver = User::factory()->create();

        // createEvent fires EXACTLY once — at cockpit approval, never during staging. The ->once()
        // expectation itself fails the test if the approval arm 422s and never reaches Graph.
        $captured = null;
        $this->mock(GraphClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('createEvent')->once()->andReturnUsing(function (string $upn, array $body) use (&$captured) {
                $captured = compact('upn', 'body');

                return ['id' => 'AAMkAG-new', 'subject' => $body['subject'], 'webLink' => 'https://outlook/x'];
            });
        });

        // 1. STAGE through the MCP endpoint with a staged-only grant.
        $staged = $this->decodedResult($this->callTool($this->token(['calendar_create_event:staged']), 'calendar_create_event', $this->createArgs($ticket)));
        $this->assertTrue($staged['staged'] ?? false, 'a staged-only grant parks the write');

        $run = TechnicianRun::where('action_type', 'calendar_stage_create_event')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // 2. APPROVE through the cockpit endpoint.
        $this->actingAs($approver)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('charlie@soundit.co', $captured['upn']);
    }

    public function test_an_immediate_grant_executes_the_write_directly_via_the_endpoint(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();

        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('createEvent')->once()->andReturn(['id' => 'AAMkAG-new', 'subject' => 'Onsite: printer swap', 'webLink' => 'https://outlook/x']);
        });

        $result = $this->decodedResult($this->callTool($this->token(['calendar_create_event:immediate']), 'calendar_create_event', $this->createArgs($ticket)));

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame('created', $result['action'] ?? null);
        $this->assertArrayNotHasKey('staged', $result);
        $this->assertSame(0, TechnicianRun::count(), 'immediate execution parks no run');
    }

    public function test_the_write_audit_redacts_free_text_to_lengths_never_raw(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('createEvent')->once()->andReturn(['id' => 'AAMkAG-new', 'subject' => 'x', 'webLink' => 'https://x']);
        });

        $this->callTool($this->token(['calendar_create_event:immediate']), 'calendar_create_event', array_merge($this->createArgs($ticket), [
            'subject' => 'CONFIDENTIAL CLIENT MERGER PLANNING',
            'reason' => 'SECRET internal reason text',
        ]));

        $audit = McpAuditLog::where('tool_name', 'calendar_create_event')->latest('id')->first();
        $this->assertNotNull($audit);
        // Free text is present only as a length; structural scalars stay verbatim.
        $this->assertArrayHasKey('subject_length', $audit->arguments);
        $this->assertArrayHasKey('reason_length', $audit->arguments);
        $this->assertSame('charlie@soundit.co', $audit->arguments['user_upn']);
        $this->assertSame($ticket->id, $audit->arguments['ticket_id']);
        // The raw content must never appear anywhere in the audit row.
        $json = (string) json_encode($audit->arguments);
        $this->assertStringNotContainsString('CONFIDENTIAL CLIENT MERGER', $json);
        $this->assertStringNotContainsString('SECRET internal reason', $json);
    }

    public function test_a_legacy_full_surface_token_cannot_reach_calendar_writes(): void
    {
        // Explicit-grant-only: the tenant-wide staff calendar writes must never be inherited by the
        // legacy full-surface token (mirrors the Slice A read guard).
        $this->enableCalendarLive(['charlie@soundit.co']);
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->never());

        $legacy = McpConfig::rotateStaffToken(); // null allowlist = legacy full-surface
        $response = $this->callTool($legacy, 'calendar_create_event', $this->createArgs($ticket));

        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('not allowed', mb_strtolower($text));
        $this->assertSame(0, TechnicianRun::count());
    }
}
