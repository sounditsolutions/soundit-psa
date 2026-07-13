<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * CIPP create M365 user — staged twin, default staged-only (bead psa-pbvy.1).
 * Extends the CippWriteUserLifecycle test family: server-derived tenant scope
 * (the UPN domain is always the client's mapped CIPP tenant domain, never a
 * caller value), CIPP-generated temp password delivered exactly once (tool
 * result on the immediate path, cockpit approval response on the staged path)
 * and never persisted or audited.
 */
class CippWriteCreateUserTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_create_user';

    private const STAGED_TOOL = 'cipp_stage_create_user';

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

    /** @return array{client: Client, ticket: Ticket, licenseType: LicenseType, license: License} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'subject' => 'New starter onboarding',
        ]);

        $licenseType = LicenseType::create([
            'name' => 'Business Premium',
            'vendor' => 'cipp_m365',
            'vendor_sku_id' => 'sku-business-premium',
            'is_active' => true,
        ]);

        $license = License::create([
            'license_type_id' => $licenseType->id,
            'client_id' => $client->id,
            'quantity' => 10,
            'assigned_quantity' => 2,
            'vendor_ref' => 'sku-from-tenant-sync',
            'status' => 'active',
            'synced_at' => now(),
        ]);

        return compact('client', 'ticket', 'licenseType', 'license');
    }

    /** @return array<string, mixed> the CIPP AddUser response body shape (Invoke-AddUser.ps1) */
    private function addUserBody(string $upn, ?string $password): array
    {
        $results = [
            'Created New User.',
            ['resultText' => "Username: {$upn}", 'copyField' => $upn, 'state' => 'success'],
        ];

        if ($password !== null) {
            $results[] = ['resultText' => "Password: {$password}", 'copyField' => $password, 'state' => 'success'];
        }

        return [
            'Results' => $results,
            'CopyFrom' => ['Success' => [], 'Error' => []],
            'User' => ['id' => 'new-user-object-id'],
        ];
    }

    private function mockCreate(string $upn, ?string $password, array $extraResults = []): Mockery\MockInterface
    {
        $body = $this->addUserBody($upn, $password);
        foreach ($extraResults as $extra) {
            $body['Results'][] = $extra;
        }

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('createUser')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => $body]);
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    private function blockedClient(): Mockery\MockInterface
    {
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('createUser');
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    /** @return array<string, mixed> */
    private function validArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'username' => 'newhire',
            'display_name' => 'New Hire',
            'given_name' => 'New',
            'surname' => 'Hire',
            'confirm_upn' => 'newhire@acme.onmicrosoft.com',
            'reason' => 'Onboarding a verified new starter for this client.',
        ], $overrides);
    }

    public function test_tool_is_registered_stageable_sensitive_and_grant_only(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertContains(self::TOOL, $writeNames, 'create-user tool must be in the sensitive cipp_write group');
        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames(), 'create-user tool must be token-grantable');

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

        // Surface discovery: with CIPP configured but no grant, the catalog
        // classifies the capability as available_ungranted (an operator token
        // grant — not a config change — is the remedy).
        $states = \App\Support\McpToolSurface::classifyNames([self::TOOL], fn (string $name): bool => false);
        $this->assertSame(\App\Support\McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states[self::TOOL]);

        $scoped = collect($this->listTools($this->token([self::TOOL])))->keyBy('name');
        $this->assertFalse($scoped->has(self::STAGED_TOOL));
        $create = $scoped[self::TOOL];
        foreach (['client_id', 'username', 'display_name', 'given_name', 'surname', 'confirm_upn', 'reason'] as $required) {
            $this->assertContains($required, $create['inputSchema']['required'], "{$required} must be required");
        }
        $this->assertArrayHasKey('usage_location', $create['inputSchema']['properties']);
        $this->assertArrayHasKey('license_type_id', $create['inputSchema']['properties']);
        $this->assertArrayHasKey('staged', $create['inputSchema']['properties']);

        // Never exposes upstream body keys — the UPN domain is server-derived.
        foreach (['tenantFilter', 'Domain', 'PrimDomain', 'password', 'licenses', 'MustChangePass', 'userPrincipalName'] as $upstream) {
            $this->assertArrayNotHasKey($upstream, $create['inputSchema']['properties']);
        }
        $this->assertStringContainsString('tenant domain', $create['description']);
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
            ['PrimDomain' => ['value' => 'attacker.example']],
            ['password' => 'attacker-chosen-password'],
            ['licenses' => [['value' => 'attacker-sku']]],
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

    public function test_confirm_upn_must_match_server_composed_upn(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'confirm_upn' => 'newhire@wrong.example',
        ]));

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('confirm_upn', (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_username_must_be_a_valid_local_part(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->blockedClient();

        foreach (['new hire', 'newhire@evil.example', '.newhire', 'newhire.', str_repeat('a', 65)] as $bad) {
            $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
                'username' => $bad,
            ]));

            $this->assertTrue((bool) $response->json('result.isError'), "username '{$bad}' must be rejected");
            $this->assertStringContainsString('username', (string) $response->json('result.content.0.text'));
        }
    }

    public function test_kill_switch_blocks_create_before_upstream_call(): void
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

    public function test_direct_create_composes_server_derived_upn_and_returns_password_once(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $secret = 'Temp-P4ss-Once!';

        $client = $this->mockCreate('newhire@acme.onmicrosoft.com', $secret);

        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));

        // Server-derived scope: tenantFilter AND the UPN domain are both the
        // client's mapped tenant domain; no license and no usage location.
        $client->shouldHaveReceived('createUser')
            ->with('acme.onmicrosoft.com', 'newhire', 'acme.onmicrosoft.com', 'New Hire', 'New', 'Hire', null, null);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('newhire@acme.onmicrosoft.com', $result['user_principal_name']);
        $this->assertSame($secret, $result['temporary_password']);
        $this->assertTrue($result['must_change_at_next_logon']);
        $this->assertStringContainsString('secure channel', $result['message']);

        // Executed audit row names the created UPN, but the credential is in
        // NO persistent sink and NO log line.
        $executed = TechnicianActionLog::where('action_type', self::TOOL)->where('result_status', 'executed')->sole();
        $this->assertStringContainsString('newhire@acme.onmicrosoft.com', $executed->summary);
        $this->assertStringNotContainsString($secret, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(McpAuditLog::all()->toArray()));
        $this->assertSame(0, TechnicianRun::count());
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'temp password leaked into a log line');
        }
    }

    public function test_direct_create_reports_missing_password_value(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->mockCreate('newhire@acme.onmicrosoft.com', null);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture));

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['password_returned']);
        $this->assertArrayNotHasKey('temporary_password', $result);
        $this->assertStringContainsString('PwPush', $result['message']);
    }

    public function test_duplicate_direct_create_is_idempotent_without_second_upstream_call(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        // Mockery ->once() enforces that only the first call reaches upstream.
        $this->mockCreate('newhire@acme.onmicrosoft.com', 'pw-first');

        $token = $this->token([self::TOOL]);
        $first = $this->callTool($token, self::TOOL, $this->validArguments($fixture));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));

        $second = $this->callTool($token, self::TOOL, $this->validArguments($fixture));
        $second->assertOk();
        $result = $this->decodedResult($second);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['idempotent']);
        $this->assertArrayNotHasKey('temporary_password', $result);
        $this->assertStringContainsString('cipp_reset_user_password', $result['message']);
    }

    public function test_license_requires_usage_location_and_uses_resolved_sku(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->blockedClient();
        $missingLocation = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'license_type_id' => $fixture['licenseType']->id,
        ]));
        $this->assertTrue((bool) $missingLocation->json('result.isError'));
        $this->assertStringContainsString('usage_location', (string) $missingLocation->json('result.content.0.text'));

        $client = $this->mockCreate('newhire@acme.onmicrosoft.com', 'pw-lic');
        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'license_type_id' => $fixture['licenseType']->id,
            'usage_location' => 'us',
        ]));

        $client->shouldHaveReceived('createUser')
            ->with('acme.onmicrosoft.com', 'newhire', 'acme.onmicrosoft.com', 'New Hire', 'New', 'Hire', 'US', 'sku-from-tenant-sync');

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame($fixture['licenseType']->id, $this->decodedResult($response)['license_type_id']);
    }

    public function test_direct_create_surfaces_post_create_warnings(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->mockCreate('newhire@acme.onmicrosoft.com', 'pw-warn', [
            'Failed to assign the license. Insufficient available seats.',
        ]);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, $this->validArguments($fixture, [
            'license_type_id' => $fixture['licenseType']->id,
            'usage_location' => 'US',
        ]));

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('pw-warn', $result['temporary_password']);
        $this->assertNotEmpty($result['post_create_warnings']);
        $this->assertStringContainsString('Failed to assign the license', $result['post_create_warnings'][0]);
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

    public function test_staged_create_requires_ticket_encrypts_payload_and_approval_shows_password_once(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token([self::STAGED_TOOL]);
        $secret = 'Approved-0nce-Secret!';

        $this->blockedClient();
        $missingTicket = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture));
        $this->assertTrue((bool) $missingTicket->json('result.isError'));
        $this->assertStringContainsString('ticket_id is required', (string) $missingTicket->json('result.content.0.text'));

        $staged = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));

        $staged->assertOk();
        $this->assertFalse((bool) $staged->json('result.isError'), (string) $staged->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame(self::STAGED_TOOL, $run->action_type);
        $this->assertNotEmpty($run->proposed_meta['encrypted_payload'] ?? null);
        $this->assertSame([], $run->proposed_meta['sensitive_inputs']);
        // The cockpit readout names the exact identity being created and the
        // one-time password delivery contract.
        $this->assertStringContainsString('newhire@acme.onmicrosoft.com', $run->proposed_content);
        $this->assertStringContainsString('shown once', $run->proposed_content);

        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('createUser')
            ->once()
            ->with('acme.onmicrosoft.com', 'newhire', 'acme.onmicrosoft.com', 'New Hire', 'New', 'Hire', null, null)
            ->andReturn(['success' => true, 'status' => 200, 'body' => $this->addUserBody('newhire@acme.onmicrosoft.com', $secret)]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $approval = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $approval->assertOk();
        $this->assertTrue((bool) $approval->json('ok'));
        $this->assertSame('executed', $approval->json('status'));
        // The temp password is delivered exactly once, in this response only.
        $this->assertSame($secret, $approval->json('secret'));
        $this->assertStringContainsString('newhire@acme.onmicrosoft.com', (string) $approval->json('message'));
        $this->assertStringNotContainsString($secret, (string) $approval->json('message'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::STAGED_TOOL,
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);

        // The credential exists in NO persistent sink and NO log line.
        $this->assertStringNotContainsString($secret, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(McpAuditLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(TechnicianRun::all()->toArray()));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'temp password leaked into a log line');
        }
    }

    public function test_approval_declines_on_tenant_mapping_drift(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->blockedClient();
        $staged = $this->callTool($this->token([self::STAGED_TOOL]), self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));
        $run = TechnicianRun::findOrFail($this->decodedResult($staged)['run_id']);

        // The client's tenant mapping changes between staging and approval —
        // the composed UPN would no longer match what the operator reviewed.
        $fixture['client']->update(['cipp_tenant_domain' => 'other.onmicrosoft.com']);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('createUser');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $approval = $this->actingAs($actor)->postJson(route('cockpit.approve', $run));

        $this->assertFalse((bool) $approval->json('ok'));
        $this->assertSame('gate_declined', $approval->json('status'));
        $this->assertStringContainsString('re-stage', (string) $approval->json('message'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_duplicate_approved_create_for_same_upn_is_a_logged_noop(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token([self::STAGED_TOOL]);

        $secondTicket = Ticket::factory()->for($fixture['client'])->create([
            'subject' => 'Duplicate onboarding request',
        ]);

        $this->blockedClient();
        $firstStage = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $fixture['ticket']->id,
        ]));
        $secondStage = $this->callTool($token, self::STAGED_TOOL, $this->validArguments($fixture, [
            'ticket_id' => $secondTicket->id,
        ]));

        $firstRun = TechnicianRun::findOrFail($this->decodedResult($firstStage)['run_id']);
        $secondRun = TechnicianRun::findOrFail($this->decodedResult($secondStage)['run_id']);
        $this->assertNotSame($firstRun->id, $secondRun->id);

        // Only the FIRST approval may reach upstream.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('createUser')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => $this->addUserBody('newhire@acme.onmicrosoft.com', 'pw-dup')]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $first = $this->actingAs($actor)->postJson(route('cockpit.approve', $firstRun));
        $this->assertSame('executed', $first->json('status'));

        $second = $this->actingAs($actor)->postJson(route('cockpit.approve', $secondRun));
        $this->assertSame('already_handled', $second->json('status'));
        $this->assertSame(TechnicianRunState::Done, $secondRun->fresh()->state);
    }

    public function test_upstream_failure_is_audited_without_response_echo(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('createUser')
            ->once()
            ->andThrow(new \App\Services\Cipp\CippClientException('CIPP write api/AddUser failed: HTTP 500'));
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
