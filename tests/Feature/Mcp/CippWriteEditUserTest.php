<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * CIPP edit M365 user profile + directory attributes — staged twin, default
 * staged-only (bead psa-pbvy.2). Extends the CippWriteUserLifecycle test
 * family: the target is always the server-resolved PSA person (never a
 * caller-supplied identity), the editable surface is the CIPP edit-user form
 * allowlist, updates are null-safe partial (only provided fields are sent;
 * explicit blanking rides the vendor's clearProperties whitelist), and the
 * sign-in UPN is pinned server-side to the person's current UPN.
 */
class CippWriteEditUserTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_edit_user';

    private const STAGED_TOOL = 'cipp_stage_edit_user';

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
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

    /** @return array{client: Client, person: Person, manager: Person, ticket: Ticket} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        // The person's UPN domain deliberately differs from the tenant domain:
        // the username/Domain halves sent upstream must derive from the
        // person's CURRENT cipp_upn, never from the tenant mapping.
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $manager = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Bobbie',
            'last_name' => 'Boss',
            'email' => 'boss@acme.example',
            'cipp_user_id' => 'user-999',
            'cipp_upn' => 'boss@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Profile update request',
        ]);

        return compact('client', 'person', 'manager', 'ticket');
    }

    private function mockEdit(): Mockery\MockInterface
    {
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('editUser')
            ->once()
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    private function blockedClient(): Mockery\MockInterface
    {
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('editUser');
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    /** @return array<string, mixed> */
    private function validArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'job_title' => 'Operations Manager',
            'reason' => 'Verified promotion recorded on the linked HR ticket.',
        ], $overrides);
    }

    public function test_tool_is_registered_stageable_sensitive_and_grant_only(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertContains(self::TOOL, $writeNames, 'edit-user tool must be in the sensitive cipp_write group');
        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames(), 'edit-user tool must be token-grantable');

        // The staged twin is a retired call-time alias, never a separate catalog entry.
        $this->assertNotContains(self::STAGED_TOOL, $writeNames);
        $this->assertTrue(McpToolModes::isStageable(self::TOOL));
        $this->assertSame(self::STAGED_TOOL, McpToolModes::stagedInternalFor(self::TOOL));
        $this->assertSame(self::TOOL, McpToolModes::canonicalForAlias(self::STAGED_TOOL));

        // Default staged-only at the grant layer: an alias grant and a :staged
        // grant both resolve to staged mode; only an explicit bare/immediate
        // entry unlocks immediate execution.
        $this->assertSame([self::TOOL, McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry(self::STAGED_TOOL));
        $this->assertSame([self::TOOL, McpToolModes::MODE_STAGED], McpToolModes::parseGrantEntry(self::TOOL.':staged'));
        $this->assertSame([self::TOOL, McpToolModes::MODE_IMMEDIATE], McpToolModes::parseGrantEntry(self::TOOL));

        // Ungranted-by-default: a legacy full-surface token never gains it.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains(self::TOOL, $legacyNames);
        $this->assertNotContains(self::STAGED_TOOL, $legacyNames);

        $scoped = collect($this->listTools($this->token([self::TOOL])))->keyBy('name');
        $this->assertFalse($scoped->has(self::STAGED_TOOL));
        $edit = $scoped[self::TOOL];
        foreach (['person_id', 'confirm_upn', 'reason'] as $required) {
            $this->assertContains($required, $edit['inputSchema']['required'], "{$required} must be required");
        }

        // The editable surface is the CIPP edit-user form allowlist.
        foreach ([
            'display_name', 'given_name', 'surname', 'job_title', 'department',
            'company_name', 'street_address', 'city', 'state', 'postal_code',
            'country', 'mobile_phone', 'business_phone', 'usage_location',
            'clear_fields', 'manager_person_id', 'staged',
        ] as $property) {
            $this->assertArrayHasKey($property, $edit['inputSchema']['properties'], "{$property} must be exposed");
        }

        // Never exposes upstream body keys — identity, scope, and the raw CIPP
        // body shape are server-derived.
        foreach (['tenantFilter', 'Domain', 'id', 'username', 'displayName', 'clearProperties', 'setManager', 'password', 'licenses', 'AddToGroups', 'userPrincipalName', 'mailNickname'] as $upstream) {
            $this->assertArrayNotHasKey($upstream, $edit['inputSchema']['properties']);
        }
        $this->assertStringContainsString('partial', mb_strtolower((string) $edit['description']));
    }

    public function test_rejects_caller_supplied_upstream_identifiers(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        foreach ([
            ['tenantFilter' => 'attacker.onmicrosoft.com'],
            ['Domain' => 'attacker.example'],
            ['displayName' => 'Smuggled Upstream Key'],
            ['clearProperties' => ['jobTitle']],
            ['setManager' => ['value' => 'attacker@evil.example']],
            ['AddToGroups' => [['value' => 'group-1']]],
            ['RemoveFromGroups' => [['value' => 'group-1']]],
            ['password' => 'attacker-chosen-password'],
            ['licenses' => [['value' => 'attacker-sku']]],
            ['AddedAliases' => 'alias@evil.example'],
            ['defaultAttributes' => ['extensionAttribute1' => ['value' => 'x']]],
            ['customData' => ['proxyAddresses' => 'smtp:evil@evil.example']],
            ['Scheduled' => ['Enabled' => true]],
        ] as $injected) {
            $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, $injected));

            $this->assertTrue((bool) $response->json('result.isError'), json_encode($injected));
            $this->assertStringContainsString(
                'upstream CIPP identifiers are not accepted',
                (string) $response->json('result.content.0.text'),
            );
        }
    }

    public function test_confirm_upn_must_match_resolved_person(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'confirm_upn' => 'someone.else@acme.example',
        ]));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('confirm_upn', (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_target_person_must_belong_to_client_and_have_cipp_mapping(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $otherClient = Client::factory()->create(['cipp_tenant_domain' => 'other.onmicrosoft.com']);
        $foreign = Person::create([
            'client_id' => $otherClient->id,
            'person_type' => PersonType::User,
            'first_name' => 'Frankie',
            'last_name' => 'Foreign',
            'email' => 'frankie@other.example',
            'cipp_user_id' => 'user-777',
            'cipp_upn' => 'frankie@other.example',
            'is_active' => true,
        ]);

        $crossClient = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'person_id' => $foreign->id,
            'confirm_upn' => 'frankie@other.example',
        ]));
        $this->assertTrue((bool) $crossClient->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossClient->json('result.content.0.text'));

        $unmapped = Person::create([
            'client_id' => $fixture['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Uma',
            'last_name' => 'Unmapped',
            'email' => 'uma@acme.example',
            'is_active' => true,
        ]);

        $noMapping = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'person_id' => $unmapped->id,
            'confirm_upn' => 'uma@acme.example',
        ]));
        $this->assertTrue((bool) $noMapping->json('result.isError'));
        $this->assertStringContainsString('no CIPP user mapping', (string) $noMapping->json('result.content.0.text'));
    }

    public function test_field_validation_rejects_bad_values_before_upstream(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();
        $token = $this->token([self::TOOL]);

        $cases = [
            // Over the CIPP form's own bound for the field.
            [['job_title' => str_repeat('a', 129)], 'job_title'],
            [['display_name' => str_repeat('a', 257)], 'display_name'],
            // Control characters never reach the upstream directory.
            [['department' => "Ops\x01Team"], 'control characters'],
            // A non-scalar set-value is refused loudly with the clear_fields
            // hint (blanking must go through clear_fields; the vendor
            // body-builder silently DROPS empty values, so a blank set-value
            // would silently no-op).
            [['department' => ['nested']], 'clear_fields'],
            // An empty string arrives as null through the HTTP layer
            // (ConvertEmptyStringsToNull) and is treated as OMITTED — never a
            // silent blank-write. With no other change left, that surfaces as
            // the no-change refusal.
            [['job_title' => null, 'department' => ''], 'No changes'],
            // usage_location is a 2-letter ISO code.
            [['usage_location' => 'USA'], 'usage_location'],
            // Unknown/unclearable fields are refused by the clear allowlist.
            [['clear_fields' => ['display_name']], 'clear_fields'],
            [['clear_fields' => ['otherMails']], 'clear_fields'],
            // A field cannot be both set and cleared in one call.
            [['clear_fields' => ['job_title']], 'both set and cleared'],
            // A call proposing no change at all is refused.
            [['job_title' => null], 'No changes'],
        ];

        foreach ($cases as [$overrides, $expected]) {
            $response = $this->callTool($token, self::TOOL, $this->validArguments($fixture, $overrides));

            $this->assertTrue((bool) $response->json('result.isError'), json_encode($overrides));
            $this->assertStringContainsString($expected, (string) $response->json('result.content.0.text'), json_encode($overrides));
        }
    }

    public function test_kill_switch_blocks_edit_before_upstream_call(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        Setting::setValue('technician_kill_switch', '1');
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('kill-switch', (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'blocked',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_direct_edit_sends_only_provided_fields_with_server_derived_identity(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = $this->mockEdit();

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
            'business_phone' => '555 0100',
            'clear_fields' => ['department'],
            'manager_person_id' => $fixture['manager']->id,
        ]));

        // Null-safe partial update: ONLY the provided fields ride upstream,
        // keyed by the vendor's UserObj names; the tenant, Graph object id,
        // and current UPN are all server-resolved; the manager UPN is derived
        // from the resolved manager person.
        $client->shouldHaveReceived('editUser')
            ->with(
                'acme.onmicrosoft.com',
                'user-123',
                'alex@acme.example',
                ['jobTitle' => 'Operations Manager', 'businessPhones' => ['555 0100']],
                ['department'],
                'boss@acme.example',
            );

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame($fixture['person']->id, $result['person_id']);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
        ]);
        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_manager_must_be_active_different_and_same_client(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();
        $token = $this->token([self::TOOL]);

        $fixture['manager']->update(['is_active' => false]);
        $inactive = $this->callTool($token, self::TOOL, $this->validArguments($fixture, [
            'manager_person_id' => $fixture['manager']->id,
        ]));
        $this->assertTrue((bool) $inactive->json('result.isError'));
        $this->assertStringContainsString('inactive', (string) $inactive->json('result.content.0.text'));
        $fixture['manager']->update(['is_active' => true]);

        $self = $this->callTool($token, self::TOOL, $this->validArguments($fixture, [
            'manager_person_id' => $fixture['person']->id,
        ]));
        $this->assertTrue((bool) $self->json('result.isError'));
        $this->assertStringContainsString('different person', (string) $self->json('result.content.0.text'));

        $otherClient = Client::factory()->create(['cipp_tenant_domain' => 'other.onmicrosoft.com']);
        $foreignManager = Person::create([
            'client_id' => $otherClient->id,
            'person_type' => PersonType::User,
            'first_name' => 'Frankie',
            'last_name' => 'Foreign',
            'email' => 'frankie@other.example',
            'cipp_user_id' => 'user-778',
            'cipp_upn' => 'frankie@other.example',
            'is_active' => true,
        ]);
        $crossClient = $this->callTool($token, self::TOOL, $this->validArguments($fixture, [
            'manager_person_id' => $foreignManager->id,
        ]));
        $this->assertTrue((bool) $crossClient->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossClient->json('result.content.0.text'));
    }

    public function test_duplicate_direct_edit_is_idempotent_without_second_upstream_call(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        // Mockery ->once() enforces that only the first call reaches upstream.
        $this->mockEdit();

        $token = $this->token([self::TOOL]);
        $first = $this->callTool($token, self::TOOL, $this->validArguments($fixture));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, self::TOOL, $this->validArguments($fixture));
        $second->assertOk();
        $result = $this->decodedResult($second);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['idempotent']);
    }

    public function test_staged_only_grant_downgrades_immediate_call_to_staged_proposal(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $response = $this->callTool($this->token([self::TOOL.':staged']), self::TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
            'staged' => false,
        ]));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['downgraded_to_staged']);

        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(self::STAGED_TOOL, $run->action_type);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
    }

    public function test_staged_edit_holds_safe_scalars_and_approval_replays_with_fresh_resolution(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token([self::STAGED_TOOL]);

        $this->blockedClient();
        $missingTicket = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture));
        $this->assertTrue((bool) $missingTicket->json('result.isError'));
        $this->assertStringContainsString('ticket_id is required', (string) $missingTicket->json('result.content.0.text'));

        $staged = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
            'clear_fields' => ['department'],
            'manager_person_id' => $fixture['manager']->id,
        ]));

        $staged->assertOk();
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame(self::STAGED_TOOL, $run->action_type);
        $this->assertNotEmpty($run->proposed_meta['encrypted_payload'] ?? null);
        $this->assertSame([], $run->proposed_meta['sensitive_inputs']);

        // The cockpit readout lists every proposed change verbatim so the
        // approver reviews exactly what will be written.
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);
        $this->assertStringContainsString('Operations Manager', $run->proposed_content);
        $this->assertStringContainsString('department', $run->proposed_content);
        $this->assertStringContainsString('boss@acme.example', $run->proposed_content);

        // The held payload stores only safe local scalars — no upstream ids
        // beyond what the executor re-derives, and no ResolvedCippPerson dump.
        $this->assertStringNotContainsString('manager_person"', json_encode($run->proposed_meta['redacted_params']));

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('editUser')
            ->once()
            ->with(
                'acme.onmicrosoft.com',
                'user-123',
                'alex@acme.example',
                ['jobTitle' => 'Operations Manager'],
                ['department'],
                'boss@acme.example',
            )
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $approval = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $approval->assertOk();
        $this->assertTrue((bool) $approval->json('ok'));
        $this->assertSame('executed', $approval->json('status'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::STAGED_TOOL,
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_approval_declines_when_manager_deactivated_after_staging(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->blockedClient();
        $staged = $this->callTool($this->token([self::STAGED_TOOL]), self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
            'manager_person_id' => $fixture['manager']->id,
        ]));
        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);

        // The manager is deactivated between staging and approval — the
        // replay re-resolves them fresh and must refuse the assignment.
        $fixture['manager']->update(['is_active' => false]);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('editUser');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $approval = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $this->assertFalse((bool) $approval->json('ok'));
        $this->assertSame('gate_declined', $approval->json('status'));
        $this->assertStringContainsString('inactive', (string) $approval->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_approval_declines_when_target_loses_cipp_mapping(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->blockedClient();
        $staged = $this->callTool($this->token([self::STAGED_TOOL]), self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));
        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);

        $fixture['person']->update(['cipp_user_id' => null, 'cipp_upn' => null]);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('editUser');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $approval = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $this->assertFalse((bool) $approval->json('ok'));
        $this->assertSame('gate_declined', $approval->json('status'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_hybrid_user_staged_display_carries_on_prem_sync_warning(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $fixture['person']->update(['is_hybrid' => true]);

        $this->blockedClient();
        $staged = $this->callTool($this->token([self::STAGED_TOOL]), self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));

        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);
        $this->assertStringContainsString('on-premises', $run->proposed_content);
    }

    public function test_upstream_failure_is_audited_without_response_echo(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('editUser')
            ->once()
            ->andThrow(new \App\Services\Cipp\CippClientException('CIPP write api/EditUser reported failure: Failed to edit user.'));
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'error',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_ungranted_token_is_denied(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $response = $this->callTool($this->token(['cipp_disable_user_sign_in']), self::TOOL, $this->validArguments($fixture));

        $this->assertTrue((bool) $response->json('result.isError'));
    }
}
