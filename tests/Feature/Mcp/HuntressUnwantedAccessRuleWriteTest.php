<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Huntress unwanted-access-rule writes: create, update, delete. Every HTTP
 * request (read and write lane) goes through a Guzzle MockHandler with a
 * history; an empty queue throws, so a stray request fails the test. Fixtures
 * follow the vendor's UnwantedAccessRule shape (applied_to {type, id, name}).
 */
class HuntressUnwantedAccessRuleWriteTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 42;

    // Names as strings, so this file loads and runs (red) on a base without the tools.
    private const CREATE = 'huntress_create_unwanted_access_rule';

    private const UPDATE = 'huntress_update_unwanted_access_rule';

    private const DELETE = 'huntress_delete_unwanted_access_rule';

    /** @var array<int, array<string, mixed>> */
    private array $reads = [];

    /** @var array<int, array<string, mixed>> */
    private array $writes = [];

    private MockHandler $readQueue;

    private MockHandler $writeQueue;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $this->readQueue = new MockHandler;
        $stack = HandlerStack::create($this->readQueue);
        $stack->push(Middleware::history($this->reads));
        $this->app->instance(HuntressClient::class, new HuntressClient([], new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack])));

        $this->writeQueue = new MockHandler;
        $stack = HandlerStack::create($this->writeQueue);
        $stack->push(Middleware::history($this->writes));
        $this->app->instance(HuntressWriteClient::class, new HuntressWriteClient(['api_key' => 'uk', 'api_secret' => 'us'], new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack])));
    }

    /** @return array<string, mixed> vendor UnwantedAccessRule */
    private function rule(int $id = 501, array $appliedTo = ['type' => 'Organization', 'id' => self::ORG, 'name' => 'Synthetic Org'], array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'applied_to' => $appliedTo,
            'category' => 'country',
            'country_code' => 'US',
            'vpn' => null,
            'ip_address' => null,
            'type' => 'expected',
            'status' => 'active',
            'logic' => 'standard',
            'note' => 'Synthetic travel note',
            'starts_at' => '2026-11-21T00:00:00Z',
            'expires_at' => '2026-12-01T00:00:00Z',
            'created_by' => 'Synthetic User',
            'created_at' => '2026-11-19T00:00:00Z',
            'updated_at' => '2026-11-19T00:00:00Z',
        ], $overrides);
    }

    private function queueRead(array $rule, int $status = 200): void
    {
        $this->readQueue->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($status === 200 ? ['unwanted_access_rule' => $rule] : ['error' => 'Not found'])));
    }

    private function queueWrite(int $status, array $body): void
    {
        $this->writeQueue->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(?int $org = self::ORG): array
    {
        $client = Client::factory()->create(['name' => 'Synthetic Client', 'huntress_organization_id' => $org]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Travel access']);

        return compact('client', 'ticket');
    }

    private function token(array $grants): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $grants, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? ['raw' => $response->json('result.content.0.text')];
    }

    private function createArgs(array $fx, array $overrides = []): array
    {
        return array_merge(['client_id' => $fx['client']->id, 'country_code' => 'US', 'type' => 'expected', 'starts_at' => '2026-11-21', 'expires_at' => '2026-12-01', 'notes' => 'Travel', 'reason' => 'User travels'], $overrides);
    }

    private function assertWrite(int $index, string $method, string $path, ?array $body): void
    {
        $request = $this->writes[$index]['request'];
        $this->assertSame($method, $request->getMethod());
        $this->assertSame($path, $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        if ($body === null) {
            $this->assertSame('', (string) $request->getBody());
        } else {
            $this->assertSame($body, json_decode((string) $request->getBody(), true));
            $this->assertSame(array_keys($body), array_keys(json_decode((string) $request->getBody(), true)));
        }
    }

    /** A response on each queue, so any stray request is RECORDED in the history, not lost to an empty-queue throw. */
    private function trapBoth(): void
    {
        $this->queueRead($this->rule());
        $this->queueWrite(201, ['unwanted_access_rule' => $this->rule()]);
    }

    private function approver(): User
    {
        return User::findOrFail((int) Setting::getValue('triage_system_user_id'));
    }

    // ── immediate grant: exact wire for each verb ──

    public function test_create_with_an_immediate_grant_sends_the_exact_post_and_returns_the_rule_read_back(): void
    {
        $fx = $this->fixture();
        $this->queueWrite(201, ['unwanted_access_rule' => $this->rule(501)]);
        $this->queueRead($this->rule(501));

        $result = $this->decoded($this->callTool($this->token([self::CREATE.':immediate']), self::CREATE, $this->createArgs($fx)));

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertCount(1, $this->writes);
        $this->assertWrite(0, 'POST', '/v1/unwanted_access_rules', [
            'organization_id' => self::ORG, 'country_code' => 'US', 'type' => 'expected',
            'starts_at' => '2026-11-21', 'expires_at' => '2026-12-01', 'notes' => 'Travel',
        ]);
        $this->assertCount(1, $this->reads);
        $this->assertSame('GET', $this->reads[0]['request']->getMethod());
        $this->assertSame('/v1/unwanted_access_rules/501', $this->reads[0]['request']->getUri()->getPath());
        $this->assertSame(501, $result['unwanted_access_rule']['id']);
        $this->assertSame(self::ORG, $result['unwanted_access_rule']['applied_to']['id']);
        $this->assertStringContainsString('UNTRUSTED HUNTRESS', $result['unwanted_access_rule']['note']);
        $this->assertSame(1, TechnicianActionLog::where('action_type', self::CREATE)->where('result_status', 'executed')->count());
    }

    public function test_update_reads_first_then_sends_the_exact_patch_and_returns_the_rule_read_back(): void
    {
        $fx = $this->fixture();
        $this->queueRead($this->rule(501));
        $this->queueWrite(200, ['unwanted_access_rule' => $this->rule(501, overrides: ['note' => 'Extended'])]);
        $this->queueRead($this->rule(501, overrides: ['note' => 'Extended']));

        $result = $this->decoded($this->callTool($this->token([self::UPDATE.':immediate']), self::UPDATE, [
            'client_id' => $fx['client']->id, 'rule_id' => 501, 'expires_at' => '2026-12-05', 'notes' => 'Extended', 'reason' => 'Trip extended',
        ]));

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertCount(1, $this->writes);
        $this->assertWrite(0, 'PATCH', '/v1/unwanted_access_rules/501', ['expires_at' => '2026-12-05', 'notes' => 'Extended']);
        $this->assertCount(2, $this->reads);
        foreach ($this->reads as $read) {
            $this->assertSame('/v1/unwanted_access_rules/501', $read['request']->getUri()->getPath());
        }
        $this->assertStringContainsString('Extended', $result['unwanted_access_rule']['note']);
    }

    public function test_delete_reads_first_then_sends_a_bodyless_delete_and_confirms_404(): void
    {
        $fx = $this->fixture();
        $this->queueRead($this->rule(501));
        $this->queueWrite(202, ['unwanted_access_rule' => $this->rule(501)]);
        $this->queueRead([], 404);

        $result = $this->decoded($this->callTool($this->token([self::DELETE.':immediate']), self::DELETE, [
            'client_id' => $fx['client']->id, 'rule_id' => 501, 'reason' => 'Back from travel',
        ]));

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertCount(1, $this->writes);
        $this->assertWrite(0, 'DELETE', '/v1/unwanted_access_rules/501', null);
        $this->assertCount(2, $this->reads);
        $this->assertSame(501, $result['deleted_rule']['id']);
        $this->assertStringContainsString('404', $result['read_back']);
    }

    // ── fence: nothing is sent ──

    public function test_an_unmapped_client_sends_nothing_for_any_verb(): void
    {
        $fx = $this->fixture(null);
        $this->trapBoth();
        foreach ([
            self::CREATE => $this->createArgs($fx),
            self::UPDATE => ['client_id' => $fx['client']->id, 'rule_id' => 501, 'notes' => 'x', 'reason' => 'r'],
            self::DELETE => ['client_id' => $fx['client']->id, 'rule_id' => 501, 'reason' => 'r'],
        ] as $tool => $args) {
            $result = $this->decoded($this->callTool($this->token([$tool.':immediate']), $tool, $args));
            $this->assertStringContainsString('no mapped Huntress organization', (string) ($result['error'] ?? ''), $tool);
        }
        $this->assertCount(0, $this->writes);
        $this->assertCount(0, $this->reads);
    }

    public function test_account_identity_foreign_and_unclassified_rules_send_no_write(): void
    {
        $fx = $this->fixture();
        Client::factory()->create(['huntress_organization_id' => 99]);
        $scopes = [
            'account' => ['type' => 'Account', 'id' => 7, 'name' => 'Synthetic Account'],
            'account-no-id' => ['type' => 'Account'],
            'identity' => ['type' => 'Identity', 'id' => 8, 'name' => 'synthetic.user'],
            'other mapped org' => ['type' => 'Organization', 'id' => 99, 'name' => 'Other'],
            'string id' => ['type' => 'Organization', 'id' => (string) self::ORG],
            'null' => null,
        ];
        foreach ([self::UPDATE => ['notes' => 'x'], self::DELETE => []] as $tool => $extra) {
            foreach ($scopes as $label => $appliedTo) {
                $this->queueRead(array_merge($this->rule(501), ['applied_to' => $appliedTo]));
                $this->queueWrite(200, ['unwanted_access_rule' => $this->rule(501)]);
                $before = count($this->reads);
                $result = $this->decoded($this->callTool($this->token([$tool.':immediate']), $tool, ['client_id' => $fx['client']->id, 'rule_id' => 501, 'reason' => 'r'] + $extra));
                $this->assertArrayHasKey('error', $result, "{$tool} {$label}");
                $this->assertStringContainsString('Nothing was sent', $result['error'], "{$tool} {$label}");
                $this->assertCount($before + 1, $this->reads, "{$tool} {$label}: the rule is read first");
                $this->assertCount(0, $this->writes, "{$tool} {$label}");
            }
        }
    }

    public function test_unknown_keys_are_refused_before_any_request(): void
    {
        $fx = $this->fixture();
        $this->trapBoth();
        $cases = [
            [self::CREATE, ['organization_id' => 99]],
            [self::CREATE, ['identity_id' => 8]],
            [self::CREATE, ['logic' => 'catchall', 'category' => 'country']],
            [self::CREATE, ['surprise' => true]],
            [self::UPDATE, ['rule_id' => 501, 'notes' => 'x', 'type' => 'unauthorized']],
            [self::UPDATE, ['rule_id' => 501, 'notes' => 'x', 'organization_id' => 42]],
            [self::DELETE, ['rule_id' => 501, 'notes' => 'x']],
        ];
        foreach ($cases as [$tool, $extra]) {
            $args = ($tool === self::CREATE ? $this->createArgs($fx) : ['client_id' => $fx['client']->id, 'reason' => 'r']) + $extra;
            $result = $this->decoded($this->callTool($this->token([$tool.':immediate']), $tool, $args));
            $this->assertStringContainsString('refused', (string) ($result['error'] ?? ''), $tool.' '.json_encode($extra));
        }
        $this->assertCount(0, $this->writes);
        $this->assertCount(0, $this->reads);
    }

    public function test_a_comma_separated_value_is_refused_before_any_request(): void
    {
        $fx = $this->fixture();
        $this->trapBoth();
        foreach ([['vpn' => 'SYNTH_VPN_A,SYNTH_VPN_B'], ['ip_address' => '192.0.2.1,198.51.100.1'], ['country_code' => 'US,CA']] as $value) {
            $args = array_merge(array_diff_key($this->createArgs($fx), ['country_code' => 1]), $value);
            $result = $this->decoded($this->callTool($this->token([self::CREATE.':immediate']), self::CREATE, $args));
            $this->assertStringContainsString('Nothing was sent', (string) ($result['error'] ?? ''), json_encode($value));
        }
        $this->assertCount(0, $this->writes);
        $this->assertCount(0, $this->reads);
    }

    public function test_the_write_client_refuses_an_unlisted_body_key_or_a_missing_org_without_http(): void
    {
        $client = app(HuntressWriteClient::class);
        foreach ([
            fn () => $client->createUnwantedAccessRule(['organization_id' => 42, 'type' => 'expected', 'identity_id' => 8]),
            fn () => $client->createUnwantedAccessRule(['type' => 'expected', 'country_code' => 'US']),
            fn () => $client->updateUnwantedAccessRule(501, ['type' => 'unauthorized']),
            fn () => $client->updateUnwantedAccessRule(501, []),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("case {$i} sent");
            } catch (\App\Services\Huntress\HuntressWriteScopeException) {
            }
        }
        $this->assertCount(0, $this->writes);
    }

    // ── staged grant ──

    public function test_a_staged_grant_stages_create_and_sends_nothing_until_approval_then_sends_the_exact_body(): void
    {
        $fx = $this->fixture();
        // staged=false on a staged-only grant is downgraded: still nothing sent.
        $response = $this->callTool($this->token([self::CREATE.':staged']), self::CREATE, $this->createArgs($fx, ['ticket_id' => $fx['ticket']->id, 'staged' => false]));
        $result = $this->decoded($response);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertCount(0, $this->writes);
        $this->assertCount(0, $this->reads);

        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame('huntress_stage_create_unwanted_access_rule', $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('POST /v1/unwanted_access_rules', $run->proposed_content);
        $this->assertStringContainsString('"organization_id": 42', $run->proposed_content);
        $this->assertStringContainsString('"country_code": "US"', $run->proposed_content);
        $this->assertStringContainsString('UNTRUSTED REQUEST BODY', $run->proposed_content);
        $this->assertStringContainsString('UNTRUSTED AGENT SUPPLIED REASON', $run->proposed_content);

        $this->queueWrite(201, ['unwanted_access_rule' => $this->rule(777)]);
        $this->queueRead($this->rule(777));
        $this->actingAs($this->approver())->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertCount(1, $this->writes);
        $this->assertWrite(0, 'POST', '/v1/unwanted_access_rules', [
            'organization_id' => self::ORG, 'country_code' => 'US', 'type' => 'expected',
            'starts_at' => '2026-11-21', 'expires_at' => '2026-12-01', 'notes' => 'Travel',
        ]);
        $this->assertCount(1, $this->reads, 'the rule is read back after the approved create');
        $this->assertSame('/v1/unwanted_access_rules/777', $this->reads[0]['request']->getUri()->getPath());
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->count());
    }

    public function test_staged_update_and_delete_replay_the_exact_request_after_re_reading_the_rule(): void
    {
        $fx = $this->fixture();
        foreach ([
            // null clears the expiry (vendor: "Pass an empty string or null to clear").
            [self::UPDATE, ['notes' => 'Extended', 'expires_at' => null], 'PATCH', ['expires_at' => null, 'notes' => 'Extended'], 200],
            [self::DELETE, [], 'DELETE', null, 202],
        ] as [$tool, $extra, $method, $body, $status]) {
            $this->reads = [];
            $this->writes = [];
            $this->queueRead($this->rule(501));
            $result = $this->decoded($this->callTool($this->token([$tool.':staged']), $tool, ['client_id' => $fx['client']->id, 'rule_id' => 501, 'ticket_id' => $fx['ticket']->id, 'reason' => 'r', 'staged' => true] + $extra));
            $this->assertTrue($result['success'] ?? false, $tool.' '.json_encode($result));
            $this->assertCount(0, $this->writes, $tool);
            $run = TechnicianRun::findOrFail($result['run_id']);
            $this->assertStringContainsString($method.' /v1/unwanted_access_rules/501', $run->proposed_content);

            $this->queueRead($this->rule(501));
            $this->queueWrite($status, ['unwanted_access_rule' => $this->rule(501)]);
            $method === 'DELETE' ? $this->queueRead([], 404) : $this->queueRead($this->rule(501));
            $this->actingAs($this->approver())->post(route('cockpit.approve', $run));

            $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, $tool);
            $this->assertCount(1, $this->writes, $tool);
            $this->assertWrite(0, $method, '/v1/unwanted_access_rules/501', $body);
            $this->assertCount(3, $this->reads, $tool.': staged read, approval re-read, read-back');
        }
    }

    public function test_approval_refuses_after_a_remap_or_a_rule_moved_out_of_scope_and_sends_nothing(): void
    {
        $fx = $this->fixture();
        $stage = function (string $tool, array $extra) use ($fx): TechnicianRun {
            if ($tool !== self::CREATE) {
                $this->queueRead($this->rule(501));
            }
            $args = $tool === self::CREATE ? $this->createArgs($fx) : ['client_id' => $fx['client']->id, 'rule_id' => 501, 'reason' => 'r'];
            $result = $this->decoded($this->callTool($this->token([$tool.':staged']), $tool, $args + ['ticket_id' => $fx['ticket']->id, 'staged' => true] + $extra));

            return TechnicianRun::findOrFail($result['run_id']);
        };

        // Remap while held: the create's stored org is no longer the mapping.
        $run = $stage(self::CREATE, []);
        $fx['client']->update(['huntress_organization_id' => 43]);
        $this->trapBoth();
        $this->actingAs($this->approver())->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(0, $this->writes);
        $fx['client']->update(['huntress_organization_id' => self::ORG]);

        // The rule now applies to the account: the approval re-read refuses.
        $this->readQueue->reset();
        $this->writeQueue->reset();
        $run = $stage(self::DELETE, []);
        $this->queueRead($this->rule(501, ['type' => 'Account', 'id' => 7]));
        $this->queueWrite(202, ['unwanted_access_rule' => $this->rule(501)]);
        $this->actingAs($this->approver())->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(0, $this->writes);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')->count());
    }

    public function test_the_staged_twins_are_registered_aliases_with_an_approve_route(): void
    {
        foreach ([self::CREATE, self::UPDATE, self::DELETE] as $tool) {
            $staged = str_replace('huntress_', 'huntress_stage_', $tool);
            $this->assertSame($tool, McpToolModes::canonicalForAlias($staged));
            $this->assertFalse(McpToolModes::isHeldOnly($tool));
            [$name, $mode] = McpToolModes::parseGrantEntry($tool.':immediate');
            $this->assertSame([$tool, McpToolModes::MODE_IMMEDIATE], [$name, $mode]);
        }
    }

    public function test_an_identical_proposal_after_an_approved_run_is_staged_again_not_answered_as_already_executed(): void
    {
        $fx = $this->fixture();
        $token = $this->token([self::CREATE.':staged']);
        $args = $this->createArgs($fx, ['ticket_id' => $fx['ticket']->id]);

        $first = $this->decoded($this->callTool($token, self::CREATE, $args));
        $run = TechnicianRun::findOrFail($first['run_id']);
        // Unknown outcome on approval: the run closes Done, but the rule may not exist.
        $this->writeQueue->append(new Response(502, [], '{}'));
        $this->actingAs($this->approver())->post(route('cockpit.approve', $run));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertCount(1, $this->writes);

        $again = $this->decoded($this->callTool($token, self::CREATE, $args));

        $this->assertTrue($again['success'] ?? false, json_encode($again));
        $this->assertArrayNotHasKey('idempotent', $again);
        $this->assertNotSame($run->id, $again['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::findOrFail($again['run_id'])->state);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'the earlier run is not rewritten');
        $this->assertCount(1, $this->writes, 'staging again sends nothing');

        $repeat = $this->decoded($this->callTool($token, self::CREATE, $args));
        $this->assertSame($again['run_id'], $repeat['run_id'], 'a pending identical proposal is answered idempotently');
        $this->assertTrue($repeat['idempotent'] ?? false);
    }

    public function test_a_failing_audit_insert_after_a_landed_immediate_write_still_reports_success(): void
    {
        $fx = $this->fixture();
        $this->queueWrite(201, ['unwanted_access_rule' => $this->rule(501)]);
        $this->queueRead($this->rule(501));
        TechnicianActionLog::creating(static function (TechnicianActionLog $row): void {
            if ($row->result_status === 'executed') {
                throw new \RuntimeException('synthetic audit insert failure');
            }
        });

        $result = $this->decoded($this->callTool($this->token([self::CREATE.':immediate']), self::CREATE, $this->createArgs($fx)));

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(501, $result['unwanted_access_rule']['id']);
        $this->assertCount(1, $this->writes);
    }

    public function test_a_vendor_refusal_reports_status_and_class_only(): void
    {
        $fx = $this->fixture();
        $this->writeQueue->append(new Response(422, [], json_encode(['error' => 'Synthetic vendor detail https://api.huntress.io/secret'])));
        $result = $this->decoded($this->callTool($this->token([self::CREATE.':immediate']), self::CREATE, $this->createArgs($fx)));

        $this->assertStringContainsString('Huntress HTTP 422', $result['error']);
        $this->assertStringContainsString('Nothing was changed', $result['error']);
        $this->assertStringNotContainsString('Synthetic vendor detail', $result['error']);
        $this->assertStringNotContainsString('https://', $result['error']);
        $this->assertCount(0, $this->reads);
    }
}
