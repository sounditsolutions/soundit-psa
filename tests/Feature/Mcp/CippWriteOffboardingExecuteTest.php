<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
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

/**
 * Offboarding EXECUTE tools (bead psa-zjpd): the destructive half of the
 * offboarding flow — (1) Intune device WIPE/retire (cipp_wipe_device) and
 * (2) OneDrive ownership reassignment to a named successor
 * (cipp_reassign_onedrive). Both follow the psa-5qrd held-only pattern: the
 * params gate throws on every non-held path, so no grant mode — including
 * :immediate — can reach the upstream call without a cockpit approval.
 *
 * Strictest-gate extras (build brief): the wipe readout names the exact device
 * (id + hostname) and action; the approver must type the Intune device id at
 * approval; approval re-resolves the device and declines on identity drift;
 * and a re-fired approval of an already-executed wipe is a logged no-op —
 * never a second upstream wipe.
 */
class CippWriteOffboardingExecuteTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_ID = 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c';

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

    /** @return array{client: Client, person: Person, successor: Person, asset: Asset, ticket: Ticket} */
    private function cippFixture(string $prefix = 'acme'): array
    {
        $client = Client::factory()->create([
            'name' => ucfirst($prefix),
            'cipp_tenant_domain' => $prefix.'.onmicrosoft.com',
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => ucfirst($prefix),
            'email' => 'alex@'.$prefix.'.example',
            'cipp_user_id' => 'user-'.$prefix,
            'cipp_upn' => 'alex@'.$prefix.'.example',
            'is_active' => true,
        ]);

        $successor = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Sam',
            'last_name' => ucfirst($prefix),
            'email' => 'sam@'.$prefix.'.example',
            'cipp_user_id' => 'successor-'.$prefix,
            'cipp_upn' => 'sam@'.$prefix.'.example',
            'is_active' => true,
        ]);

        // m365_device_id is DB-unique, so non-default fixtures derive their own GUID.
        $hash = md5($prefix);
        $deviceId = $prefix === 'acme' ? self::DEVICE_ID : sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12),
        );

        $asset = Asset::factory()->for($client)->create([
            'name' => 'Finance laptop',
            'hostname' => 'FIN-LT-042',
            'm365_device_id' => $deviceId,
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Offboarding: execute device wipe and OneDrive handover',
        ]);

        return compact('client', 'person', 'successor', 'asset', 'ticket');
    }

    /** @return array<string, mixed> */
    private function wipeArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'asset_id' => $fixture['asset']->id,
            'wipe_action' => 'wipe',
            'confirm_hostname' => 'FIN-LT-042',
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => $fixture['person']->cipp_upn,
            'reason' => 'Offboarding: factory-reset the departed user\'s laptop before redeployment.',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function reassignArguments(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'successor_person_id' => $fixture['successor']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => $fixture['person']->cipp_upn,
            'reason' => 'Offboarding: hand the departed user\'s OneDrive to their manager.',
        ], $overrides);
    }

    private function blockedClient(): Mockery\MockInterface
    {
        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('wipeDevice');
        $blocked->shouldNotReceive('reassignOneDriveOwnership');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        return $blocked;
    }

    public function test_offboarding_execute_tools_are_sensitive_explicit_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        foreach ([
            'cipp_stage_wipe_device' => 'cipp_wipe_device',
            'cipp_stage_reassign_onedrive' => 'cipp_reassign_onedrive',
        ] as $alias => $canonical) {
            $this->assertSame($canonical, McpToolModes::canonicalForAlias($alias));
            $this->assertNotContains($alias, $writeNames);
            $this->assertContains($canonical, $writeNames);
            $this->assertContains($canonical, McpToolRegistry::allToolNames());
        }

        // A legacy full-surface token must not silently gain the new destructive tools.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        foreach (['cipp_wipe_device', 'cipp_stage_wipe_device', 'cipp_reassign_onedrive', 'cipp_stage_reassign_onedrive'] as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        $scoped = collect($this->listTools($this->token(['cipp_wipe_device', 'cipp_reassign_onedrive'])))->keyBy('name');
        $this->assertFalse($scoped->has('cipp_stage_wipe_device'));
        $this->assertFalse($scoped->has('cipp_stage_reassign_onedrive'));

        $wipe = $scoped['cipp_wipe_device'];
        $this->assertContains('client_id', $wipe['inputSchema']['required']);
        foreach (['person_id', 'asset_id', 'wipe_action', 'confirm_hostname', 'confirm_upn', 'reason'] as $req) {
            $this->assertContains($req, $wipe['inputSchema']['required']);
        }
        // No upstream CIPP/Graph identities are ever accepted from the caller.
        foreach (['tenantFilter', 'GUID', 'Action', 'ID', 'm365_device_id', 'device_id'] as $key) {
            $this->assertArrayNotHasKey($key, $wipe['inputSchema']['properties']);
        }
        $this->assertArrayHasKey('staged', $wipe['inputSchema']['properties']);
        $this->assertStringContainsStringIgnoringCase('held-only', $wipe['description']);
        $this->assertStringContainsStringIgnoringCase('irreversible', $wipe['description']);

        $reassign = $scoped['cipp_reassign_onedrive'];
        foreach (['person_id', 'successor_person_id', 'confirm_upn', 'reason'] as $req) {
            $this->assertContains($req, $reassign['inputSchema']['required']);
        }
        foreach (['UPN', 'onedriveAccessUser', 'RemovePermission', 'URL', 'tenantFilter'] as $key) {
            $this->assertArrayNotHasKey($key, $reassign['inputSchema']['properties']);
        }
        $this->assertArrayHasKey('staged', $reassign['inputSchema']['properties']);
        $this->assertStringContainsStringIgnoringCase('held-only', $reassign['description']);

        // A staged-only token sees the staged variant's schema: ticket_id required.
        $stagedScoped = collect($this->listTools($this->token(['cipp_stage_wipe_device', 'cipp_stage_reassign_onedrive'])))->keyBy('name');
        $this->assertContains('ticket_id', $stagedScoped['cipp_wipe_device']['inputSchema']['required']);
        $this->assertContains('ticket_id', $stagedScoped['cipp_reassign_onedrive']['inputSchema']['required']);
    }

    public function test_direct_execution_is_structurally_refused_even_with_immediate_grant(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        // Bare grants = legacy immediate mode: the mode gate would allow
        // staged=false, so the refusal must come from the executor itself.
        $token = $this->token(['cipp_wipe_device', 'cipp_reassign_onedrive']);
        $this->blockedClient();

        foreach ([
            'cipp_wipe_device' => $this->wipeArguments($fixture),
            'cipp_reassign_onedrive' => $this->reassignArguments($fixture),
        ] as $tool => $arguments) {
            $response = $this->callTool($token, $tool, $arguments);

            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), $tool);
            $this->assertStringContainsStringIgnoringCase('held-only', (string) $response->json('result.content.0.text'), $tool);
            $this->assertStringContainsString('staged=true', (string) $response->json('result.content.0.text'), $tool);

            $this->assertDatabaseHas('technician_action_logs', [
                'action_type' => $tool,
                'result_status' => 'rejected',
                'client_id' => $fixture['client']->id,
            ]);
        }

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_staged_grant_auto_downgrades_immediate_calls_to_a_staged_proposal(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        // Alias grant = staged-only mode; staged=false must downgrade, not fail.
        $token = $this->token(['cipp_stage_wipe_device']);
        $this->blockedClient();

        $response = $this->callTool($token, 'cipp_wipe_device', $this->wipeArguments($fixture, ['staged' => false]));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_wipe_device', $run->action_type);
    }

    public function test_staged_wipe_holds_then_typed_device_id_approval_executes(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_wipe_device']);
        $this->blockedClient();

        // Stage with a lowercased hostname confirmation to prove case-insensitivity.
        $response = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'confirm_hostname' => 'fin-lt-042',
        ]));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_wipe_device', $run->action_type);

        // The readout makes the blast radius unmistakable: exact device (id +
        // hostname), the action, and the irreversibility warning.
        $this->assertStringContainsString(self::DEVICE_ID, $run->proposed_content);
        $this->assertStringContainsString('FIN-LT-042', $run->proposed_content);
        $this->assertStringContainsStringIgnoringCase('irreversible', $run->proposed_content);
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);

        // The stored proposal keeps only safe local scalars (PSA asset id, the
        // action, the server-derived device id snapshot); the hostname lives in
        // the display only, and the approver must re-type the device id.
        $stored = json_encode($run->proposed_meta);
        $this->assertStringContainsString(self::DEVICE_ID, $stored);
        $this->assertStringContainsString('"wipe_action":"wipe"', $stored);
        $this->assertStringNotContainsString('FIN-LT-042', $stored);
        $this->assertContains('confirm_device_id', $run->proposed_meta['sensitive_inputs']);

        // Approving without the typed device id fails validation and executes nothing.
        $this->actingAs($actor)
            ->from(route('cockpit.index'))
            ->post(route('cockpit.approve', $run))
            ->assertSessionHasErrors('confirm_device_id');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        // Approval re-resolves the device server-side and executes with the
        // server-derived identity; the typed id is case-insensitive.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('wipeDevice')
            ->once()
            ->with('acme.onmicrosoft.com', self::DEVICE_ID, 'wipe')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run), ['confirm_device_id' => strtoupper(self::DEVICE_ID)])
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_wipe_device',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);

        // The audit summary carries PSA ids + the device GUID (id-only) so the
        // executed-dedup guard can key on it — never the hostname or tenant.
        $summary = TechnicianActionLog::where('action_type', 'cipp_stage_wipe_device')
            ->where('result_status', 'executed')->latest('id')->firstOrFail()->summary;
        $this->assertStringContainsString('person #'.$fixture['person']->id, $summary);
        $this->assertStringContainsString('device '.self::DEVICE_ID.' (wipe)', $summary);
        $this->assertStringNotContainsString('FIN-LT-042', $summary);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $summary);
    }

    public function test_wipe_approval_declines_on_wrong_device_id_identity_drift_or_lost_mapping(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();

        $scenarios = [
            'typed_id_mismatch' => [
                'mutate' => fn (Asset $asset) => null,
                'confirm_device_id' => 'ffffffff-0000-0000-0000-000000000000',
            ],
            // The asset was re-enrolled between staging and approval: the id the
            // approver saw in the readout no longer names the current device.
            'identity_drift' => [
                'mutate' => fn (Asset $asset) => $asset->update(['m365_device_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']),
                'confirm_device_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            ],
            'mapping_lost' => [
                'mutate' => fn (Asset $asset) => $asset->update(['m365_device_id' => null]),
                'confirm_device_id' => self::DEVICE_ID,
            ],
        ];

        $prefixes = ['typed_id_mismatch' => 'alpha', 'identity_drift' => 'bravo', 'mapping_lost' => 'carol'];
        foreach ($scenarios as $label => $scenario) {
            $fixture = $this->cippFixture($prefixes[$label]);
            $token = $this->token(['cipp_stage_wipe_device']);
            $this->blockedClient();

            $response = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
            $this->assertFalse((bool) $response->json('result.isError'), $label.': '.(string) $response->json('result.content.0.text'));
            $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

            $scenario['mutate']($fixture['asset']);

            $approveClient = Mockery::mock(CippRestWriteClient::class);
            $approveClient->shouldNotReceive('wipeDevice');
            $this->app->instance(CippRestWriteClient::class, $approveClient);

            $this->actingAs($actor)->post(route('cockpit.approve', $run), [
                'confirm_device_id' => $scenario['confirm_device_id'],
            ]);

            // Gate declined: the run returns to the queue, an error row is audited,
            // and the upstream wipe was never called.
            $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, $label);
            $this->assertDatabaseHas('technician_action_logs', [
                'action_type' => 'cipp_stage_wipe_device',
                'result_status' => 'error',
                'run_id' => $run->id,
            ]);
        }
    }

    public function test_wipe_approval_declines_while_kill_switch_is_engaged(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_wipe_device']);
        $this->blockedClient();

        $response = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        Setting::setValue('technician_kill_switch', '1');

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldNotReceive('wipeDevice');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)->post(route('cockpit.approve', $run), ['confirm_device_id' => self::DEVICE_ID]);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_wipe_device',
            'result_status' => 'blocked',
            'run_id' => $run->id,
        ]);
    }

    public function test_refired_wipe_approval_is_a_logged_noop_never_a_second_wipe(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_wipe_device']);

        // First wipe: stage and approve normally.
        $this->blockedClient();
        $first = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
        $runA = TechnicianRun::findOrFail($this->decodedResult($first)['run_id']);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('wipeDevice')
            ->once()
            ->with('acme.onmicrosoft.com', self::DEVICE_ID, 'wipe')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);
        $this->actingAs($actor)->post(route('cockpit.approve', $runA), ['confirm_device_id' => self::DEVICE_ID]);
        $this->assertSame(TechnicianRunState::Done, $runA->fresh()->state);

        // Re-staging the identical wipe is idempotent — no new proposal.
        $this->blockedClient();
        $restage = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
        $decoded = $this->decodedResult($restage);
        $this->assertTrue((bool) ($decoded['idempotent'] ?? false));
        $this->assertSame(1, TechnicianRun::count());

        // A sibling proposal for the SAME device from a different ticket can still
        // be staged (different content hash) — but approving it must be a logged
        // no-op, not a second upstream wipe.
        $ticketB = Ticket::factory()->for($fixture['client'])->create([
            'contact_id' => $fixture['person']->id,
            'subject' => 'Duplicate offboarding request',
        ]);
        $second = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, ['ticket_id' => $ticketB->id]));
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));
        $runB = TechnicianRun::findOrFail($this->decodedResult($second)['run_id']);
        $this->assertNotSame($runA->id, $runB->id);

        $noopClient = Mockery::mock(CippRestWriteClient::class);
        $noopClient->shouldNotReceive('wipeDevice');
        $this->app->instance(CippRestWriteClient::class, $noopClient);

        $this->actingAs($actor)->post(route('cockpit.approve', $runB), ['confirm_device_id' => self::DEVICE_ID]);

        // Terminal no-op: the duplicate leaves the queue for good and the audit
        // trail records the suppression against the approving operator.
        $this->assertSame(TechnicianRunState::Done, $runB->fresh()->state);
        $noop = TechnicianActionLog::where('action_type', 'cipp_stage_wipe_device')
            ->where('result_status', 'blocked')
            ->where('run_id', $runB->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringContainsString('no-op', $noop->summary);
        $this->assertStringContainsString('device '.self::DEVICE_ID, $noop->summary);
        $this->assertSame($actor->id, $noop->approver_user_id);

        // A DIFFERENT action on the same device is not suppressed: after the
        // proposal cooldown clears, a retire stages and executes normally.
        $this->travel(301)->seconds();
        $this->blockedClient();
        $retire = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'ticket_id' => $ticketB->id,
            'wipe_action' => 'retire',
        ]));
        $this->assertFalse((bool) $retire->json('result.isError'), (string) $retire->json('result.content.0.text'));
        $runC = TechnicianRun::findOrFail($this->decodedResult($retire)['run_id']);

        $retireClient = Mockery::mock(CippRestWriteClient::class);
        $retireClient->shouldReceive('wipeDevice')
            ->once()
            ->with('acme.onmicrosoft.com', self::DEVICE_ID, 'retire')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $retireClient);

        $this->actingAs($actor)->post(route('cockpit.approve', $runC), ['confirm_device_id' => self::DEVICE_ID]);
        $this->assertSame(TechnicianRunState::Done, $runC->fresh()->state);
    }

    public function test_staged_onedrive_reassign_holds_then_approval_reresolves_and_executes(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_reassign_onedrive']);
        $this->blockedClient();

        $response = $this->callTool($token, 'cipp_stage_reassign_onedrive', $this->reassignArguments($fixture));

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_reassign_onedrive', $run->action_type);

        // The approver must see WHO gains ownership of WHOSE OneDrive.
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);
        $this->assertStringContainsString('sam@acme.example', $run->proposed_content);

        // The stored payload keeps only local PSA identifiers — the successor UPN
        // is re-derived server-side at approval, never persisted.
        $stored = json_encode($run->proposed_meta);
        $this->assertStringContainsString('"successor_person_id":'.$fixture['successor']->id, $stored);
        $this->assertStringNotContainsString('sam@acme.example', $stored);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('reassignOneDriveOwnership')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'sam@acme.example')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_reassign_onedrive',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);

        // Audit stays id-only: PSA person ids, no UPNs, no tenant domain.
        $summary = TechnicianActionLog::where('action_type', 'cipp_stage_reassign_onedrive')
            ->where('result_status', 'executed')->latest('id')->firstOrFail()->summary;
        $this->assertStringContainsString('person #'.$fixture['person']->id, $summary);
        $this->assertStringNotContainsString('sam@acme.example', $summary);
        $this->assertStringNotContainsString('acme.onmicrosoft.com', $summary);
    }

    public function test_onedrive_reassign_rejects_self_cross_client_and_unmapped_successors(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_reassign_onedrive']);
        $this->blockedClient();

        // Self-succession is meaningless for an offboarding handover.
        $self = $this->callTool($token, 'cipp_stage_reassign_onedrive', $this->reassignArguments($fixture, [
            'successor_person_id' => $fixture['person']->id,
        ]));
        $this->assertTrue((bool) $self->json('result.isError'));
        $this->assertStringContainsString('different person', (string) $self->json('result.content.0.text'));

        // A successor from another client can never be resolved into this scope.
        $other = $this->cippFixture('rival');
        $cross = $this->callTool($token, 'cipp_stage_reassign_onedrive', $this->reassignArguments($fixture, [
            'successor_person_id' => $other['person']->id,
        ]));
        $this->assertTrue((bool) $cross->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $cross->json('result.content.0.text'));

        // A successor without a CIPP mapping has no upstream identity to grant.
        $unmapped = Person::create([
            'client_id' => $fixture['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Unmapped',
            'email' => 'pat@acme.example',
            'is_active' => true,
        ]);
        $noMapping = $this->callTool($token, 'cipp_stage_reassign_onedrive', $this->reassignArguments($fixture, [
            'successor_person_id' => $unmapped->id,
        ]));
        $this->assertTrue((bool) $noMapping->json('result.isError'));
        $this->assertStringContainsString('no CIPP user mapping', (string) $noMapping->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_rejects_bad_inputs_and_caller_supplied_upstream_identifiers(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_wipe_device', 'cipp_stage_reassign_onedrive']);
        $this->blockedClient();

        // wipe_action is a closed allowlist — no delete, no arbitrary Graph actions.
        $badAction = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, ['wipe_action' => 'delete']));
        $this->assertTrue((bool) $badAction->json('result.isError'));
        $this->assertStringContainsString('wipe_action', (string) $badAction->json('result.content.0.text'));

        // The typed hostname confirmation must match the resolved asset.
        foreach (['FIN-LT-999', null] as $typed) {
            $arguments = $this->wipeArguments($fixture);
            if ($typed === null) {
                unset($arguments['confirm_hostname']);
            } else {
                $arguments['confirm_hostname'] = $typed;
            }
            $badHostname = $this->callTool($token, 'cipp_stage_wipe_device', $arguments);
            $this->assertTrue((bool) $badHostname->json('result.isError'));
            $this->assertStringContainsString('confirm_hostname', (string) $badHostname->json('result.content.0.text'));
        }

        // The asset must belong to the caller's client, be active, and carry an
        // Intune mapping.
        $other = $this->cippFixture('rival');
        $crossAsset = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'asset_id' => $other['asset']->id,
        ]));
        $this->assertTrue((bool) $crossAsset->json('result.isError'));
        $this->assertStringContainsString('different client', (string) $crossAsset->json('result.content.0.text'));

        $unmanaged = Asset::factory()->for($fixture['client'])->create([
            'hostname' => 'FIN-LT-777',
            'm365_device_id' => null,
            'is_active' => true,
        ]);
        $noMapping = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'asset_id' => $unmanaged->id,
            'confirm_hostname' => 'FIN-LT-777',
        ]));
        $this->assertTrue((bool) $noMapping->json('result.isError'));
        $this->assertStringContainsString('Intune', (string) $noMapping->json('result.content.0.text'));

        $inactive = Asset::factory()->for($fixture['client'])->create([
            'hostname' => 'FIN-LT-888',
            'm365_device_id' => 'cccccccc-dddd-4eee-8fff-000000000001',
            'is_active' => false,
        ]);
        $retired = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'asset_id' => $inactive->id,
            'confirm_hostname' => 'FIN-LT-888',
        ]));
        $this->assertTrue((bool) $retired->json('result.isError'));
        $this->assertStringContainsString('not active', (string) $retired->json('result.content.0.text'));

        // The upstream endpoints' own body keys are never accepted from the caller.
        foreach ([
            'cipp_stage_wipe_device' => ['GUID' => self::DEVICE_ID, 'Action' => 'wipe', 'm365_device_id' => self::DEVICE_ID, 'device_id' => self::DEVICE_ID],
            'cipp_stage_reassign_onedrive' => ['UPN' => 'alex@acme.example', 'onedriveAccessUser' => ['value' => 'attacker@evil.example'], 'RemovePermission' => true, 'URL' => 'https://evil.example'],
        ] as $tool => $keys) {
            $baseArguments = $tool === 'cipp_stage_wipe_device' ? $this->wipeArguments($fixture) : $this->reassignArguments($fixture);
            foreach ($keys as $key => $value) {
                $rejected = $this->callTool($token, $tool, array_merge($baseArguments, [$key => $value]));
                $this->assertTrue((bool) $rejected->json('result.isError'), $tool.' / '.$key);
                $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'), $tool.' / '.$key);
            }
        }

        // confirm_upn must match the resolved offboarded user.
        $mismatch = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture, [
            'confirm_upn' => 'someone-else@acme.example',
        ]));
        $this->assertTrue((bool) $mismatch->json('result.isError'));
        $this->assertStringContainsString('confirm_upn does not match', (string) $mismatch->json('result.content.0.text'));

        $this->assertSame(0, TechnicianRun::count());
    }

    public function test_staged_wipe_is_idempotent_while_awaiting_approval(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_wipe_device']);
        $this->blockedClient();

        $first = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $runId = $this->decodedResult($first)['run_id'];

        $second = $this->callTool($token, 'cipp_stage_wipe_device', $this->wipeArguments($fixture));
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));
        $decoded = $this->decodedResult($second);
        $this->assertTrue((bool) ($decoded['idempotent'] ?? false));
        $this->assertSame($runId, $decoded['run_id']);
        $this->assertSame(1, TechnicianRun::count());
    }
}
