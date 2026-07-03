<?php

namespace Tests\Feature\Chet;

use App\Enums\TicketStatus;
use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChetRequestToolTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools = ['request_tool'], string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
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
    private function decodedToolResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?: [];
    }

    private function ticketWithClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    public function test_chet_request_tool_is_grant_gated_and_lists_ticket_schema(): void
    {
        $tool = collect($this->tools($this->token()))->firstWhere('name', 'request_tool');

        $this->assertNotNull($tool);
        $this->assertSame(['ticket_id', 'capability_gap', 'classification'], $tool['inputSchema']['required']);
        $this->assertArrayHasKey('ticket_id', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('capability_gap', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('classification', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('note', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('client_id', $tool['inputSchema']['properties']);
    }

    public function test_chet_request_tool_records_tooling_gap_only(): void
    {
        $ticket = $this->ticketWithClient();
        $originalStatus = $ticket->status;
        $originalUpdatedAt = $ticket->updated_at->toDateTimeString();

        $response = $this->callTool($this->token(), 'request_tool', [
            'ticket_id' => $ticket->id,
            'capability_gap' => 'needs a reusable lookup for recent matching tickets on the same client',
            'classification' => 'tool_missing',
            'note' => 'Chet saw enough hints that this issue may be recurring.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $payload = $this->decodedToolResult($response);
        $this->assertTrue($payload['success']);
        $this->assertSame($ticket->id, $payload['ticket_id']);
        $this->assertStringContainsString('Logged a tooling-gap', $payload['message']);

        $gap = ToolingGap::firstOrFail();
        $this->assertSame($ticket->id, $gap->ticket_id);
        $this->assertSame($ticket->client_id, $gap->client_id);
        $this->assertSame('needs a reusable lookup for recent matching tickets on the same client', $gap->capability_gap);
        $this->assertSame('Chet saw enough hints that this issue may be recurring.', $gap->agent_note);
        $this->assertSame(ToolingGapClassification::ToolMissing, $gap->classification);
        $this->assertSame(ToolingGapSource::Agent, $gap->source);
        $this->assertSame(ToolingGapStatus::Open, $gap->status);

        $this->assertSame($originalStatus, $ticket->fresh()->status);
        $this->assertSame($originalUpdatedAt, $ticket->fresh()->updated_at->toDateTimeString());
        $this->assertSame(0, TechnicianRun::count(), 'request_tool must not create any TechnicianRun.');

        $audit = McpAuditLog::where('method', 'tools/call')->where('tool_name', 'request_tool')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('mcp-staff:chet', $audit->actor_label);
    }

    public function test_request_tool_rejects_missing_or_unknown_ticket_without_recording(): void
    {
        $token = $this->token();

        $missing = $this->callTool($token, 'request_tool', [
            'capability_gap' => 'needs better diagnostics',
            'classification' => 'tool_missing',
        ]);
        $missing->assertOk();
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('ticket_id is required', (string) $missing->json('result.content.0.text'));

        $unknown = $this->callTool($token, 'request_tool', [
            'ticket_id' => 999999,
            'capability_gap' => 'needs better diagnostics',
            'classification' => 'tool_missing',
        ]);
        $unknown->assertOk();
        $this->assertTrue((bool) $unknown->json('result.isError'));
        $this->assertStringContainsString('Ticket not found', (string) $unknown->json('result.content.0.text'));

        $this->assertSame(0, ToolingGap::count());
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_token_without_request_tool_scope_is_denied(): void
    {
        $token = $this->token(['find_staff']);
        $ticket = $this->ticketWithClient();

        $this->assertNotContains('request_tool', collect($this->tools($token))->pluck('name')->all());

        $response = $this->callTool($token, 'request_tool', [
            'ticket_id' => $ticket->id,
            'capability_gap' => 'needs better diagnostics',
            'classification' => 'tool_missing',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, ToolingGap::count());
        $this->assertSame(0, TechnicianRun::count());
    }
}
