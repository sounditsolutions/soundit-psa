<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
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
 * Email-security remediation writes (bead psa-t08l): quarantine RELEASE and
 * tenant ALLOW-list ADD, each one capability with a staged twin. Neither
 * targets a mapped person — the quarantine release is gated by a server-side
 * verification read against the resolved tenant's live quarantine listing,
 * and the allow entry is a validated caller value pinned to one tenant with
 * listMethod=Allow and 45-days-after-last-use expiry pinned server-side.
 */
class CippWriteEmailSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTITY = 'aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888';

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

    /** @return array{client: Client, contact: Person, ticket: Ticket} */
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
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'subject' => 'Legit vendor invoice stuck in quarantine',
        ]);

        return compact('client', 'contact', 'ticket');
    }

    /** @return array<string, mixed> */
    private function quarantineRow(array $overrides = []): array
    {
        return array_merge([
            'Identity' => self::IDENTITY,
            'SenderAddress' => 'billing@vendor.example',
            'RecipientAddress' => ['alex@acme.example'],
            'Subject' => 'Vendor invoice 4321',
            'ReceivedTime' => '2026-07-11T15:04:05Z',
            'ReleaseStatus' => 'NOTRELEASED',
            'QuarantineTypes' => 'HighConfPhish',
        ], $overrides);
    }

    public function test_email_security_tools_are_sensitive_explicit_grant_only_and_schemas_are_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        $this->assertTrue($groups['cipp_write']['sensitive']);

        foreach ([
            'cipp_release_quarantine_message' => 'cipp_stage_release_quarantine_message',
            'cipp_add_tenant_allow_entry' => 'cipp_stage_add_tenant_allow_entry',
        ] as $canonical => $alias) {
            $this->assertSame($canonical, McpToolModes::canonicalForAlias($alias));
            $this->assertContains($canonical, $writeNames);
            $this->assertNotContains($alias, $writeNames);
            $this->assertContains($canonical, McpToolRegistry::allToolNames());
        }

        // A legacy full-surface token must not silently gain the new sensitive tools.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains('cipp_release_quarantine_message', $legacyNames);
        $this->assertNotContains('cipp_add_tenant_allow_entry', $legacyNames);

        $scoped = collect($this->listTools($this->token([
            'cipp_release_quarantine_message',
            'cipp_add_tenant_allow_entry',
        ])))->keyBy('name');

        $release = $scoped['cipp_release_quarantine_message'];
        foreach (['client_id', 'quarantine_identity', 'confirm_sender', 'reason'] as $required) {
            $this->assertContains($required, $release['inputSchema']['required']);
        }
        // No upstream endpoint keys are ever accepted from the caller.
        foreach (['tenantFilter', 'tenantID', 'Identity', 'Type', 'AllowSender', 'SenderAddress', 'PolicyName'] as $key) {
            $this->assertArrayNotHasKey($key, $release['inputSchema']['properties']);
        }
        $this->assertArrayHasKey('staged', $release['inputSchema']['properties']);
        $this->assertStringContainsString('Supports staged=true', $release['description']);

        $allow = $scoped['cipp_add_tenant_allow_entry'];
        foreach (['client_id', 'list_type', 'entry', 'confirm_entry', 'reason'] as $required) {
            $this->assertContains($required, $allow['inputSchema']['required']);
        }
        foreach (['tenantFilter', 'tenantID', 'entries', 'listType', 'listMethod', 'NoExpiration', 'RemoveAfter', 'notes'] as $key) {
            $this->assertArrayNotHasKey($key, $allow['inputSchema']['properties']);
        }
        $this->assertSame(['Sender', 'Url'], $allow['inputSchema']['properties']['list_type']['enum']);
        $this->assertArrayHasKey('staged', $allow['inputSchema']['properties']);
    }

    public function test_direct_quarantine_release_is_gated_by_the_live_quarantine_listing(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_release_quarantine_message']);

        // Caller-supplied upstream endpoint keys are rejected before anything else.
        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('releaseQuarantineMessage');
        $blocked->shouldNotReceive('listMailQuarantine');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $smuggled = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'Identity' => 'attacker-supplied',
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Reject upstream identifier smuggling.',
        ]);
        $this->assertTrue((bool) $smuggled->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $smuggled->json('result.content.0.text'));

        // A malformed identity never reaches the verification read.
        $malformed = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => 'DELETE FROM quarantine',
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Malformed identity.',
        ]);
        $this->assertTrue((bool) $malformed->json('result.isError'));
        // Decoded (the raw content text JSON-escapes the backslash in GUID\GUID).
        $this->assertStringContainsString('GUID\\GUID Identity', (string) $this->decodedResult($malformed)['error']);

        // An identity that is not in the tenant's live quarantine is refused.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listMailQuarantine')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->quarantineRow(['Identity' => 'cccccccc-1111-2222-3333-444444444444\dddddddd-5555-6666-7777-888888888888'])]);
        $client->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $client);

        $notFound = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Identity not present in tenant quarantine.',
        ]);
        $this->assertTrue((bool) $notFound->json('result.isError'));
        $this->assertStringContainsString('not found in this client tenant', (string) $notFound->json('result.content.0.text'));

        // confirm_sender must match the VERIFIED row's sender, not the caller's claim.
        $mismatchClient = Mockery::mock(CippRestWriteClient::class);
        $mismatchClient->shouldReceive('listMailQuarantine')->once()->andReturn([$this->quarantineRow()]);
        $mismatchClient->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $mismatchClient);

        $mismatch = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'confirm_sender' => 'someone-else@vendor.example',
            'reason' => 'Wrong sender confirmation.',
        ]);
        $this->assertTrue((bool) $mismatch->json('result.isError'));
        $this->assertStringContainsString('confirm_sender does not match', (string) $mismatch->json('result.content.0.text'));

        // Happy path: verified present -> released. Case-insensitive sender match.
        $happyClient = Mockery::mock(CippRestWriteClient::class);
        $happyClient->shouldReceive('listMailQuarantine')->once()->andReturn([$this->quarantineRow()]);
        $happyClient->shouldReceive('releaseQuarantineMessage')->once()
            ->with('acme.onmicrosoft.com', self::IDENTITY)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $happyClient);

        $released = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_sender' => 'Billing@Vendor.example',
            'reason' => 'Confirmed false positive: known vendor invoice.',
        ]);
        $this->assertFalse((bool) $released->json('result.isError'), (string) $released->json('result.content.0.text'));
        $this->assertSame('Quarantine release executed for all original recipients.', $this->decodedResult($released)['message']);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_release_quarantine_message',
            'result_status' => 'executed',
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
        ]);

        // An identical repeat dedups locally — no fresh verification read, no release.
        $dedupClient = Mockery::mock(CippRestWriteClient::class);
        $dedupClient->shouldNotReceive('listMailQuarantine');
        $dedupClient->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $dedupClient);

        $repeat = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Repeat of an already-executed release.',
        ]);
        $this->assertFalse((bool) $repeat->json('result.isError'));
        $this->assertTrue((bool) $this->decodedResult($repeat)['idempotent']);
    }

    public function test_direct_quarantine_release_short_circuits_an_already_released_message(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_release_quarantine_message']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listMailQuarantine')->once()
            ->andReturn([$this->quarantineRow(['ReleaseStatus' => 'RELEASED'])]);
        $client->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Already released by another path.',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'));
        $result = $this->decodedResult($response);
        $this->assertTrue((bool) $result['already_released']);
        $this->assertStringContainsString('already released upstream', $result['message']);
    }

    public function test_staged_quarantine_release_holds_server_verified_metadata_then_executes(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_release_quarantine_message']);

        // Staging performs the verification READ only — never the release.
        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldReceive('listMailQuarantine')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->quarantineRow()]);
        $stageClient->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Confirmed false positive; hold for approval.',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_release_quarantine_message', $run->action_type);

        // The approver sees the SERVER-captured facts, not the caller's claims.
        $this->assertStringContainsString('billing@vendor.example', $run->proposed_content);
        $this->assertStringContainsString('Vendor invoice 4321', $run->proposed_content);
        $this->assertStringContainsString(self::IDENTITY, $run->proposed_content);
        $this->assertStringContainsString('alex@acme.example', $run->proposed_content);

        // The stored meta stays identifier-only — no untrusted subject text at rest.
        $this->assertStringNotContainsString('Vendor invoice 4321', json_encode($run->proposed_meta));

        // Approval re-verifies against the live quarantine, then executes.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('listMailQuarantine')->once()
            ->with('acme.onmicrosoft.com')
            ->andReturn([$this->quarantineRow()]);
        $approveClient->shouldReceive('releaseQuarantineMessage')->once()
            ->with('acme.onmicrosoft.com', self::IDENTITY)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_release_quarantine_message',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }

    public function test_staged_quarantine_release_approval_declines_when_message_left_quarantine(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_release_quarantine_message']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldReceive('listMailQuarantine')->once()->andReturn([$this->quarantineRow()]);
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_release_quarantine_message', [
            'client_id' => $fixture['client']->id,
            'quarantine_identity' => self::IDENTITY,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_sender' => 'billing@vendor.example',
            'reason' => 'Hold for approval.',
        ]);
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        // Between staging and approval the message expired / was handled elsewhere.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('listMailQuarantine')->once()->andReturn([]);
        $approveClient->shouldNotReceive('releaseQuarantineMessage');
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $blocked = TechnicianActionLog::where('action_type', 'cipp_stage_release_quarantine_message')
            ->where('result_status', 'blocked')
            ->where('run_id', $run->id)
            ->firstOrFail();
        $this->assertStringContainsString('Approval refused', $blocked->summary);
    }

    public function test_direct_allow_entry_posts_sender_domain_and_url_variants(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_add_tenant_allow_entry']);
        $expectedTicketNotes = 'Added via '.config('app.name').' (ticket '.$fixture['ticket']->display_id.')';
        $expectedPlainNotes = 'Added via '.config('app.name');

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('addTenantAllowListEntry')->once()
            ->with('acme.onmicrosoft.com', 'Sender', 'billing@vendor.example', $expectedTicketNotes)
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('addTenantAllowListEntry')->once()
            ->with('acme.onmicrosoft.com', 'Sender', 'vendor.example', $expectedPlainNotes)
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('addTenantAllowListEntry')->once()
            ->with('acme.onmicrosoft.com', 'Url', '*.vendor.example/invoices/*', $expectedPlainNotes)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        foreach ([
            ['Sender', 'billing@vendor.example', $fixture['ticket']->id],
            ['Sender', 'vendor.example', null],
            ['Url', '*.vendor.example/invoices/*', null],
        ] as [$listType, $entry, $ticketId]) {
            $arguments = [
                'client_id' => $fixture['client']->id,
                'list_type' => $listType,
                'entry' => $entry,
                'confirm_entry' => $entry,
                'reason' => 'Confirmed false positive for this vendor.',
            ];
            if ($ticketId !== null) {
                $arguments['ticket_id'] = $ticketId;
            }

            $response = $this->callTool($token, 'cipp_add_tenant_allow_entry', $arguments);
            $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
            $this->assertStringContainsString('45 days', $this->decodedResult($response)['message']);
        }

        $this->assertSame(3, TechnicianActionLog::where('action_type', 'cipp_add_tenant_allow_entry')
            ->where('result_status', 'executed')->count());

        // The audit trail records the actual allowed value — it is tenant config, not a secret.
        $summaries = TechnicianActionLog::where('action_type', 'cipp_add_tenant_allow_entry')
            ->where('result_status', 'executed')->pluck('summary')->implode(' | ');
        $this->assertStringContainsString('billing@vendor.example', $summaries);
    }

    public function test_allow_entry_validation_rejects_unsafe_values_before_upstream(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_add_tenant_allow_entry']);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('addTenantAllowListEntry');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $base = fn (array $overrides): array => array_merge([
            'client_id' => $fixture['client']->id,
            'list_type' => 'Sender',
            'entry' => 'billing@vendor.example',
            'confirm_entry' => 'billing@vendor.example',
            'reason' => 'Validation case.',
        ], $overrides);

        foreach ([
            // Upstream endpoint keys are refused outright.
            [$base(['entries' => ['smuggled@evil.example']]), 'upstream CIPP identifiers are not accepted'],
            // FileHash (and anything but Sender/Url) is out of scope.
            [$base(['list_type' => 'FileHash', 'entry' => 'abc123', 'confirm_entry' => 'abc123']), 'list_type must be one of'],
            // Sender entries must be an address or bare domain — no wildcards.
            [$base(['entry' => '*.vendor.example', 'confirm_entry' => '*.vendor.example']), 'full email address or a bare domain'],
            [$base(['entry' => 'not-a-domain', 'confirm_entry' => 'not-a-domain']), 'full email address or a bare domain'],
            // Url entries must not carry a scheme.
            [$base(['list_type' => 'Url', 'entry' => 'https://vendor.example/x', 'confirm_entry' => 'https://vendor.example/x']), 'must not include a scheme'],
            // The typed confirmation must match exactly.
            [$base(['confirm_entry' => 'other@vendor.example']), 'confirm_entry does not match'],
        ] as [$arguments, $expectedError]) {
            $response = $this->callTool($token, 'cipp_add_tenant_allow_entry', $arguments);
            $this->assertTrue((bool) $response->json('result.isError'), 'Expected rejection: '.$expectedError);
            $this->assertStringContainsString($expectedError, (string) $response->json('result.content.0.text'));
        }
    }

    public function test_staged_allow_entry_holds_the_entry_verbatim_then_executes_on_approval(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_add_tenant_allow_entry']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('addTenantAllowListEntry');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $response = $this->callTool($token, 'cipp_stage_add_tenant_allow_entry', [
            'client_id' => $fixture['client']->id,
            'list_type' => 'Sender',
            'entry' => 'billing@vendor.example',
            'confirm_entry' => 'billing@vendor.example',
            'ticket_id' => $fixture['ticket']->id,
            'reason' => 'Recurring false positive; hold the tenant-wide allow for approval.',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // The approver must review the exact value and its tenant-wide blast radius.
        $this->assertStringContainsString('billing@vendor.example', $run->proposed_content);
        $this->assertStringContainsString('WHOLE tenant', $run->proposed_content);
        $this->assertStringContainsString('45 days', $run->proposed_content);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('addTenantAllowListEntry')->once()
            ->with(
                'acme.onmicrosoft.com',
                'Sender',
                'billing@vendor.example',
                'Added via '.config('app.name').' (ticket '.$fixture['ticket']->display_id.')',
            )
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_add_tenant_allow_entry',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);
    }
}
