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
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class CippWriteUserLifecyclePr1Test extends TestCase
{
    use RefreshDatabase;

    private const PR_ONE_TOOLS = [
        'cipp_disable_user_sign_in',
        'cipp_stage_disable_user_sign_in',
        'cipp_enable_user_sign_in',
        'cipp_stage_enable_user_sign_in',
        'cipp_revoke_user_sessions',
        'cipp_stage_revoke_user_sessions',
        'cipp_remove_user_mfa_methods',
        'cipp_stage_remove_user_mfa_methods',
        'cipp_set_legacy_per_user_mfa',
        'cipp_stage_set_legacy_per_user_mfa',
        'cipp_assign_user_license',
        'cipp_stage_assign_user_license',
        'cipp_remove_user_license',
        'cipp_stage_remove_user_license',
    ];

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

    /** @return array{client: Client, person: Person, ticket: Ticket, licenseType: LicenseType, license: License} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

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

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'M365 account update',
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

        return compact('client', 'person', 'ticket', 'licenseType', 'license');
    }

    public function test_pr_one_cipp_write_tools_are_sensitive_and_explicit_grant_only(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        foreach (self::PR_ONE_TOOLS as $tool) {
            if (($canonical = McpToolModes::canonicalForAlias($tool)) !== null) {
                // Retired staged alias: callable, but the catalog carries only
                // the canonical capability (with a staged mode grant).
                $this->assertNotContains($tool, $writeNames, "{$tool} is a retired staged alias");
                $this->assertContains($canonical, $writeNames);

                continue;
            }
            $this->assertContains($tool, $writeNames, "{$tool} should be in the sensitive CIPP write group");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
        }

        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (self::PR_ONE_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scopedTools = collect($this->listTools($this->token([
            'cipp_disable_user_sign_in',
            'cipp_stage_disable_user_sign_in',
            'cipp_assign_user_license',
        ])))->keyBy('name');

        $disable = $scopedTools['cipp_disable_user_sign_in'];
        $this->assertContains('client_id', $disable['inputSchema']['required']);
        $this->assertArrayHasKey('person_id', $disable['inputSchema']['properties']);
        $this->assertArrayNotHasKey('tenantFilter', $disable['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $disable['inputSchema']['properties']);
        $this->assertArrayNotHasKey('userPrincipalName', $disable['inputSchema']['properties']);
        $this->assertStringContainsString('blocks sign-in', $disable['description']);
        $this->assertStringContainsString('Requires an explicit token grant', $disable['description']);

        // Unified surface: the staged alias is no longer advertised — the
        // canonical tool carries a `staged` parameter instead. Granting the
        // alias plus the bare name resolves to the immediate mode grant.
        $this->assertFalse($scopedTools->has('cipp_stage_disable_user_sign_in'));
        $this->assertArrayHasKey('staged', $disable['inputSchema']['properties']);
        $this->assertStringContainsString('cockpit approval', $disable['description']);
        $this->assertStringContainsString(
            'Required when staged=true',
            $disable['inputSchema']['properties']['ticket_id']['description'] ?? '',
        );

        $assign = $scopedTools['cipp_assign_user_license'];
        $this->assertStringContainsString('human-smoke-verify before first live grant', $assign['description']);
    }

    public function test_direct_disable_rejects_upstream_selectors_then_uses_server_derived_scope(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_disable_user_sign_in']);

        $blockedClient = Mockery::mock(CippRestWriteClient::class);
        $blockedClient->shouldNotReceive('setUserSignInState');
        $this->app->instance(CippRestWriteClient::class, $blockedClient);

        $rejected = $this->callTool($token, 'cipp_disable_user_sign_in', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'tenantFilter' => 'attacker.onmicrosoft.com',
            'ID' => 'attacker-user-id',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Verify caller cannot inject upstream tenant or user ids.',
        ]);

        $rejected->assertOk();
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_disable_user_sign_in',
            'result_status' => 'rejected',
            'client_id' => $fixture['client']->id,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('setUserSignInState')
            ->once()
            ->with('acme.onmicrosoft.com', 'user-123', false)
            ->andReturn(['Results' => [['ok' => true, 'raw' => 'not returned to MCP']]]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_disable_user_sign_in', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Disable sign-in after account compromise containment.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $this->assertTrue($result['success']);
        $this->assertSame('CIPP action executed.', $result['message']);
        $this->assertStringNotContainsString('not returned to MCP', (string) $response->json('result.content.0.text'));

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_disable_user_sign_in',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
        ]);

        $audit = McpAuditLog::where('tool_name', 'cipp_disable_user_sign_in')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('user-123', json_encode($audit->arguments));
        $this->assertStringNotContainsString('acme.onmicrosoft.com', json_encode($audit->arguments));
    }

    public function test_kill_switch_blocks_revoke_before_upstream_call(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        Setting::setValue('technician_kill_switch', '1');

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('revokeUserSessions');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($this->token(['cipp_revoke_user_sessions']), 'cipp_revoke_user_sessions', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Contain a suspected token theft.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('kill-switch engaged', (string) $response->json('result.content.0.text'));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_revoke_user_sessions',
            'result_status' => 'blocked',
            'client_id' => $fixture['client']->id,
        ]);
    }

    public function test_staged_remove_mfa_requires_ticket_encrypts_payload_and_revalidates_on_approval(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_remove_user_mfa_methods']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('removeUserMfaMethods');
        $this->app->instance(CippRestWriteClient::class, $client);

        $missingTicket = $this->callTool($token, 'cipp_stage_remove_user_mfa_methods', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User needs MFA reset after device replacement.',
        ]);
        $this->assertTrue((bool) $missingTicket->json('result.isError'));
        $this->assertStringContainsString('ticket_id is required', (string) $missingTicket->json('result.content.0.text'));

        $response = $this->callTool($token, 'cipp_stage_remove_user_mfa_methods', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User needs MFA reset after device replacement.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = $this->decodedResult($response);
        $run = TechnicianRun::findOrFail($result['run_id']);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_remove_user_mfa_methods', $run->action_type);
        $this->assertNotEmpty($run->proposed_meta['encrypted_payload'] ?? null);
        $storedMeta = json_encode($run->proposed_meta);
        $this->assertStringNotContainsString('user-123', $storedMeta);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $storedMeta);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('removeUserMfaMethods')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example')
            ->andReturn(['Results' => [['ok' => true]]]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_remove_user_mfa_methods',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_license_assign_remove_uses_resolved_sku_and_suppresses_replace_all_shapes(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_assign_user_license', 'cipp_remove_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('assignUserLicense')
            ->once()
            ->with('acme.onmicrosoft.com', 'user-123', 'sku-from-tenant-sync')
            ->andReturn(['Results' => [['ok' => true]]]);
        $client->shouldReceive('removeUserLicense')
            ->once()
            ->with('acme.onmicrosoft.com', 'user-123', 'sku-from-tenant-sync')
            ->andReturn(['Results' => [['ok' => true]]]);
        $this->app->instance(CippRestWriteClient::class, $client);

        foreach (['cipp_assign_user_license', 'cipp_remove_user_license'] as $tool) {
            $response = $this->callTool($token, $tool, [
                'client_id' => $fixture['client']->id,
                'person_id' => $fixture['person']->id,
                'license_type_id' => $fixture['licenseType']->id,
                'skuId' => 'attacker-sku',
                'replaceAllLicenses' => true,
                'confirm_upn' => 'alex@acme.example',
                'reason' => 'Exercise license write selector rejection.',
            ]);

            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $response->json('result.content.0.text'));
        }

        foreach (['cipp_assign_user_license', 'cipp_remove_user_license'] as $tool) {
            $response = $this->callTool($token, $tool, [
                'client_id' => $fixture['client']->id,
                'person_id' => $fixture['person']->id,
                'license_type_id' => $fixture['licenseType']->id,
                'confirm_upn' => 'alex@acme.example',
                'reason' => 'Change one user license after human review.',
            ]);

            $response->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        }

        $this->assertSame(2, TechnicianActionLog::whereIn('action_type', ['cipp_assign_user_license', 'cipp_remove_user_license'])
            ->where('result_status', 'executed')
            ->count());
    }
}
