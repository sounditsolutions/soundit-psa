<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
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

/**
 * PR-2 mailbox tools (convert / forwarding / GAL / out-of-office).
 *
 * The INTERNAL forwarding target is deliberately NOT gated on is_active:
 * shared/resource mailboxes sync as inactive (disabled backing account) and
 * are mainstream forwarding targets (psa-pgnj product decision; the
 * recipient-type-aware guard is tracked separately as psa-24db). Owner and
 * target may both be inactive — pinned below.
 */
class CippWriteMailboxPr2Test extends TestCase
{
    use RefreshDatabase;

    private const PR_TWO_TOOLS = [
        'cipp_convert_mailbox',
        'cipp_stage_convert_mailbox',
        'cipp_set_mailbox_forwarding',
        'cipp_stage_set_mailbox_forwarding',
        'cipp_set_mailbox_gal_visibility',
        'cipp_stage_set_mailbox_gal_visibility',
        'cipp_set_mailbox_out_of_office',
        'cipp_stage_set_mailbox_out_of_office',
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

    /** @return array{client: Client, person: Person, target: Person, ticket: Ticket} */
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

        $target = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Jordan',
            'last_name' => 'Acme',
            'email' => 'target@acme.example',
            'cipp_user_id' => 'target-456',
            'cipp_upn' => 'target@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Mailbox administration',
        ]);

        return compact('client', 'person', 'target', 'ticket');
    }

    public function test_pr_two_mailbox_tools_are_sensitive_explicit_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('cipp_write', $groups);
        $this->assertTrue($groups['cipp_write']['sensitive']);

        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        foreach (self::PR_TWO_TOOLS as $tool) {
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
        foreach (self::PR_TWO_TOOLS as $tool) {
            $this->assertNotContains($tool, $legacyNames, "legacy full-surface token must not gain {$tool}");
        }

        $scopedTools = collect($this->listTools($this->token(self::PR_TWO_TOOLS)))->keyBy('name');

        $convert = $scopedTools['cipp_convert_mailbox'];
        $this->assertContains('client_id', $convert['inputSchema']['required']);
        $this->assertArrayHasKey('person_id', $convert['inputSchema']['properties']);
        $this->assertArrayNotHasKey('tenantFilter', $convert['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $convert['inputSchema']['properties']);
        $this->assertStringContainsString('Shared mailbox conversion can change licensing obligations', $convert['description']);

        // Unified surface: one forwarding tool with a `staged` parameter. The
        // staged-only capabilities (external mode + external_smtp) are folded
        // in and annotated; the server still holds external forwarding to the
        // staged path only.
        $this->assertFalse($scopedTools->has('cipp_stage_set_mailbox_forwarding'));
        $directForward = $scopedTools['cipp_set_mailbox_forwarding'];
        $this->assertArrayHasKey('staged', $directForward['inputSchema']['properties']);
        $this->assertSame(['disabled', 'internal', 'external'], $directForward['inputSchema']['properties']['mode']['enum']);
        $this->assertStringContainsString('Values [external] are only accepted when staged=true', $directForward['inputSchema']['properties']['mode']['description']);
        $this->assertArrayHasKey('external_smtp', $directForward['inputSchema']['properties']);
        $this->assertStringContainsString('Only used when staged=true', $directForward['inputSchema']['properties']['external_smtp']['description']);
        $this->assertStringContainsString('External SMTP forwarding is held-only', $directForward['description']);

        $ooo = $scopedTools['cipp_set_mailbox_out_of_office'];
        $this->assertSame(['Disabled', 'Enabled', 'Scheduled'], $ooo['inputSchema']['properties']['state']['enum']);
        $this->assertArrayNotHasKey('CreateOOFEvent', $ooo['inputSchema']['properties']);
        $this->assertArrayNotHasKey('AutoDeclineFutureRequestsWhenOOF', $ooo['inputSchema']['properties']);
        $this->assertStringContainsString('message lengths only', $ooo['description']);
    }

    public function test_direct_mailbox_actions_use_server_derived_scope_and_sanitize_audits(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token([
            'cipp_convert_mailbox',
            'cipp_set_mailbox_forwarding',
            'cipp_set_mailbox_gal_visibility',
            'cipp_set_mailbox_out_of_office',
        ]);

        $blockedClient = Mockery::mock(CippRestWriteClient::class);
        $blockedClient->shouldNotReceive('convertMailbox');
        $this->app->instance(CippRestWriteClient::class, $blockedClient);

        $rejected = $this->callTool($token, 'cipp_convert_mailbox', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'tenantFilter' => 'attacker.onmicrosoft.com',
            'ID' => 'attacker@evil.example',
            'mailbox_type' => 'Shared',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reject caller-supplied upstream mailbox identity.',
        ]);
        $this->assertTrue((bool) $rejected->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $rejected->json('result.content.0.text'));

        $directExternal = $this->callTool($token, 'cipp_set_mailbox_forwarding', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'mode' => 'external',
            'external_smtp' => 'forward@example.net',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'External forwards must not be direct.',
        ]);
        $this->assertTrue((bool) $directExternal->json('result.isError'));
        $this->assertStringContainsString('External SMTP forwarding is held-only', (string) $directExternal->json('result.content.0.text'));

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('convertMailbox')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'Shared')
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('setMailboxForwardingInternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('setMailboxGalVisibility')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $client->shouldReceive('setMailboxOutOfOffice')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'Enabled', 'Internal responder body', 'External responder body', null, null, 'UTC')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $client);

        foreach ([
            ['cipp_convert_mailbox', [
                'mailbox_type' => 'Shared',
                'reason' => 'Convert to shared mailbox after user departure; licensing impact reviewed.',
            ]],
            ['cipp_set_mailbox_forwarding', [
                'mode' => 'internal',
                'target_person_id' => $fixture['target']->id,
                'keep_copy' => true,
                'reason' => 'Forward mailbox internally during coverage window.',
            ]],
            ['cipp_set_mailbox_gal_visibility', [
                'hidden' => true,
                'reason' => 'Hide departed mailbox from address lists.',
            ]],
            ['cipp_set_mailbox_out_of_office', [
                'state' => 'Enabled',
                'internal_message' => 'Internal responder body',
                'external_message' => 'External responder body',
                'timezone' => 'UTC',
                'reason' => 'Set temporary out-of-office responder.',
            ]],
        ] as [$tool, $extra]) {
            $response = $this->callTool($token, $tool, array_merge([
                'client_id' => $fixture['client']->id,
                'person_id' => $fixture['person']->id,
                'ticket_id' => $fixture['ticket']->id,
                'confirm_upn' => 'alex@acme.example',
            ], $extra));

            $response->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
            $this->assertSame('CIPP action executed.', $this->decodedResult($response)['message']);
        }

        $this->assertSame(4, TechnicianActionLog::whereIn('action_type', [
            'cipp_convert_mailbox',
            'cipp_set_mailbox_forwarding',
            'cipp_set_mailbox_gal_visibility',
            'cipp_set_mailbox_out_of_office',
        ])->where('result_status', 'executed')->count());

        $auditPayload = json_encode(McpAuditLog::where('tool_name', 'cipp_set_mailbox_out_of_office')->latest('id')->firstOrFail()->arguments);
        $this->assertStringNotContainsString('Internal responder body', $auditPayload);
        $this->assertStringNotContainsString('External responder body', $auditPayload);
        $this->assertStringContainsString('internal_message_length', $auditPayload);
        $this->assertStringContainsString('external_message_length', $auditPayload);
    }

    public function test_external_forwarding_is_held_only_and_reentered_at_approval_without_storing_address(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('setMailboxForwardingExternal');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mode' => 'external',
            'external_smtp' => 'forward@example.net',
            'keep_copy' => false,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Temporary external legal mailbox forwarding to forward@example.net approved by manager.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('cipp_stage_set_mailbox_forwarding', $run->action_type);
        $stored = json_encode($run->proposed_meta)."\n".$run->proposed_content;
        $this->assertStringNotContainsString('forward@example.net', $stored);
        $this->assertStringNotContainsString('forward@', $stored);
        $this->assertStringContainsString('example.net', $stored);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxForwardingExternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'forward@example.net', false)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run), ['external_smtp' => 'forward@example.net'])
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_stage_set_mailbox_forwarding',
            'result_status' => 'executed',
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'run_id' => $run->id,
            'approver_user_id' => $actor->id,
        ]);

        $this->assertStringNotContainsString('forward@example.net', TechnicianActionLog::latest('id')->firstOrFail()->summary);
        $this->assertStringNotContainsString('forward@example.net', json_encode(McpAuditLog::where('tool_name', 'cipp_stage_set_mailbox_forwarding')->latest('id')->firstOrFail()->arguments));
    }

    public function test_internal_forwarding_still_executes_when_the_target_is_inactive(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        // Shared/resource mailboxes have disabled backing accounts, so contact
        // sync stores them as is_active = false — yet forwarding a departed
        // user's mail into a team shared mailbox is a mainstream offboarding
        // flow. An inactive target therefore stages AND executes (psa-pgnj
        // product decision; the type-aware guard is psa-24db).
        $fixture['target']->update(['is_active' => false]);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mode' => 'internal',
            'target_person_id' => $fixture['target']->id,
            'keep_copy' => true,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Forward the departed user\'s mail into the team shared mailbox.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxForwardingInternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_internal_forwarding_still_executes_when_the_mailbox_owner_is_inactive(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        // Mid-offboarding the mailbox OWNER is often already deactivated
        // (contact sync mirrors accountEnabled). Only the RECIPIENT must be
        // active — forwarding the departed user's mail to an active colleague
        // is exactly the coverage flow this tool exists for.
        $fixture['person']->update(['is_active' => false]);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mode' => 'internal',
            'target_person_id' => $fixture['target']->id,
            'keep_copy' => false,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Offboarding coverage: forward the departed user\'s mail to a colleague.',
        ]);
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxForwardingInternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', false)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_staged_out_of_office_reenters_messages_at_approval_without_storing_bodies(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_out_of_office']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('setMailboxOutOfOffice');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_stage_set_mailbox_out_of_office', [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'state' => 'Scheduled',
            'internal_message' => 'Internal staged body must not persist',
            'external_message' => 'External staged body must not persist',
            'start_time' => '2026-07-04T09:00:00Z',
            'end_time' => '2026-07-05T17:00:00Z',
            'timezone' => 'UTC',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Set scheduled holiday auto-replies; Internal staged body must not persist.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $run = TechnicianRun::findOrFail($this->decodedResult($response)['run_id']);

        $stored = json_encode($run->proposed_meta)."\n".$run->proposed_content;
        $this->assertStringNotContainsString('Internal staged body must not persist', $stored);
        $this->assertStringNotContainsString('External staged body must not persist', $stored);
        $this->assertStringContainsString('internal_message_length', $stored);
        $this->assertStringContainsString('external_message_length', $stored);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxOutOfOffice')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'Scheduled', 'Approved internal message', 'Approved external message', '2026-07-04T09:00:00Z', '2026-07-05T17:00:00Z', 'UTC')
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run), [
                'internal_message' => 'Approved internal message',
                'external_message' => 'Approved external message',
            ])
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $auditPayload = json_encode(McpAuditLog::where('tool_name', 'cipp_stage_set_mailbox_out_of_office')->latest('id')->firstOrFail()->arguments);
        $this->assertStringNotContainsString('Internal staged body must not persist', $auditPayload);
        $this->assertStringNotContainsString('External staged body must not persist', $auditPayload);
        $this->assertStringContainsString('internal_message_length', $auditPayload);
        $this->assertStringContainsString('external_message_length', $auditPayload);
    }
}
