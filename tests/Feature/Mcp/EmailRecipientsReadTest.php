<?php

namespace Tests\Feature\Mcp;

use App\Enums\EmailDirection;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Mcp\TicketTimeline;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card Tk3X8x1p: MCP email reads must expose To/Cc so a caller can tell an
 * email addressed to the help desk from one where it was only copied.
 * Synthetic data only (G-13).
 */
class EmailRecipientsReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @param  array<string, mixed>  $extra */
    private function ccOnlyEmail(array $extra = []): Email
    {
        return Email::create($extra + [
            'graph_id' => (string) Str::uuid(),
            'direction' => EmailDirection::Inbound,
            'from_address' => 'sender@example.test',
            'from_name' => 'Synthetic Sender',
            'to_recipients' => [['name' => 'Other Desk', 'address' => 'Desk@Other.example.test']],
            'cc_recipients' => [
                ['name' => 'Help Desk', 'address' => 'helpdesk@example.test'],
                ['name' => null, 'address' => 'third@example.test'],
            ],
            'subject' => 'Copied for visibility',
            'body_preview' => 'Synthetic preview',
            'body_text' => 'Synthetic body',
            'received_at' => now(),
        ]);
    }

    public function test_get_email_item_returns_to_and_cc_with_names(): void
    {
        $email = $this->ccOnlyEmail();
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_email_item'], label: 'chet');

        $item = $this->decoded($this->callTool($token, 'get_email_item', ['email_id' => $email->id]))['email_item'];

        $this->assertSame([['address' => 'desk@other.example.test', 'name' => 'Other Desk']], $item['to_recipients']);
        $this->assertSame([
            ['address' => 'helpdesk@example.test', 'name' => 'Help Desk'],
            ['address' => 'third@example.test', 'name' => null],
        ], $item['cc_recipients']);
    }

    public function test_get_email_item_tolerates_legacy_string_and_empty_recipients(): void
    {
        $legacy = $this->ccOnlyEmail([
            'to_recipients' => ['legacy@example.test', ''],
            'cc_recipients' => null,
        ]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['get_email_item'], label: 'chet');

        $item = $this->decoded($this->callTool($token, 'get_email_item', ['email_id' => $legacy->id]))['email_item'];

        $this->assertSame([['address' => 'legacy@example.test', 'name' => null]], $item['to_recipients']);
        $this->assertSame([], $item['cc_recipients']);
    }

    public function test_list_email_items_rows_carry_compact_address_lists(): void
    {
        $email = $this->ccOnlyEmail();
        $token = McpConfig::rotateStaffToken(allowedTools: ['list_email_items'], label: 'chet');

        $rows = $this->decoded($this->callTool($token, 'list_email_items', []))['email_items'];
        $row = collect($rows)->firstWhere('id', $email->id);

        $this->assertSame(['desk@other.example.test'], $row['to']);
        $this->assertSame(['helpdesk@example.test', 'third@example.test'], $row['cc']);
        $this->assertArrayNotHasKey('body_text', $row);
    }

    public function test_timeline_email_rows_and_mirrored_notes_carry_recipients(): void
    {
        $ticket = Ticket::factory()->for(Client::factory())->create();
        $envelope = $this->ccOnlyEmail(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'received_at' => '2026-01-01 09:00:00']);
        $mirrored = $this->ccOnlyEmail(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'received_at' => '2026-01-01 10:00:00', 'to_recipients' => [['address' => 'helpdesk@example.test']],
            'cc_recipients' => null]);
        TicketNote::create(['ticket_id' => $ticket->id, 'email_id' => $mirrored->id, 'author_name' => 'Client',
            'noted_at' => '2026-01-01 10:00:00', 'body' => 'Mirrored correspondence', 'note_type' => 'reply']);
        TicketNote::create(['ticket_id' => $ticket->id, 'noted_at' => '2026-01-01 11:00:00',
            'body' => 'Plain staff note', 'note_type' => 'note']);

        $items = collect(app(TicketTimeline::class)->page($ticket)['items']);

        $email = $items->firstWhere('id', 'email:'.$envelope->id);
        $this->assertSame(['desk@other.example.test'], $email['to']);
        $this->assertSame(['helpdesk@example.test', 'third@example.test'], $email['cc']);

        $note = $items->first(fn ($i) => $i['kind'] === 'note' && ($i['email_id'] ?? null) === $mirrored->id);
        $this->assertNotNull($note, 'The mirrored note must name its email');
        $this->assertSame(['helpdesk@example.test'], $note['to']);
        $this->assertSame([], $note['cc']);

        $plain = $items->first(fn ($i) => $i['kind'] === 'note' && ! isset($i['email_id']));
        $this->assertNotNull($plain);
        $this->assertArrayNotHasKey('to', $plain);
    }

    public function test_get_ticket_notes_marks_email_notes_with_recipients(): void
    {
        $ticket = Ticket::factory()->for(Client::factory())->create();
        $email = $this->ccOnlyEmail(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id]);
        TicketNote::create(['ticket_id' => $ticket->id, 'email_id' => $email->id, 'author_name' => 'Client',
            'noted_at' => now()->subMinute(), 'body' => 'Mirrored correspondence', 'note_type' => 'reply']);
        TicketNote::create(['ticket_id' => $ticket->id, 'noted_at' => now(), 'body' => 'Plain staff note', 'note_type' => 'note']);

        $token = McpConfig::rotateStaffToken(allowedTools: ['get_ticket_notes'], label: 'chet');
        $notes = $this->decoded($this->callTool($token, 'get_ticket_notes',
            ['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id]));

        $mirrored = collect($notes)->firstWhere('email_id', $email->id);
        $this->assertNotNull($mirrored);
        $this->assertSame(['desk@other.example.test'], $mirrored['to']);
        $this->assertSame(['helpdesk@example.test', 'third@example.test'], $mirrored['cc']);

        $plain = collect($notes)->firstWhere('body', 'Plain staff note');
        $this->assertArrayNotHasKey('to', $plain);
    }
}
