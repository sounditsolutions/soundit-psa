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
 * #1134 — mesh_remove_allow_rule.
 *
 * Removing an allow rule STRENGTHENS a customer's filtering, which is why the
 * verb exists at all — but it is still a delete against a vendor tenant, and
 * three facts shape every test here:
 *
 *   1. SCOPE. Mesh's detail route is NOT tenant-scoped: the API key can read
 *      and delete any rule id in the partnership. The verb therefore resolves
 *      the id through the scoped list, so another customer's rule is ABSENT
 *      rather than deletable, and the refusal never confirms it exists.
 *   2. FOREIGN RULES. The PSA did not write most of what is in a tenant. A
 *      rule it holds no record of is removable — and is LABELLED foreign on
 *      the card, because a human put it there for a reason this system cannot
 *      see.
 *   3. THE POST-CONDITION IS THE RESULT. A DELETE's own response proves
 *      nothing (Mesh commits before it answers). Only a 404 on the re-read
 *      makes this a success; still-readable and could-not-measure are both
 *      faults, and neither is ever reported as done.
 */
class MeshRemoveAllowRuleTest extends TestCase
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
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor allow no longer needed']);

        return compact('client', 'ticket');
    }

    /** @return array<string, mixed> */
    private function removeArgs(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'rule_id' => 'rule-xyz',
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'The vendor migrated to an authenticated domain; the allow is no longer needed.',
        ], $overrides);
    }

    private function mockWrite(): Mockery\MockInterface
    {
        $client = Mockery::mock(MeshWriteClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->app->instance(MeshWriteClient::class, $client);

        return $client;
    }

    /**
     * A row as MeshWriteClient::findRuleById() hands it back — already proved
     * to belong to the tenant, so the executor's job is the allow/block type,
     * the typed sender and the tracked-vs-foreign labelling.
     *
     * @return array<string, mixed>
     */
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
            'expires_at' => now()->addDays(30),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ], $overrides));
    }

    private function stagedRun(array $fixture, array $overrides = []): TechnicianRun
    {
        $this->callTool($this->token(['mesh_remove_allow_rule:staged']), 'mesh_remove_allow_rule', $this->removeArgs($fixture, $overrides))->assertOk();
        $run = TechnicianRun::where('action_type', 'mesh_stage_remove_allow_rule')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    // ---- surface / grant shape ------------------------------------------------

    public function test_the_verb_is_a_sensitive_grantable_mesh_tool_and_never_defaults_immediate(): void
    {
        $this->configureMesh();

        $groups = McpToolRegistry::groups();
        $this->assertContains('mesh_remove_allow_rule', array_column($groups['mesh_admin']['tools'], 'name'));
        $this->assertContains('mesh_remove_allow_rule', McpToolRegistry::allToolNames());
        $this->assertSame('mesh', McpToolRegistry::integrationForToolName('mesh_remove_allow_rule'));

        $this->assertNotContains(
            'mesh_remove_allow_rule',
            array_column($this->listTools($this->legacyToken()), 'name'),
            'a legacy full-surface token must not gain the removal verb',
        );

        $scoped = collect($this->listTools($this->token(['mesh_remove_allow_rule:staged'])))->keyBy('name');
        $this->assertArrayHasKey('mesh_remove_allow_rule', $scoped);
        $definition = $scoped['mesh_remove_allow_rule'];
        foreach (['rule_id', 'confirm_sender', 'reason', 'ticket_id'] as $required) {
            $this->assertContains($required, $definition['inputSchema']['required']);
        }
        $this->assertStringContainsString('STAGED ONLY', $definition['description']);
        $this->assertStringContainsString('FOREIGN', $definition['description']);

        // The staged alias is dispatch-only; the grant default is staged, which
        // is the second lock behind the executor's own refusal.
        $this->assertNotContains('mesh_stage_remove_allow_rule', $scoped->keys()->all());
        $this->assertSame(['mesh_remove_allow_rule', McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry('mesh_remove_allow_rule:staged'));
        $this->assertSame(McpToolModes::MODE_STAGED, McpToolModes::defaultMode('mesh_remove_allow_rule'));
        $this->assertTrue(StagedActionLabels::isVendorSideEffectAction('mesh_stage_remove_allow_rule'));
        $this->assertTrue(StagedActionLabels::hasCuratedLabel('mesh_stage_remove_allow_rule'), 'the cockpit must not show an approver the raw action_type');
        $this->assertSame('Mesh allow rule removal', StagedActionLabels::humanLabel('mesh_stage_remove_allow_rule'));
    }

    public function test_a_bare_immediate_grant_is_refused_and_names_the_staged_grant(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('deleteRule');
        $write->shouldNotReceive('findRuleById');

        // The immediate mode is reachable only by an explicit grant; the
        // executor refuses it anyway, so both locks are exercised.
        $result = $this->decodedResult(
            $this->callTool($this->token(['mesh_remove_allow_rule:immediate']), 'mesh_remove_allow_rule', $this->removeArgs($fixture))
        );

        $this->assertStringContainsString('staged-only', $result['error']);
        $this->assertStringContainsString('mesh_remove_allow_rule:staged', $result['error']);
        $this->assertStringContainsString('No upstream call was made', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_the_verb_is_not_published_while_mesh_is_unconfigured(): void
    {
        $this->assertNotContains(
            'mesh_remove_allow_rule',
            array_column($this->listTools($this->token(['mesh_remove_allow_rule:staged'])), 'name'),
        );
    }

    // ---- argument surface -----------------------------------------------------

    /**
     * The allow-list is PER VERB. A reason true of the create verb is not true
     * of this one, and a shared flat list would either accept `expires_at` on
     * a removal (meaningless — there is nothing to give a lifetime to) or
     * refuse `confirm_sender` on both.
     */
    public function test_widening_and_create_shaped_parameters_are_refused_by_name(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        foreach (['expires_at' => '2027-01-01', 'sender' => 'x@y.test', 'rule_ids' => ['a', 'b'], 'all' => true, 'customers' => ['t'], 'organization_level' => true, 'force' => true] as $key => $value) {
            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_remove_allow_rule:staged']),
                'mesh_remove_allow_rule',
                $this->removeArgs($fixture, [$key => $value]),
            ));

            $this->assertStringContainsString('not accepted by mesh_remove_allow_rule', $result['error'] ?? '', "'{$key}' must be refused by name");
            $this->assertStringContainsString($key, $result['error']);
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_the_create_verbs_own_parameters_are_still_accepted_on_the_create_verb(): void
    {
        // Guards the per-verb split against a regression that made one verb's
        // allow-list the other's.
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->mockWrite();

        $result = $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => 'billing@vendor.example',
            'confirm_domain' => 'vendor.example',
            'expires_at' => now()->addDays(10)->toIso8601String(),
            'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
        ]));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success']);
    }

    public function test_reason_and_ticket_are_required(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldNotReceive('findRuleById');

        $args = $this->removeArgs($fixture);
        unset($args['reason']);
        $this->assertStringContainsString('reason is required', $this->decodedResult(
            $this->callTool($this->token(['mesh_remove_allow_rule:staged']), 'mesh_remove_allow_rule', $args)
        )['error']);

        $args = $this->removeArgs($fixture);
        unset($args['ticket_id']);
        $this->assertArrayHasKey('error', $this->decodedResult(
            $this->callTool($this->token(['mesh_remove_allow_rule:staged']), 'mesh_remove_allow_rule', $args)
        ));

        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- scope ----------------------------------------------------------------

    /**
     * Criterion 1. The id is real upstream and the key could delete it — it
     * just is not this client's. The verb must treat it as absent, must not
     * confirm the other tenant's row exists, and must stage nothing.
     */
    public function test_a_rule_id_outside_this_clients_tenant_is_absent_not_deletable(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->with(self::TENANT, 'rule-of-another-customer')->andReturn(null);
        $write->shouldNotReceive('deleteRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['rule_id' => 'rule-of-another-customer']),
        ));

        $this->assertStringContainsString("No allow rule with id 'rule-of-another-customer' belongs to Acme's Mesh tenant", $result['error']);
        $this->assertStringContainsString('Nothing was removed', $result['error']);
        $this->assertStringNotContainsString('another customer\'s rule', $result['error']);

        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_remove_allow_rule')
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
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertStringContainsString('no Mesh customer mapping', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /** An unreadable list is not an empty list. Fail closed, in both directions. */
    public function test_an_unreadable_rule_list_refuses_rather_than_assuming_absence(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andThrow(new MeshClientException('read timed out'));
        $write->shouldNotReceive('deleteRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertStringContainsString('scope could not be checked and nothing was removed', $result['error']);
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
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['rule_id' => str_repeat('a', 256)]),
        ));

        $this->assertStringContainsString('not a Mesh rule id', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    // ---- allow-only, and the typed confirmation -------------------------------

    public function test_a_block_rule_is_refused_because_removing_it_would_unblock_a_sender(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['ab' => false]));
        $write->shouldNotReceive('deleteRule');

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertStringContainsString('is a BLOCK rule, not an allow rule', $result['error']);
        $this->assertStringContainsString('un-block a sender this customer chose to block', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    /** Unable-to-assess is a refusal, never a pass. */
    public function test_a_rule_whose_type_mesh_does_not_state_is_refused_too(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();

        foreach ([['ab' => null], ['ab' => 'true'], ['ab' => 1]] as $shape) {
            $write = $this->mockWrite();
            $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow($shape));
            $write->shouldNotReceive('deleteRule');

            $result = $this->decodedResult($this->callTool(
                $this->token(['mesh_remove_allow_rule:staged']),
                'mesh_remove_allow_rule',
                $this->removeArgs($fixture),
            ));

            $this->assertStringContainsString('did not state whether', $result['error'], 'ab='.var_export($shape['ab'], true).' must not be assumed to be an allow rule');
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_confirm_sender_must_match_the_rules_actual_sender(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $write->shouldNotReceive('deleteRule');

        // A VALID id, pasted for the wrong rule: the id resolves, the sender
        // does not. This is the only check that catches it.
        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['confirm_sender' => 'someone-else@vendor.example']),
        ));

        $this->assertStringContainsString('confirm_sender must exactly match the sender', $result['error']);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_confirm_sender_matches_case_insensitively(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['sender' => 'Billing@Vendor.Example']));

        $result = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['confirm_sender' => '  BILLING@vendor.example  ']),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('billing@vendor.example', $result['sender']);
    }

    // ---- the card -------------------------------------------------------------

    public function test_the_card_labels_a_psa_tracked_rule_and_names_its_expiry(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);

        $this->assertStringContainsString("PSA-TRACKED (record #{$record->id}", $run->proposed_content);
        $this->assertStringContainsString('due to expire', $run->proposed_content);
        $this->assertStringNotContainsString('FOREIGN', $run->proposed_content);
        $this->assertSame('yes', $run->proposed_meta['redacted_params']['psa_tracked']);

        // The approver is told how WIDE the rule is, who Mesh says made it,
        // and that Mesh's own displayed expiry is display-only.
        $this->assertStringContainsString("allows the single address 'billing@vendor.example'", $run->proposed_content);
        $this->assertStringContainsString('owner@soundit.example', $run->proposed_content);
        $this->assertStringContainsString('display only', $run->proposed_content);
    }

    public function test_the_card_labels_a_permanent_tracked_rule_as_permanent(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $this->tracked($fixture, ['expires_at' => null]);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);

        $this->assertStringContainsString('PERMANENT — it has no expiry and nothing in the PSA would ever have removed it', $run->proposed_content);
    }

    /**
     * Criterion 3. A rule the PSA never wrote is removable — and the approver
     * is told, in words, that somebody set it up outside this system.
     */
    public function test_the_card_labels_a_foreign_rule_and_warns_it_may_be_load_bearing(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['comment' => 'phish sim server']));

        $run = $this->stagedRun($fixture);

        $this->assertStringContainsString('This rule is FOREIGN', $run->proposed_content);
        $this->assertStringContainsString('removing it may break mail delivery that is working today', $run->proposed_content);
        $this->assertStringContainsString('phish sim server', $run->proposed_content);
        $this->assertStringNotContainsString('PSA-TRACKED', $run->proposed_content);
        $this->assertSame('no (foreign rule)', $run->proposed_meta['redacted_params']['psa_tracked']);
    }

    /** The caller is told too, not only the approver. */
    public function test_the_staging_result_reports_whether_the_rule_is_psa_tracked(): void
    {
        $this->configureMesh();
        $foreign = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());

        $this->assertFalse($this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($foreign),
        ))['psa_tracked']);

        // A second CLIENT, on its own tenant: mesh_customer_id is unique per
        // client, and a same-client second call inside the cooldown would be
        // answering a different question than this one.
        $second = '99999999-8888-7777-6666-555555555555';
        $owned = $this->fixture($second);
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $this->tracked($owned, [
            'client_id' => $owned['client']->id,
            'ticket_id' => $owned['ticket']->id,
            'mesh_customer_id' => $second,
        ]);

        $this->assertTrue($this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($owned),
        ))['psa_tracked']);
    }

    public function test_a_domain_wide_rule_says_so_in_words(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['sender' => 'vendor.example']));

        $run = $this->stagedRun($fixture, ['confirm_sender' => 'vendor.example']);

        $this->assertStringContainsString("allows EVERY sender at 'vendor.example'", $run->proposed_content);
    }

    /**
     * The tenant, the sender and the allow/block flag are all re-resolved at
     * approval, so the payload carries only the id and the confirmation — and
     * nothing that could stand in for the second scope check.
     */
    public function test_the_staged_payload_carries_only_the_id_the_confirmation_and_the_reason(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);

        $this->assertSame('mesh_remove_allow_rule', $payload['direct_tool']);
        $this->assertSame(['rule_id', 'confirm_sender', 'reason'], array_keys($payload['arguments']));
        $this->assertSame('rule-xyz', $payload['arguments']['rule_id']);
    }

    // ---- approval → execution -------------------------------------------------

    public function test_approval_removes_the_rule_proves_absence_and_closes_the_psa_record(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once()->with('rule-xyz');
        $write->shouldReceive('ruleAbsent')->once()->with('rule-xyz')->andReturn(true);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REMOVED, $record->state);
        $this->assertNotNull($record->removed_at);
        $this->assertNull($record->last_error);

        // Removed is NOT reaped, and it is out of the reaper's queue by
        // construction rather than by an expiry that happens not to have come.
        $this->assertNull($record->reaped_at);
        $this->assertSame(0, MeshAllowRule::query()->reapable()->count());

        $log = TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole();
        $this->assertStringContainsString('rule-xyz', $log->summary);
        $this->assertStringContainsString('absence proved by detail read', $log->summary);
        $this->assertStringContainsString("PSA record #{$record->id} closed", $log->summary);
    }

    public function test_removing_a_foreign_rule_succeeds_and_creates_no_psa_record(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(true);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $this->assertSame(0, MeshAllowRule::count(), 'a removal must never manufacture a tracking row for a rule the PSA did not create');
        $this->assertStringContainsString(
            'the PSA held no record of it',
            TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole()->summary,
        );
    }

    /**
     * Criterion 4, the hard half. Mesh commits before it answers, so a DELETE
     * that threw may sit on top of a rule that IS gone. The post-condition is
     * measured anyway, and it — not the exception — decides the outcome.
     */
    public function test_a_delete_that_threw_over_a_rule_that_is_gone_is_a_success_that_says_so(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once()->andThrow(new MeshClientException('connection reset'));
        $write->shouldReceive('ruleAbsent')->once()->andReturn(true);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $this->assertSame(MeshAllowRule::STATE_REMOVED, $record->fresh()->state);
        $summary = TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringContainsString('connection reset', $summary);
        $this->assertStringContainsString('but the rule is gone', $summary);
    }

    public function test_a_rule_still_readable_after_the_delete_is_a_fault_never_a_success(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(false);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('was NOT removed', (string) session('error'));
        $this->assertStringContainsString('still bypassing filtering', (string) session('error'));

        // The proposal is SPENT — a fault must not hand the card back for a
        // second DELETE against a rule whose state is already unclear.
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // And the row stays in a state the reaper still works: the hole is live
        // and the expiry job is the only thing that would ever close it.
        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertNull($record->removed_at);
        $this->assertStringContainsString('was NOT removed', (string) $record->last_error);
        $this->assertSame(1, MeshAllowRule::query()->where('id', $record->id)->whereIn('state', [MeshAllowRule::STATE_REAP_FAILED])->count());

        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed_with_fault')->count());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->count());
    }

    /** Unmeasurable is not a pass — ruleAbsent() returning null is its own fault. */
    public function test_an_unmeasurable_post_condition_is_a_fault_and_says_treat_the_rule_as_live(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(null);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('could NOT be measured', (string) session('error'));
        $this->assertStringContainsString('Treat the rule as still live', (string) session('error'));

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->state);
        $this->assertNull($record->removed_at);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed_with_fault')->count());
    }

    /**
     * The scope check runs AGAIN at approval, against live state — a card can
     * wait days, and a client's Mesh mapping can be re-pointed in that time.
     */
    public function test_a_rule_that_no_longer_belongs_to_this_client_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        // By the time the approver clicks, the rule no longer resolves in the
        // client's tenant.
        $write->shouldReceive('findRuleById')->once()->andReturn(null);
        $write->shouldNotReceive('deleteRule');
        $write->shouldNotReceive('ruleAbsent');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('re-checked at approval; nothing was removed', (string) session('error'));
        $this->assertNotSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_a_rule_that_became_a_block_rule_is_refused_at_approval(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('findRuleById')->once()->andReturn($this->upstreamRow(['ab' => false]));
        $write->shouldNotReceive('deleteRule');

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertStringContainsString('is a BLOCK rule', (string) session('error'));
    }

    // ---- idempotence ----------------------------------------------------------

    /**
     * Unlike the create verb, a repeat is a genuinely idempotent answer: the
     * requested end state (that rule gone) is the state that holds, and there
     * is no lifetime a second caller could be silently deprived of.
     */
    public function test_a_second_removal_of_the_same_rule_reports_idempotent_success_and_stages_nothing(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        // Twice and no more: staging the card, and the approval's own scope
        // re-check. After that the rule is gone.
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(true);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        // The rule has just been PROVED absent, so the scoped list can no
        // longer resolve its id. A mock that kept handing the row back would be
        // asserting a state this test itself disproved, and would make the
        // idempotent answer reachable only in the mock — so the retry must be
        // answered WITHOUT resolving the target at all.
        $write->shouldNotReceive('findRuleById');

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertTrue($second['success']);
        $this->assertTrue($second['idempotent']);
        $this->assertStringContainsString('already removed', $second['message']);
        $this->assertStringNotContainsString('belongs to', $second['message'], 'a satisfied retry must not be answered with a tenant-scope error');
        $this->assertSame(1, TechnicianRun::count(), 'no second proposal may be staged');
    }

    /**
     * The idempotent answer is keyed on the rule id ALONE, and the id is
     * exactly what a caller pastes from the wrong ticket. The typed
     * confirmation is still checked before that answer is given: otherwise a
     * mis-pasted id reads as handled while the rule the caller meant to remove
     * stays live, with nothing staged and nothing rejected to notice.
     */
    public function test_a_repeat_removal_whose_typed_sender_does_not_match_is_refused_not_answered_idempotently(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->twice()->andReturn($this->upstreamRow());
        $run = $this->stagedRun($fixture);

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(true);
        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        // The rule is gone, so the sender is re-read from the executed
        // proposal's own record rather than from upstream.
        $write->shouldNotReceive('findRuleById');

        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['confirm_sender' => 'someone-else@vendor.example']),
        ));

        $this->assertArrayNotHasKey('success', $second);
        $this->assertStringContainsString('confirm_sender must exactly match the sender', $second['error']);
        $this->assertSame(1, TechnicianRun::count(), 'no second proposal may be staged');
        $this->assertSame(1, TechnicianActionLog::query()
            ->where('action_type', 'mesh_stage_remove_allow_rule')
            ->where('result_status', 'rejected')
            ->count(), 'a refused confirmation must leave a rejected audit row');
    }

    /**
     * The same answer at APPROVAL. Two cards for one rule can exist — the run
     * slot is per ticket — and the second is approved after the first has
     * already proved the rule absent, so its id no longer resolves. It must
     * close as an idempotent no-op: refusing it with a tenant-scope error
     * releases the claim and strands the card AwaitingApproval forever, while
     * telling the approver to check an id that was never wrong.
     */
    public function test_a_duplicate_card_approved_after_the_removal_landed_closes_as_idempotent(): void
    {
        $this->configureMesh();
        $actor = $this->configureAiActor();
        $fixture = $this->fixture();
        $secondTicket = Ticket::factory()->for($fixture['client'])->create(['subject' => 'Same allow, raised twice']);
        $write = $this->mockWrite();
        // Three resolutions and no more: staging each card, and the FIRST
        // approval's scope re-check.
        $write->shouldReceive('findRuleById')->times(3)->andReturn($this->upstreamRow());

        $first = $this->stagedRun($fixture);

        // Past the staging cooldown, and on another ticket, so this is a
        // genuinely second card rather than the live proposal handed back.
        $this->travel(6)->minutes();
        $this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture, ['ticket_id' => $secondTicket->id]),
        )->assertOk();
        $duplicate = TechnicianRun::where('ticket_id', $secondTicket->id)->sole();

        $write->shouldReceive('deleteRule')->once();
        $write->shouldReceive('ruleAbsent')->once()->andReturn(true);
        $this->actingAs($actor)->post(route('cockpit.approve', $first))->assertSessionHas('success');

        // The rule is gone and proved gone: nothing resolves it now, and no
        // second DELETE may be sent for it.
        $write->shouldNotReceive('findRuleById');
        $write->shouldNotReceive('deleteRule');
        $this->travel(6)->minutes();

        $this->actingAs($actor)->post(route('cockpit.approve', $duplicate))->assertSessionHas('error');
        $this->assertStringContainsString('was already removed for this client recently', (string) session('error'));
        $this->assertStringNotContainsString('belongs to', (string) session('error'));

        // Terminal, not stranded: the card is spent, and the idempotent outcome
        // rides the fault channel rather than reading as a fresh removal.
        $this->assertSame(TechnicianRunState::Done, $duplicate->fresh()->state);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->count());
    }

    /**
     * The two verbs are opposite-signed writes and do NOT share a dedup or
     * cooldown window. This is the regression guard for the shortcut that was
     * invisible while there was only one verb: actionLogQuery() took a $tool
     * argument and ignored it, listing the create verb's action types. With a
     * second verb that meant a removal was throttled by an unrelated addition
     * — and, in the other direction, that a repeat removal was never caught.
     */
    public function test_a_staged_addition_does_not_spend_the_removal_verbs_cooldown(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
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

        $removed = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertArrayNotHasKey('error', $removed);
        $this->assertTrue($removed['success']);
        $this->assertSame(2, TechnicianRun::count());

        // And the reverse: a second ADDITION for that client is still braked by
        // its own verb, so the split did not simply remove the cooldown.
        $this->assertStringContainsString('cooldown active', $this->decodedResult($this->callTool($this->token(['mesh_add_allow_rule:staged']), 'mesh_add_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => 'third@vendor.example',
            'confirm_domain' => 'vendor.example',
            'reason' => 'A third allow for the same client inside the cooldown window.',
        ]))['error']);
    }

    public function test_staging_the_same_removal_twice_returns_the_live_proposal(): void
    {
        $this->configureMesh();
        $fixture = $this->fixture();
        $write = $this->mockWrite();
        $write->shouldReceive('findRuleById')->andReturn($this->upstreamRow());

        $run = $this->stagedRun($fixture);
        $second = $this->decodedResult($this->callTool(
            $this->token(['mesh_remove_allow_rule:staged']),
            'mesh_remove_allow_rule',
            $this->removeArgs($fixture),
        ));

        $this->assertTrue($second['idempotent']);
        $this->assertSame($run->id, $second['run_id']);
        $this->assertSame(1, TechnicianRun::count());
    }
}
