<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use App\Support\StagedActionLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #1135 — mesh_edit_allow_rule.
 *
 * ONE field, and the ruling that shaped every test here (Jeeves, 2026-09-02):
 * `expires_at`, PSA-tracked rules only.
 *
 *   1. THE AUTHORITATIVE CHANGE IS LOCAL. Mesh's `date_expiry` is display-only
 *      — measured, and the whole reason MeshAllowRuleReaper exists. The PSA
 *      row is what is enforced, so it is written FIRST and is never undone by
 *      an upstream failure.
 *   2. FOREIGN RULES ARE REFUSED, unlike the removal verb. A rule the PSA did
 *      not create has no enforced expiry to edit, so patching one upstream
 *      would be a no-op dressed as a change. Adopting such rules into the
 *      reaper's queue is a product decision, not an edit path.
 *   3. `comment` IS NOT EDITABLE and is not merely absent from the schema:
 *      it is the reaper's fallback IDENTITY for a rule (resolveRuleId() ->
 *      findRuleByComment()). Editing it would strand a live allow rule outside
 *      the only queue able to close it. MeshWriteClient refuses the field too.
 *   4. THE POST-CONDITION IS A DISPLAY CHECK, NOT THE RESULT. If Mesh does not
 *      show the new date — or no longer answers under the same id — the
 *      upstream side is NOT retried; the card says in words that Mesh will
 *      keep showing the old date while the PSA enforces the new one.
 */
class MeshEditAllowRuleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private function configureMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', 'k');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
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

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(?string $tenant = self::TENANT): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => $tenant]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor allow needs longer']);

        return compact('client', 'ticket');
    }

    /** The new expiry every test edits TO, unless it says otherwise. */
    private function newExpiry(): \Illuminate\Support\Carbon
    {
        return now()->addDays(60)->startOfMinute();
    }

    /** @return array<string, mixed> */
    private function editArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'rule_id' => 'rule-xyz',
            'confirm_sender' => 'billing@vendor.example',
            'expires_at' => $this->newExpiry()->toIso8601String(),
            'reason' => 'The vendor migration slipped a month; the allow has to outlive the original 30 days.',
        ], $overrides);
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    /** @return array<string, mixed> */
    private function upstreamRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rule-xyz',
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'ab' => MeshWriteClient::ALLOW_RULE,
            'created_by' => 'owner@soundit.example',
            'date_expiry' => '2026-12-01',
        ], $overrides);
    }

    private function tracked(array $fixture, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-xyz',
            'expires_at' => now()->addDays(30)->startOfMinute(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ], $overrides));
    }

    private function stagedRun(array $fixture, array $overrides = []): TechnicianRun
    {
        $this->callTool($this->token(['mesh_edit_allow_rule:staged']), 'mesh_edit_allow_rule', $this->editArgs($fixture, $overrides))->assertOk();
        $run = TechnicianRun::where('action_type', 'mesh_stage_edit_allow_rule')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    // ---- surface / grant shape ------------------------------------------------

    public function test_the_verb_is_a_sensitive_grantable_mesh_tool_and_never_defaults_immediate(): void
    {
        $this->configureMesh();

        $groups = McpToolRegistry::groups();
        $this->assertContains('mesh_edit_allow_rule', array_column($groups['mesh_admin']['tools'], 'name'));
        $this->assertContains('mesh_edit_allow_rule', McpToolRegistry::allToolNames());
        $this->assertSame('mesh', McpToolRegistry::integrationForToolName('mesh_edit_allow_rule'));

        $this->assertNotContains(
            'mesh_edit_allow_rule',
            array_column($this->listTools($this->legacyToken()), 'name'),
            'a legacy full-surface token must not gain the edit verb',
        );

        $scoped = collect($this->listTools($this->token(['mesh_edit_allow_rule:staged'])))->keyBy('name');
        $this->assertArrayHasKey('mesh_edit_allow_rule', $scoped);
        $definition = $scoped['mesh_edit_allow_rule'];
        foreach (['rule_id', 'confirm_sender', 'expires_at', 'reason', 'ticket_id'] as $required) {
            $this->assertContains($required, $definition['inputSchema']['required'], "{$required} must be required on the edit verb");
        }
        $this->assertStringContainsString('STAGED ONLY', $definition['description']);
        $this->assertStringContainsString('ONE FIELD', $definition['description']);
        $this->assertStringContainsString('PSA-TRACKED RULES ONLY', $definition['description']);
        $this->assertStringContainsString('FOREIGN', $definition['description']);

        $this->assertNotContains('mesh_stage_edit_allow_rule', $scoped->keys()->all());
        $this->assertSame(['mesh_edit_allow_rule', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('mesh_edit_allow_rule:staged'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('mesh_edit_allow_rule'));
        $this->assertTrue(StagedActionLabels::isVendorSideEffectAction('mesh_stage_edit_allow_rule'));
        $this->assertTrue(StagedActionLabels::hasCuratedLabel('mesh_stage_edit_allow_rule'), 'the cockpit must not show an approver the raw action_type');
        $this->assertSame('Mesh allow rule expiry edit', StagedActionLabels::humanLabel('mesh_stage_edit_allow_rule'));
    }

    public function test_a_bare_immediate_grant_is_refused_and_names_the_staged_grant(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('patchRule');
        $write->shouldNotReceive('findRuleById');

        $result = $this->decodedResult(
            $this->callTool($this->token(['mesh_edit_allow_rule:immediate']), 'mesh_edit_allow_rule', $this->editArgs($fixture))
        );

        $this->assertStringContainsString('staged-only', $result['error']);
        $this->assertStringContainsString('mesh_edit_allow_rule:staged', $result['error']);
        $this->assertStringContainsString('No upstream call was made', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_the_verb_is_not_published_while_mesh_is_unconfigured(): void
    {
        $this->assertNotContains(
            'mesh_edit_allow_rule',
            array_column($this->listTools($this->token(['mesh_edit_allow_rule:staged'])), 'name'),
        );
    }

    // ---- argument surface -----------------------------------------------------

    /**
     * The allow-list is PER VERB, and the one that matters most here is
     * `comment`: it is the reaper's identity for the rule, not a label, so it
     * is refused by name with that reason rather than silently dropped.
     */
    public function test_every_field_but_the_expiry_is_refused_by_name(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        $cases = [
            'comment' => 'PSA allow ZZZZZZZZZZ',
            'sender' => 'someone-else@vendor.example',
            'date_expiry' => '2027-01-01',
            'ab' => true,
            'active' => false,
            'rule_ids' => ['a', 'b'],
            'all' => true,
            'force' => true,
            'edge' => true,
            'customers' => ['t'],
            'customer_id' => 'other-tenant',
            'organization_level' => true,
            'domains' => ['vendor.example'],
            'users' => ['someone'],
            'partner_id' => 'p',
            'global' => true,
        ];

        foreach ($cases as $key => $value) {
            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_edit_allow_rule:staged']),
                'mesh_edit_allow_rule',
                $this->editArgs($fixture, [$key => $value]),
            ));

            $this->assertStringContainsString('not accepted by mesh_edit_allow_rule', $result['error'] ?? '', "'{$key}' must be refused by name");
            $this->assertStringContainsString($key, $result['error']);
        }

        $this->assertStringContainsString(
            'the reaper',
            $this->decodedResult($this->callTool(
                $this->token(['mesh_edit_allow_rule:staged']),
                'mesh_edit_allow_rule',
                $this->editArgs($fixture, ['comment' => 'anything']),
            ))['error'],
            'the comment refusal must say WHY — it is the identity the expiry job matches on',
        );

        $this->assertSame(0, TechnicianRun::count());
    }

    /**
     * The create verb defaults an ABSENT expires_at to 90 days, which is right
     * for a rule being born and wrong for one being edited: defaulting here
     * would quietly rewrite a lifetime somebody chose. There is nothing else
     * this verb changes, so an edit with no expiry is a mistake.
     */
    public function test_an_absent_expires_at_is_refused_rather_than_defaulted(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');
        $write->shouldNotReceive('patchRule');

        $args = $this->editArgs($fixture);
        unset($args['expires_at']);

        $result = $this->decodedResult($this->callTool($this->token(['mesh_edit_allow_rule:staged']), 'mesh_edit_allow_rule', $args));

        $this->assertStringContainsString('expires_at is required', $result['error']);
        $this->assertStringContainsString('only thing this verb changes', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertTrue($record->fresh()->expires_at->equalTo($record->expires_at));
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_edit_allow_rule')
            ->where('result_status', 'rejected')
            ->count());
    }

    /** @dataProvider badExpiries */
    public function test_an_expiry_that_cannot_be_honoured_is_refused_at_staging(mixed $value, string $expected): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['expires_at' => $value]),
        ));

        $this->assertStringContainsString($expected, $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public static function badExpiries(): array
    {
        return [
            'unreadable' => ['whenever', "expires_at ('whenever') is not a date this system can read"],
            'past' => ['2020-01-01', 'which is in the past'],
            'empty' => ['   ', 'expires_at was empty'],
            'explicit null' => [null, 'expires_at was empty'],
            'non-string' => [90, 'expires_at must be an ISO-8601 date or datetime'],
            'out of range' => ['+100000 years', 'further away than this system can record'],
        ];
    }

    public function test_reason_and_ticket_are_required(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        $args = $this->editArgs($fixture);
        unset($args['reason']);
        $this->assertStringContainsString('reason is required', $this->decodedResult(
            $this->callTool($this->token(['mesh_edit_allow_rule:staged']), 'mesh_edit_allow_rule', $args)
        )['error']);

        $args = $this->editArgs($fixture);
        unset($args['ticket_id']);
        $this->assertArrayHasKey('error', $this->decodedResult(
            $this->callTool($this->token(['mesh_edit_allow_rule:staged']), 'mesh_edit_allow_rule', $args)
        ));

        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- scope ----------------------------------------------------------------

    public function test_a_rule_id_outside_this_clients_tenant_is_absent_not_editable(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->with(self::TENANT, 'rule-of-another-customer')->andReturn(null);
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['rule_id' => 'rule-of-another-customer']),
        ));

        $this->assertStringContainsString("No allow rule with id 'rule-of-another-customer' belongs to Acme's Mesh tenant", $result['error']);
        $this->assertStringContainsString('Nothing was changed', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_edit_allow_rule')
            ->where('result_status', 'rejected')
            ->count());
    }

    public function test_a_client_with_no_mesh_mapping_is_refused_before_any_read(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture(tenant: null);
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertStringContainsString('no Mesh customer mapping', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /** An unreadable list is not an empty list. Fail closed, in both directions. */
    public function test_an_unreadable_rule_list_refuses_rather_than_assuming_absence(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andThrow(new MeshClientException('read timed out'));
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertStringContainsString('scope could not be checked and nothing was changed', $result['error']);
        $this->assertStringContainsString('read timed out', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_an_absurdly_long_rule_id_is_refused_before_it_reaches_a_url(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['rule_id' => str_repeat('a', 256)]),
        ));

        $this->assertStringContainsString('not a Mesh rule id', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- allow-only, PSA-tracked-only, and the typed confirmation -------------

    public function test_a_block_rule_is_never_edited(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['ab' => false]));
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertStringContainsString('is a BLOCK rule, not an allow rule', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /** Unable-to-assess is a refusal, never a pass. */
    public function test_a_rule_whose_type_mesh_does_not_state_is_refused_too(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);

        foreach ([['ab' => null], ['ab' => 'true'], ['ab' => 1]] as $shape) {
            $write = $this->mockWrite();
            $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow($shape));
            $write->shouldNotReceive('patchRule');

            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_edit_allow_rule:staged']),
                'mesh_edit_allow_rule',
                $this->editArgs($fixture),
            ));

            $this->assertStringContainsString('did not state whether', $result['error'], 'ab='.var_export($shape['ab'], true).' must not be assumed to be an allow rule');
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_confirm_sender_must_match_the_rules_actual_sender(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['confirm_sender' => 'someone-else@vendor.example']),
        ));

        $this->assertStringContainsString('confirm_sender must exactly match the sender', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_confirm_sender_matches_case_insensitively(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture, ['sender' => 'Billing@Vendor.Example']);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['sender' => 'Billing@Vendor.Example']));

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['confirm_sender' => '  BILLING@vendor.example  ']),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('billing@vendor.example', $result['sender']);
    }

    /**
     * THE ruling, and the one place this verb differs hardest from the removal
     * verb: a FOREIGN rule is removable but NOT editable. Only the PSA's own
     * expiry is enforced — Mesh displays one and does not act on it — so a rule
     * with no PSA record has nothing to enforce a new date. Patching it upstream
     * would look like a change and be a no-op.
     */
    public function test_a_foreign_rule_is_refused_with_the_reason_on_the_card(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['comment' => 'phish sim server']));
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertStringContainsString('is FOREIGN', $result['error']);
        $this->assertStringContainsString('the PSA did not create it and holds no record of it for Acme', $result['error']);
        $this->assertStringContainsString('Mesh displays an expiry but does not act on one', $result['error']);
        $this->assertStringContainsString('product decision', $result['error']);
        $this->assertStringContainsString('Nothing was changed', $result['error']);

        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(0, MeshAllowRule::count(), 'a refused edit must never manufacture a tracking row for a foreign rule');
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_edit_allow_rule')
            ->where('result_status', 'rejected')
            ->count());
    }

    /**
     * A record belonging to ANOTHER client does not make the rule tracked for
     * this one — the lookup is scoped by client, so this stays foreign.
     */
    public function test_a_record_held_for_a_different_client_does_not_make_the_rule_editable_here(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $other = $this->fixture('99999999-8888-7777-6666-555555555555');
        $this->tracked($other, [
            'client_id' => $other['client']->id,
            'ticket_id' => $other['ticket']->id,
            'mesh_customer_id' => '99999999-8888-7777-6666-555555555555',
        ]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertStringContainsString('is FOREIGN', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /**
     * Reached from the other side: the record exists but is CLOSED, so it is
     * outside scopeReapable() and a new date written onto it would never be
     * enforced either.
     */
    public function test_a_closed_psa_record_is_refused_because_its_expiry_is_no_longer_enforced(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();

        foreach ([MeshAllowRule::STATE_REMOVED, MeshAllowRule::STATE_REAPED] as $state) {
            MeshAllowRule::query()->delete();
            $record = $this->tracked($fixture, ['state' => $state]);
            $write = $this->mockWrite();
            $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
            $write->shouldNotReceive('patchRule');

            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_edit_allow_rule:staged']),
                'mesh_edit_allow_rule',
                $this->editArgs($fixture),
            ));

            $this->assertStringContainsString("is already closed ({$state})", $result['error']);
            $this->assertStringContainsString('no longer enforced and cannot be edited', $result['error']);
            $this->assertTrue($record->fresh()->expires_at->equalTo($record->expires_at));
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    /**
     * A card whose approval would do nothing is a card that teaches approvers
     * not to read cards.
     */
    public function test_an_edit_to_the_expiry_the_psa_already_enforces_stages_nothing(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $when = now()->addDays(45)->startOfMinute();
        $this->tracked($fixture, ['expires_at' => $when]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $write->shouldNotReceive('patchRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['expires_at' => $when->toIso8601String()]),
        ));

        $this->assertStringContainsString('there is nothing to change and no proposal was staged', $result['error']);
        $this->assertStringContainsString('already expires', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_asking_a_permanent_rule_to_be_permanent_again_stages_nothing(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture, ['expires_at' => null]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['expires_at' => 'never']),
        ));

        $this->assertStringContainsString('already PERMANENT — never reaped by the PSA', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- the card -------------------------------------------------------------

    public function test_the_card_names_the_old_expiry_the_new_one_and_who_enforces_it(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);

        $this->assertStringContainsString("PSA-TRACKED (record #{$record->id}, state active): currently expires ", $run->proposed_content);
        $this->assertStringContainsString('New expiry: expires '.$this->newExpiry()->toDayDateTimeString().' UTC', $run->proposed_content);
        $this->assertStringContainsString('The PSA enforces this: its expiry job removes the rule once that date has passed.', $run->proposed_content);
        $this->assertStringContainsString("allows the single address 'billing@vendor.example'", $run->proposed_content);
        $this->assertStringContainsString('The scope is not changed by this edit.', $run->proposed_content);

        // The honesty clause the ruling asked for, in words, on the card.
        $this->assertStringContainsString("Mesh's own Expires column is display only", $run->proposed_content);
        $this->assertStringContainsString('Mesh will keep showing the old date while the PSA enforces the new one', $run->proposed_content);

        // And the field that is NOT edited is named as such, with the reason.
        $this->assertStringContainsString('unchanged — it is how the PSA identifies the rule', $run->proposed_content);
        $this->assertStringContainsString('PSA allow ABCDEFGHIJ', $run->proposed_content);

        $params = $run->proposed_meta['redacted_params'];
        $this->assertSame('rule-xyz', $params['rule_id']);
        $this->assertSame($record->id, $params['psa_record_id']);
        $this->assertSame($record->expires_at->toIso8601String(), $params['current_expires_at']);
        $this->assertSame($this->newExpiry()->toIso8601String(), $params['expires_at']);
    }

    public function test_a_card_that_makes_a_rule_permanent_says_the_hole_stays_open(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture, ['expires_at' => 'never']);

        $this->assertStringContainsString('New expiry: PERMANENT — never reaped by the PSA', $run->proposed_content);
        $this->assertStringContainsString('nothing in the PSA will ever remove it', $run->proposed_content);
        $this->assertStringContainsString('until a human closes it in the Mesh portal', $run->proposed_content);
        $this->assertSame('never', $run->proposed_meta['redacted_params']['expires_at']);
    }

    public function test_a_domain_wide_rule_says_so_in_words(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture, ['sender' => 'vendor.example']);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['sender' => 'vendor.example']));

        $run = $this->stagedRun($fixture, ['confirm_sender' => 'vendor.example']);

        $this->assertStringContainsString("allows EVERY sender at 'vendor.example'", $run->proposed_content);
    }

    /**
     * The tenant, the sender, the allow/block flag and the PSA record are all
     * re-resolved at approval; the payload carries the id, the confirmation and
     * the requested lifetime — which is re-validated there too, so a date that
     * passes while the card waits is refused rather than applied.
     */
    public function test_the_staged_payload_carries_the_id_the_confirmation_and_the_requested_expiry(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);

        $this->assertSame('mesh_edit_allow_rule', $payload['direct_tool']);
        $this->assertSame(['rule_id', 'confirm_sender', 'expires_at', 'reason'], array_keys($payload['arguments']));
        $this->assertSame('rule-xyz', $payload['arguments']['rule_id']);
        $this->assertSame($this->newExpiry()->toIso8601String(), $payload['arguments']['expires_at']);
    }

    // ---- approval → execution -------------------------------------------------

    public function test_approval_writes_the_psa_expiry_first_then_asks_mesh_to_display_it(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $previous = $record->expires_at;
        $new = $this->newExpiry();

        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        // ONE field goes upstream, and it is the display field.
        $write->shouldReceive('patchRule')->once()->with('rule-xyz', ['date_expiry' => $new->toIso8601String()])->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->with(self::TENANT, 'rule-xyz')->andReturn($this->upstreamRow(['date_expiry' => $new->toDateString()]));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $record->refresh();
        $this->assertTrue($record->expires_at->equalTo($new));
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertSame('rule-xyz', $record->mesh_rule_id);
        $this->assertSame(0, MeshAllowRule::query()->reapable()->count(), 'the new lifetime is in the future, so nothing is due');

        $log = TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->sole();
        $this->assertStringContainsString('expires '.$previous->toDayDateTimeString().' UTC -> expires '.$new->toDayDateTimeString().' UTC', $log->summary);
        $this->assertStringContainsString('Mesh re-read under the same id and displays it', $log->summary);
    }

    /** "never" is a value, and it goes upstream EXPLICITLY as null. */
    public function test_making_a_rule_permanent_sends_an_explicit_null_and_takes_it_out_of_the_reapers_queue(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture, ['expires_at' => now()->subDay()]);

        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $this->assertSame(1, MeshAllowRule::query()->reapable()->count(), 'the row starts DUE, so the edit is the only thing that can save it');
        $run = $this->stagedRun($fixture, ['expires_at' => 'never']);

        $write->shouldReceive('patchRule')->once()->with('rule-xyz', ['date_expiry' => null])->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['date_expiry' => null]));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $record->refresh();
        $this->assertNull($record->expires_at);
        $this->assertSame(0, MeshAllowRule::query()->reapable()->count());
        $this->assertStringContainsString('-> PERMANENT — never reaped by the PSA', TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->sole()->summary);
    }

    /**
     * A row that reads back with a date when permanence was asked for is NOT
     * agreement — the display check is a match, not a "did the call return".
     */
    public function test_a_permanent_edit_whose_display_still_shows_a_date_is_a_fault(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture, ['expires_at' => 'never']);

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['date_expiry' => '2026-12-01']));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('Mesh did not take the display update', (string) session('error'));
        $this->assertStringContainsString('it still shows an expiry of', (string) session('error'));

        $this->assertNull($record->fresh()->expires_at, 'the authoritative change stands; only the display failed');
    }

    /**
     * The PATCH threw, but the re-read shows the new date: Mesh commits before
     * it answers, and the post-condition — not the exception — decides.
     */
    public function test_a_patch_that_threw_over_a_display_that_took_is_a_success_that_says_so(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $new = $this->newExpiry();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('patchRule')->once()->andThrow(new MeshClientException('connection reset'));
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['date_expiry' => $new->toDateString()]));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $this->assertTrue($record->fresh()->expires_at->equalTo($new));
        $summary = TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringContainsString('connection reset', $summary);
        $this->assertStringContainsString('but the re-read shows the new date', $summary);
    }

    /**
     * Item 6 of the build shape, as ruled: if the id is not preserved, DO NOT
     * patch again and DO NOT remove-and-re-add a live allow rule. Say it on the
     * card — and clear the recorded id, so the reaper falls back to the comment
     * lookup rather than DELETEing a stale id, reading the 404 as absence, and
     * retiring a rule that may still be live under another id.
     */
    public function test_an_id_that_no_longer_resolves_after_the_patch_is_a_fault_that_clears_the_recorded_id(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $new = $this->newExpiry();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn(null);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString("Mesh no longer returns a rule under id 'rule-xyz'", $error);
        $this->assertStringContainsString('the upstream side was NOT retried', $error);
        $this->assertStringContainsString('Mesh may keep showing the old expiry while the PSA enforces the new one', $error);
        $this->assertStringContainsString('re-identifies the rule by sender and comment', $error);

        $record->refresh();
        $this->assertTrue($record->expires_at->equalTo($new), 'the enforced expiry is the change and it stands');
        $this->assertNull($record->mesh_rule_id, 'a stale id would 404 on the reaper\'s DELETE and be read as absence');
        $this->assertSame('PSA allow ABCDEFGHIJ', $record->comment, 'the comment is the fallback identity and is never touched');

        // Terminal, and never a second PATCH.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed_with_fault')->count());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->count());
    }

    /** Unmeasurable is not a pass, and it is not the same answer as id-lost. */
    public function test_a_confirming_read_that_does_not_answer_is_reported_as_unmeasured(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andThrow(new MeshClientException('read timed out'));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString('could NOT be measured', $error);
        $this->assertStringContainsString('read timed out', $error);
        $this->assertStringContainsString('the upstream side was NOT retried', $error);

        $record->refresh();
        $this->assertTrue($record->expires_at->equalTo($this->newExpiry()));
        $this->assertSame('rule-xyz', $record->mesh_rule_id, 'an unanswered read is not evidence the id is gone');
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed_with_fault')->count());
    }

    public function test_a_display_that_did_not_move_is_a_fault_and_the_upstream_side_is_not_retried(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        // Exactly one PATCH, whatever the re-read says.
        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['date_expiry' => '2026-12-01']));

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $error = (string) session('error');
        $this->assertStringContainsString('Mesh did not take the display update', $error);
        $this->assertStringContainsString('Mesh will keep showing the old date while the PSA enforces the new one', $error);

        $record->refresh();
        $this->assertTrue($record->expires_at->equalTo($this->newExpiry()));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    // ---- the re-checks at approval --------------------------------------------

    /**
     * A card can wait days. The date it carries is re-validated against NOW,
     * because an expiry that has passed in the meantime would make the rule
     * reapable on the spot — and the approver did not consent to a removal.
     */
    public function test_an_expiry_that_passed_while_the_card_waited_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture, ['expires_at' => now()->addDay()->toIso8601String()]);

        $write->shouldNotReceive('patchRule');
        $this->travel(2)->days();

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('which is in the past', (string) session('error'));
        $this->assertStringContainsString('No upstream call was made and nothing was changed', (string) session('error'));

        $this->assertTrue($record->fresh()->expires_at->equalTo($record->expires_at));
        $this->assertNotSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_a_rule_that_no_longer_belongs_to_this_client_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $write->shouldNotReceive('patchRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('re-checked at approval; nothing was changed', (string) session('error'));
        $this->assertTrue($record->fresh()->expires_at->equalTo($record->expires_at));
        $this->assertNotSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_a_rule_that_became_a_block_rule_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['ab' => false]));
        $write->shouldNotReceive('patchRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('is a BLOCK rule', (string) session('error'));
    }

    /**
     * The PSA record is re-resolved at approval too — a removal approved on
     * another card in the meantime closes the row, and a new expiry written
     * onto a closed row would never be enforced.
     */
    public function test_a_record_closed_while_the_card_waited_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $record->forceFill(['state' => MeshAllowRule::STATE_REMOVED, 'removed_at' => now()])->save();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $write->shouldNotReceive('patchRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('is already closed (removed)', (string) session('error'));
        $this->assertStringContainsString('re-checked at approval; nothing was changed', (string) session('error'));
    }

    /**
     * And the record can DISAPPEAR — a client re-point, a hand-deleted row —
     * which makes the rule foreign by the time the button is pressed.
     */
    public function test_a_record_that_vanished_while_the_card_waited_is_refused_as_foreign(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $record->delete();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $write->shouldNotReceive('patchRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('is FOREIGN', (string) session('error'));
        $this->assertStringContainsString('re-checked at approval; nothing was changed', (string) session('error'));
    }

    // ---- idempotence ----------------------------------------------------------

    /**
     * A repeat is genuinely idempotent here: the requested end state (that rule
     * carrying that lifetime) is the state that holds. Unlike the removal verb
     * the rule still exists, so the scope and sender checks run normally first.
     */
    public function test_a_second_edit_to_the_same_expiry_reports_idempotent_success_and_stages_nothing(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        // Mesh agrees with whatever the PSA record enforces: these tests are
        // about the duplicate brakes, not the display post-condition.
        $write->shouldReceive('findRuleById')->andReturnUsing(fn () => $this->upstreamRow([
            'date_expiry' => $record->fresh()?->expires_at?->toDateString(),
        ]));
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        // Exactly one PATCH for this lifetime, however many times it is asked for.
        $write->shouldNotReceive('patchRule');

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertTrue($second['success']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($run->id, $second['run_id']);
        $this->assertStringContainsString('was already set to', $second['message']);
        $this->assertSame(1, TechnicianRun::count(), 'no second proposal may be staged');
    }

    /**
     * A DIFFERENT date is a different write, not a duplicate — the requested
     * lifetime is part of the content hash, so the brake that answers a repeat
     * must not swallow a genuine change of mind.
     */
    public function test_a_different_date_is_a_different_proposal(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());

        $first = $this->stagedRun($fixture);

        // Past the staging cooldown, so this is a second proposal rather than
        // the live one handed back.
        $this->travel(6)->minutes();
        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['expires_at' => now()->addDays(90)->startOfMinute()->toIso8601String()]),
        ));

        $this->assertTrue($second['success']);
        $this->assertArrayNotHasKey('idempotent', $second);
        $this->assertNotSame($first->id, $second['run_id']);
        $this->assertSame(2, TechnicianRun::count());
    }

    public function test_staging_the_same_edit_twice_returns_the_live_proposal(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);
        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertTrue($second['idempotent']);
        $this->assertSame($run->id, $second['run_id']);
        $this->assertSame(1, TechnicianRun::count());
    }

    /**
     * Two cards for one edit can exist — the run slot is per ticket — and the
     * second is approved after the first has already applied the lifetime. It
     * must close as an idempotent no-op with no second PATCH: the row already
     * carries the date, so there is nothing to enforce differently.
     */
    public function test_a_duplicate_card_approved_after_the_edit_landed_closes_as_idempotent(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same edit, raised twice']);
        // ONE instant, held across the travel below: newExpiry() is relative to
        // now(), so re-deriving it for the second card would ask for a lifetime
        // minutes apart from the first and stop being a duplicate at all.
        $expiry = $this->newExpiry();
        $write = $this->mockWrite();
        // Mesh agrees with whatever the PSA record enforces: these tests are
        // about the duplicate brakes, not the display post-condition.
        $write->shouldReceive('findRuleById')->andReturnUsing(fn () => $this->upstreamRow([
            'date_expiry' => $record->fresh()?->expires_at?->toDateString(),
        ]));

        $first = $this->stagedRun($fixture, ['expires_at' => $expiry->toIso8601String()]);

        $this->travel(6)->minutes();
        $this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['ticket_id' => $secondTicket->id, 'expires_at' => $expiry->toIso8601String()]),
        )->assertOk();
        $duplicate = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('success');

        // Past the approve-time cooldown, so the refusal below is the duplicate
        // brake and not the cooldown answering for it.
        $write->shouldNotReceive('patchRule');
        $this->travel(6)->minutes();

        $this->actingAs($actor)->post(route('cockpit.approve', $duplicate))->assertSessionHas('error');
        $this->assertStringContainsString('already set to this expiry for this client recently', (string) session('error'));

        $this->assertSame(TechnicianRunState::Done, $duplicate->fresh()->state, 'a spent card must be terminal, not stranded AwaitingApproval');
        $this->assertTrue($record->fresh()->expires_at->equalTo($expiry));
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->count());
    }

    /**
     * The same answer from the ROW rather than the window: past the 24h dedup
     * horizon a duplicate card still changes nothing, and must still not send a
     * second PATCH. This is the gap #1171 records on the removal verb.
     */
    public function test_a_duplicate_card_approved_after_the_dedup_window_still_sends_no_second_patch(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same edit, raised twice']);
        // ONE instant, held across the travel below: newExpiry() is relative to
        // now(), so re-deriving it for the second card would ask for a lifetime
        // minutes apart from the first and stop being a duplicate at all.
        $expiry = $this->newExpiry();
        $write = $this->mockWrite();
        // Mesh agrees with whatever the PSA record enforces: these tests are
        // about the duplicate brakes, not the display post-condition.
        $write->shouldReceive('findRuleById')->andReturnUsing(fn () => $this->upstreamRow([
            'date_expiry' => $record->fresh()?->expires_at?->toDateString(),
        ]));

        $first = $this->stagedRun($fixture, ['expires_at' => $expiry->toIso8601String()]);
        $this->travel(6)->minutes();
        $this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['ticket_id' => $secondTicket->id, 'expires_at' => $expiry->toIso8601String()]),
        )->assertOk();
        $duplicate = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        $write->shouldReceive('patchRule')->once()->andReturn([]);
        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('success');

        // Well past DIRECT_DEDUP_HOURS, so alreadyExecuted() can no longer see
        // the first approval at all.
        $write->shouldNotReceive('patchRule');
        $this->travel(30)->hours();

        $this->actingAs($actor)->post(route('cockpit.approve', $duplicate))->assertSessionHas('error');
        $this->assertStringContainsString('this proposal changes nothing and no upstream call was made', (string) session('error'));
        $this->assertSame(TechnicianRunState::Done, $duplicate->fresh()->state);
        $this->assertTrue($record->fresh()->expires_at->equalTo($expiry));
    }

    /**
     * The three verbs are different writes and do NOT share a dedup or cooldown
     * window — the regression guard for the shortcut that made one verb's
     * action-log query answer for another.
     */
    public function test_a_staged_addition_does_not_spend_the_edit_verbs_cooldown(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());

        $added = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => 'other@vendor.example',
            'confirm_domain' => 'vendor.example',
            'reason' => 'Unrelated allow staged moments earlier for the same client.',
        ]));
        $this->assertTrue($added['success']);

        $edited = $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture),
        ));

        $this->assertArrayNotHasKey('error', $edited);
        $this->assertTrue($edited['success']);
        $this->assertSame(2, TechnicianRun::count());

        // And the reverse: a second EDIT for that client is braked by its own
        // verb, so the split did not simply remove the cooldown.
        $this->travel(1)->minute();
        $this->assertStringContainsString('cooldown active', $this->decodedResult($this->callTool(
            $this->token(['mesh_edit_allow_rule:staged']),
            'mesh_edit_allow_rule',
            $this->editArgs($fixture, ['expires_at' => now()->addDays(120)->startOfMinute()->toIso8601String()]),
        ))['error']);
    }
}
