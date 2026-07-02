<?php

namespace Tests\Feature\Chet;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class ChetSendReplyTest extends TestCase
{
    use RefreshDatabase;

    private function chetToken(array $tools = ['send_reply']): string
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

    private function ticketWithClientReply(?Client $client = null): Ticket
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        User::factory()->create(['name' => 'Chet']);

        $client ??= Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Client Contact',
            'who_type' => WhoType::EndUser,
            'ai_authored' => false,
            'body' => 'Can you send an update?',
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        return $ticket;
    }

    public function test_chet_send_reply_lists_client_scoped_schema_with_optional_body(): void
    {
        $tool = collect($this->tools($this->chetToken()))->firstWhere('name', 'send_reply');

        $this->assertNotNull($tool);
        $this->assertSame(['ticket_id', 'reason', 'client_id'], $tool['inputSchema']['required']);
        $this->assertArrayHasKey('body', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('to', $tool['inputSchema']['properties']);
    }

    public function test_chet_body_lands_verbatim_as_a_held_run_without_drafting_or_sending(): void
    {
        Setting::setValue('technician_action_tiers', json_encode(['send_reply' => 'auto']));
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $mock) => $mock->shouldReceive('draft')->never());
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = $this->ticketWithClientReply($client);
        $body = 'Thanks for the update. We found the mailbox rule and will remove it now.';

        $response = $this->callTool($token, 'send_reply', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Chet has enough context from Teams and ticket history to draft the client update.',
            'body' => $body,
            'to' => 'attacker@example.test',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($body, $run->proposed_content);
        $this->assertSame(['Chet has enough context from Teams and ticket history to draft the client update.'], $run->proposed_meta['reasons']);
        $this->assertSame('mcp-staff:chet', $run->proposed_meta['drafted_by']);

        $this->assertSame(1, TechnicianActionLog::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('result_status', 'awaiting_approval')
            ->count());
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('result_status', 'executed')
            ->count());
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());

        $audit = McpAuditLog::where('method', 'tools/call')->where('tool_name', 'send_reply')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame(strlen($body), $audit->arguments['body_length']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
        $this->assertStringNotContainsString('attacker@example.test', (string) json_encode($audit->arguments));
    }

    public function test_bodyless_send_reply_uses_drafter_fallback(): void
    {
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $mock) => $mock->shouldReceive('draft')
            ->once()
            ->andReturn(new TechnicianDraft('Server drafted fallback body.', 'client@example.test', 91)));
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $ticket = $this->ticketWithClientReply($client);

        $response = $this->callTool($token, 'send_reply', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'reason' => 'Chet wants the PSA drafter to compose the body.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->firstOrFail();
        $this->assertSame('Server drafted fallback body.', $run->proposed_content);
        $this->assertSame('client@example.test', $run->proposed_meta['to']);
        $this->assertSame(['Chet wants the PSA drafter to compose the body.'], $run->proposed_meta['reasons']);
        $this->assertSame('technician-drafter', $run->proposed_meta['drafted_by']);
    }

    public function test_chet_send_reply_requires_client_scope_and_ticket_ownership(): void
    {
        $token = $this->chetToken();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $otherTicket = $this->ticketWithClientReply($otherClient);

        $missing = $this->callTool($token, 'send_reply', [
            'ticket_id' => $otherTicket->id,
            'reason' => 'Missing client scope.',
            'body' => 'This should not be held.',
        ]);
        $missing->assertOk();
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $missing->json('result.content.0.text'));

        $malformed = $this->callTool($token, 'send_reply', [
            'client_id' => 'abc',
            'ticket_id' => $otherTicket->id,
            'reason' => 'Malformed client scope.',
            'body' => 'This should not be held.',
        ]);
        $malformed->assertOk();
        $this->assertTrue((bool) $malformed->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $malformed->json('result.content.0.text'));

        $crossClient = $this->callTool($token, 'send_reply', [
            'client_id' => $client->id,
            'ticket_id' => $otherTicket->id,
            'reason' => 'Cross-client write.',
            'body' => 'This should not be held.',
        ]);
        $crossClient->assertOk();
        $this->assertTrue((bool) $crossClient->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossClient->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $otherTicket->id)->where('action_type', 'send_reply')->count());

        $errorAudits = McpAuditLog::where('method', 'tools/call')
            ->where('tool_name', 'send_reply')
            ->where('status', 'error')
            ->pluck('arguments')
            ->all();
        $this->assertNotEmpty($errorAudits);
        foreach ($errorAudits as $arguments) {
            $this->assertSame(strlen('This should not be held.'), $arguments['body_length']);
            $this->assertStringNotContainsString('This should not be held.', (string) json_encode($arguments));
        }
    }

    public function test_token_without_send_reply_scope_is_denied(): void
    {
        $token = $this->chetToken(['propose_close']);
        $client = Client::factory()->create();
        $ticket = $this->ticketWithClientReply($client);

        $this->assertNotContains('send_reply', collect($this->tools($token))->pluck('name')->all());

        $response = $this->callTool($token, 'send_reply', [
            'client_id' => $client->id,
            'ticket_id' => $ticket->id,
            'reason' => 'No token scope.',
            'body' => 'This should not be held.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }
}
