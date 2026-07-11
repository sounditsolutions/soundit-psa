<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Enums\ToolingGapClassification;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\User;
use App\Services\Agent\RequestToolTool;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * request_tool auto-classification (psa-ve9v). A "tool_missing" report whose
 * text names a tool that already exists in the MCP catalog is reclassified to
 * its real remedy — ToolUnused (already granted), ToolUngranted (operator
 * grant), or ToolUnconfigured (instance config) — so the backlog arrives
 * pre-classified instead of everything filing as "Tool missing". Matching is
 * purely lexical; genuinely unknown capabilities still file as build requests.
 */
class RequestToolAutoClassifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
    }

    private function ticketWithClient(): Ticket
    {
        return Ticket::factory()->for(Client::factory()->create())->create(['status' => TicketStatus::InProgress]);
    }

    private function callRequestTool(string $token, Ticket $ticket, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'request_tool', 'arguments' => array_merge(['ticket_id' => $ticket->id], $arguments)],
            ]);
    }

    private function responseMessage(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (string) (json_decode((string) $response->json('result.content.0.text'), true)['message'] ?? '');
    }

    public function test_report_naming_a_config_off_tool_files_as_tool_unconfigured(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $message = $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs huntress_list_escalations to check open SOC escalations for context',
            'classification' => 'tool_missing',
        ]));

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolUnconfigured, $gap->classification);
        $this->assertSame('huntress_list_escalations', $gap->tool_name);
        $this->assertStringContainsString('not configured', $message);
    }

    public function test_report_naming_a_live_ungranted_tool_files_as_an_enablement_request(): void
    {
        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $message = $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs huntress_list_escalations to check open SOC escalations for context',
            'classification' => 'tool_missing',
        ]));

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolUngranted, $gap->classification);
        $this->assertSame('huntress_list_escalations', $gap->tool_name);
        $this->assertStringContainsString('operator', $message);
        $this->assertStringContainsString('enablement request', $message);
    }

    public function test_report_naming_a_granted_tool_files_as_tool_unused(): void
    {
        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool', 'huntress_list_escalations'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $message = $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs huntress_list_escalations to check open SOC escalations for context',
            'classification' => 'tool_missing',
        ]));

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolUnused, $gap->classification);
        $this->assertSame('huntress_list_escalations', $gap->tool_name);
        $this->assertStringContainsString('granted', $message);
    }

    public function test_spaced_tool_name_mentions_match_the_catalog(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs a way to create ticket from email when intake resolves the client',
            'classification' => 'tool_missing',
        ]));

        $gap = ToolingGap::sole();
        // create_ticket_from_email is built and always live — an enablement ask.
        $this->assertSame(ToolingGapClassification::ToolUngranted, $gap->classification);
        $this->assertSame('create_ticket_from_email', $gap->tool_name);
    }

    public function test_unmatched_capability_still_files_as_a_build_request(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $message = $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs warranty expiry lookups against the vendor purchasing portal',
            'classification' => 'tool_missing',
        ]));

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolMissing, $gap->classification);
        $this->assertNull($gap->tool_name);
        $this->assertStringNotContainsString('already exists', $message);
    }

    public function test_tool_broken_reports_are_never_reclassified(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'ninja_get_device returned an empty payload for a device that clearly exists',
            'classification' => 'tool_broken',
            'tool_name' => 'ninja_get_device',
        ]));

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolBroken, $gap->classification);
        $this->assertSame('ninja_get_device', $gap->tool_name);
    }

    public function test_system_classifications_are_not_agent_selectable(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['request_tool'], label: 'chet');
        $ticket = $this->ticketWithClient();

        $this->responseMessage($this->callRequestTool($token, $ticket, [
            'capability_gap' => 'needs warranty expiry lookups against the vendor purchasing portal',
            'classification' => 'tool_ungranted',
        ]));

        // The self-selected system state fails back to ToolMissing (no catalog match).
        $this->assertSame(ToolingGapClassification::ToolMissing, ToolingGap::sole()->classification);
    }

    public function test_internal_callers_without_grant_context_classify_live_tools_as_ungranted(): void
    {
        $ticket = $this->ticketWithClient();

        $result = (new RequestToolTool(new WikiRedactor))->execute($ticket, [
            'capability_gap' => 'needs list_open_tickets to see the current queue before proposing action',
            'classification' => 'tool_missing',
        ]);

        $gap = ToolingGap::sole();
        $this->assertSame(ToolingGapClassification::ToolUngranted, $gap->classification);
        $this->assertSame('list_open_tickets', $gap->tool_name);
        $this->assertStringContainsString('Logged a tooling-gap', $result);
    }
}
