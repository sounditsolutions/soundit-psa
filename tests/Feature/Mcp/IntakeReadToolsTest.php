<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\TranscriptionStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the W2 Task 1 READ tools (list_email_items, get_email_item,
 * list_phone_calls, get_phone_call) added to the generalized, dormant, grant-gated
 * psa_read group. These are cross-client staff-class reads — client_id is an
 * OPTIONAL filter, never required (a woken Chet must be able to list unlinked /
 * unresolved items that have no client yet). Mirrors the PsaRecordsToolsTest /
 * PsaContractReadToolsTest harness conventions.
 */
class IntakeReadToolsTest extends TestCase
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

    public function test_read_tools_are_grant_gated_and_absent_from_legacy_token(): void
    {
        $names = ['list_email_items', 'get_email_item', 'list_phone_calls', 'get_phone_call'];

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $this->assertTrue($groups['psa_read']['sensitive']);

        $groupNames = array_column($groups['psa_read']['tools'], 'name');
        foreach ($names as $name) {
            $this->assertContains($name, $groupNames);
            $this->assertContains($name, McpToolRegistry::allToolNames());
        }

        // Dormant by default: a legacy full-surface token cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach ($names as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        // A granted token sees them, and client_id is NOT a required field —
        // these are cross-client reads, unlike list_client_contracts/get_contract.
        $scoped = collect($this->tools($this->token($names, 'chet')))->keyBy('name');
        foreach ($names as $name) {
            $this->assertTrue($scoped->has($name), "{$name} should be visible to a granted token");
        }
        $this->assertNotContains('client_id', $scoped['list_email_items']['inputSchema']['required']);
        $this->assertNotContains('client_id', $scoped['list_phone_calls']['inputSchema']['required']);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_read_tools(): void
    {
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@example.test',
            'subject' => 'Denied call test',
            'received_at' => now(),
        ]);

        $calls = [
            ['list_email_items', []],
            ['get_email_item', ['email_id' => $email->id]],
            ['list_phone_calls', []],
            ['get_phone_call', ['phone_call_id' => 1]],
        ];

        foreach ([$this->token(['create_ticket'], 'chet'), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }
    }

    public function test_list_email_items_returns_preview_not_body_and_respects_filters(): void
    {
        $ticket = Ticket::factory()->create();

        $linked = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'linked@example.test',
            'subject' => 'Linked email',
            'body_preview' => 'short preview',
            'body_text' => 'SECRET FULL BODY',
            'received_at' => now()->subHour(),
            'ticket_id' => $ticket->id,
        ]);

        $unlinked = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'unlinked@example.test',
            'subject' => 'Unlinked email',
            'body_preview' => 'short preview',
            'body_text' => 'SECRET FULL BODY',
            'received_at' => now(),
        ]);

        $token = $this->token(['list_email_items'], 'chet');

        $response = $this->callTool($token, 'list_email_items', ['unlinked' => true]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $ids = collect($result['email_items'])->pluck('id')->all();
        $this->assertContains($unlinked->id, $ids);
        $this->assertNotContains($linked->id, $ids);

        $row = collect($result['email_items'])->firstWhere('id', $unlinked->id);
        $this->assertSame('short preview', $row['body_preview']);
        $this->assertArrayNotHasKey('body_text', $row);

        // Neither email's secret body ever appears in the wire payload.
        $this->assertStringNotContainsString('SECRET FULL BODY', (string) $response->json('result.content.0.text'));
    }

    public function test_get_email_item_returns_full_body(): void
    {
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@example.test',
            'subject' => 'Full body test',
            'body_preview' => 'short preview',
            'body_text' => 'THE FULL BODY TEXT',
            'received_at' => now(),
        ]);

        $token = $this->token(['get_email_item'], 'chet');

        $response = $this->callTool($token, 'get_email_item', ['email_id' => $email->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('THE FULL BODY TEXT', $result['email_item']['body_text']);
        $this->assertSame($email->id, $result['email_item']['id']);
    }

    public function test_get_email_item_rejects_unknown_id(): void
    {
        $token = $this->token(['get_email_item'], 'chet');

        $response = $this->callTool($token, 'get_email_item', ['email_id' => 999999]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $response->json('result.content.0.text'));
    }

    public function test_list_phone_calls_excludes_transcript(): void
    {
        $completed = PhoneCall::create([
            'call_uuid' => 'call-list-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'to_number' => '+15555550000',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(10),
            'transcription' => 'SECRET TRANSCRIPT',
            'transcription_status' => TranscriptionStatus::Completed,
        ]);

        PhoneCall::create([
            'call_uuid' => 'call-list-2',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550101',
            'to_number' => '+15555550000',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'transcription_status' => TranscriptionStatus::Pending,
        ]);

        $token = $this->token(['list_phone_calls'], 'chet');

        $response = $this->callTool($token, 'list_phone_calls', []);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame(2, $result['count']);
        $row = collect($result['phone_calls'])->firstWhere('id', $completed->id);
        $this->assertArrayNotHasKey('transcription', $row);
        $this->assertStringNotContainsString('SECRET TRANSCRIPT', (string) $response->json('result.content.0.text'));

        // transcription_status filter narrows to just the completed call.
        $filtered = $this->callTool($token, 'list_phone_calls', ['transcription_status' => 'completed']);
        $filteredResult = $this->decodedResult($filtered);
        $this->assertSame(1, $filteredResult['count']);
        $this->assertSame($completed->id, $filteredResult['phone_calls'][0]['id']);
    }

    public function test_get_phone_call_returns_transcript(): void
    {
        $call = PhoneCall::create([
            'call_uuid' => 'call-get-1',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550199',
            'to_number' => '+15555550000',
            'status' => CallStatus::Completed,
            'started_at' => now(),
            'transcription' => 'THE FULL TRANSCRIPT',
            'transcription_status' => TranscriptionStatus::Completed,
        ]);

        $token = $this->token(['get_phone_call'], 'chet');

        $response = $this->callTool($token, 'get_phone_call', ['phone_call_id' => $call->id]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('THE FULL TRANSCRIPT', $result['phone_call']['transcription']);
        $this->assertSame($call->id, $result['phone_call']['id']);
    }

    public function test_read_limit_capped_at_50(): void
    {
        foreach (range(1, 3) as $i) {
            Email::create([
                'direction' => EmailDirection::Inbound,
                'from_address' => "person{$i}@example.test",
                'subject' => "Email {$i}",
                'received_at' => now()->subMinutes($i),
            ]);
        }

        $token = $this->token(['list_email_items'], 'chet');

        // Explicit small cap is honored.
        $capped = $this->decodedResult($this->callTool($token, 'list_email_items', ['limit' => 2]));
        $this->assertSame(2, $capped['count']);

        // A limit far above the hard cap of 50 never errors and never exceeds
        // what actually exists (there is no 51st row to prove the ceiling with,
        // but the handler must not choke on — or blindly honor — a huge limit).
        $high = $this->decodedResult($this->callTool($token, 'list_email_items', ['limit' => 999]));
        $this->assertSame(3, $high['count']);
        $this->assertLessThanOrEqual(50, $high['count']);
    }

    public function test_read_tools_filter_by_client_id_when_provided_and_are_cross_client_when_omitted(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $emailA = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'a@example.test',
            'subject' => 'Client A email',
            'received_at' => now()->subMinutes(2),
            'client_id' => $clientA->id,
        ]);
        $emailB = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'b@example.test',
            'subject' => 'Client B email',
            'received_at' => now()->subMinute(),
            'client_id' => $clientB->id,
        ]);

        // client_id is NOT in PhoneCall::$fillable (unlike Email) — mirrors the
        // established fixture pattern in CockpitActionJsonTest: create(), then
        // set the attribute directly and save().
        $callA = PhoneCall::create([
            'call_uuid' => 'call-client-a',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550201',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(2),
        ]);
        $callA->client_id = $clientA->id;
        $callA->save();

        $callB = PhoneCall::create([
            'call_uuid' => 'call-client-b',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550202',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinute(),
        ]);
        $callB->client_id = $clientB->id;
        $callB->save();

        $token = $this->token(['list_email_items', 'list_phone_calls'], 'chet');

        // client_id omitted: cross-client, sees both clients' rows.
        $allEmails = $this->decodedResult($this->callTool($token, 'list_email_items', []));
        $emailIds = collect($allEmails['email_items'])->pluck('id')->all();
        $this->assertContains($emailA->id, $emailIds);
        $this->assertContains($emailB->id, $emailIds);

        $allCalls = $this->decodedResult($this->callTool($token, 'list_phone_calls', []));
        $callIds = collect($allCalls['phone_calls'])->pluck('id')->all();
        $this->assertContains($callA->id, $callIds);
        $this->assertContains($callB->id, $callIds);

        // client_id provided: scoped to exactly that client.
        $scopedEmails = $this->decodedResult($this->callTool($token, 'list_email_items', ['client_id' => $clientA->id]));
        $scopedEmailIds = collect($scopedEmails['email_items'])->pluck('id')->all();
        $this->assertContains($emailA->id, $scopedEmailIds);
        $this->assertNotContains($emailB->id, $scopedEmailIds);

        $scopedCalls = $this->decodedResult($this->callTool($token, 'list_phone_calls', ['client_id' => $clientB->id]));
        $scopedCallIds = collect($scopedCalls['phone_calls'])->pluck('id')->all();
        $this->assertContains($callB->id, $scopedCallIds);
        $this->assertNotContains($callA->id, $scopedCallIds);
    }
}
