<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class CippWritePasswordResetPr3Test extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_reset_user_password';

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

    /** @return array{client: Client, person: Person, ticket: Ticket} */
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
            'subject' => 'Password reset',
        ]);

        return compact('client', 'person', 'ticket');
    }

    private function mockReset(string $copyField, string $state = 'success'): Mockery\MockInterface
    {
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('resetUserPassword')
            ->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => [
                    'resultText' => "The new password is {$copyField}",
                    'copyField' => $copyField,
                    'state' => $state,
                ],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $client);

        return $client;
    }

    public function test_tool_is_sensitive_grant_only_and_schema_is_safe(): void
    {
        $this->configureCipp();

        $groups = McpToolRegistry::groups();
        $writeNames = array_column($groups['cipp_write']['tools'], 'name');
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertContains(self::TOOL, $writeNames, 'reset tool must be in the sensitive cipp_write group');
        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames(), 'reset tool must be token-grantable');

        // No staged twin exists.
        $this->assertNotContains('cipp_stage_reset_user_password', $writeNames);

        // Ungranted-by-default: a legacy full-surface token never gains it.
        $legacyNames = array_column($this->listTools($this->legacyToken()), 'name');
        $this->assertNotContains(self::TOOL, $legacyNames);

        $scoped = collect($this->listTools($this->token([self::TOOL])))->keyBy('name');
        $reset = $scoped[self::TOOL];
        $this->assertContains('client_id', $reset['inputSchema']['required']);
        $this->assertContains('person_id', $reset['inputSchema']['required']);
        $this->assertContains('confirm_upn', $reset['inputSchema']['required']);
        $this->assertContains('reason', $reset['inputSchema']['required']);
        $this->assertArrayHasKey('must_change', $reset['inputSchema']['properties']);
        $this->assertSame('boolean', $reset['inputSchema']['properties']['must_change']['type']);
        // Never exposes upstream identifiers.
        $this->assertArrayNotHasKey('tenantFilter', $reset['inputSchema']['properties']);
        $this->assertArrayNotHasKey('ID', $reset['inputSchema']['properties']);
    }

    public function test_ungranted_token_is_denied(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        // Token granted a DIFFERENT cipp_write tool, not the reset tool.
        $response = $this->callTool($this->token(['cipp_disable_user_sign_in']), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Attempt without grant.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
    }

    public function test_returns_temp_password_to_caller_and_never_persists_it(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $secret = 'S3cret-Temp-9x!';

        $client = $this->mockReset($secret);

        // Capture every Laravel log line emitted during the call.
        $logged = [];
        Log::listen(function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User forgot password; verified identity over the phone.',
        ]);

        // Mockery::shouldHaveReceived() verifies eagerly against calls recorded so
        // far, so it must run after the call it is checking, not before.
        $client->shouldHaveReceived('resetUserPassword')
            ->with('acme.onmicrosoft.com', 'alex@acme.example', true);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        // The temp password IS returned to the caller (that is the whole point).
        $result = $this->decodedResult($response);
        $this->assertSame($secret, $result['temporary_password']);
        $this->assertTrue($result['must_change_at_next_logon']);
        $this->assertFalse($result['ad_synced_warning']);
        $this->assertStringContainsString('Relay it', $result['message']);

        // An 'executed' audit row exists...
        $this->assertSame(1, TechnicianActionLog::where('action_type', self::TOOL)->where('result_status', 'executed')->count());

        // ...but the credential is in NO persistent sink and NO log line.
        $this->assertStringNotContainsString($secret, json_encode(TechnicianActionLog::all()->toArray()));
        $this->assertStringNotContainsString($secret, json_encode(McpAuditLog::all()->toArray()));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'temp password leaked into a log line');
        }
    }

    public function test_honors_must_change_false(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = $this->mockReset('pw-x');

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'must_change' => false,
            'reason' => 'Permanent reset for a shared service account.',
        ]);

        // Mockery::shouldHaveReceived() verifies eagerly against calls recorded so
        // far, so it must run after the call it is checking, not before.
        $client->shouldHaveReceived('resetUserPassword')
            ->with('acme.onmicrosoft.com', 'alex@acme.example', false);

        $response->assertOk();
        $this->assertFalse($this->decodedResult($response)['must_change_at_next_logon']);
    }

    public function test_surfaces_ad_sync_warning(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $this->mockReset('pw-y', state: 'warning');

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reset for hybrid-identity user.',
        ]);

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertTrue($result['ad_synced_warning']);
        $this->assertStringContainsString('AD-synced', $result['message']);
    }

    public function test_rejects_caller_supplied_upstream_identifiers(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'tenantFilter' => 'attacker.onmicrosoft.com',
            'ID' => 'attacker@evil.example',
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reject upstream identity injection.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', (string) $response->json('result.content.0.text'));
    }

    public function test_requires_confirm_upn_match(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'wrong@acme.example',
            'reason' => 'Confirm mismatch must cancel.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('confirm_upn', (string) $response->json('result.content.0.text'));
    }

    public function test_kill_switch_blocks_reset(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        Setting::setValue('technician_kill_switch', '1');
        $fixture = $this->cippFixture();

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Kill switch must refuse.',
        ]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('kill-switch', (string) $response->json('result.content.0.text'));
    }

    public function test_success_response_is_marked_no_store(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->mockReset('pw-nostore');

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reset; response must not be cached.',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_must_change_is_recorded_as_safe_boolean_in_audit(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $this->mockReset('pw-audit');

        $this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'must_change' => true,
            'reason' => 'Audit must record must_change.',
        ]);

        $args = McpAuditLog::where('tool_name', self::TOOL)->latest('id')->firstOrFail()->arguments;
        $this->assertTrue($args['must_change']);
        $this->assertSame('[withheld]', $args['confirm_upn']);
    }

    public function test_cooldown_blocks_a_second_reset_for_the_same_user(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('resetUserPassword')
            ->once() // ONLY the first attempt may reach upstream
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => ['copyField' => 'pw-cooldown-1', 'state' => 'success'],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $token = $this->token([self::TOOL]);
        $args = [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['person']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'First reset, then an immediate retry that must be refused.',
        ];

        $first = $this->callTool($token, self::TOOL, $args);
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $this->assertSame('pw-cooldown-1', $this->decodedResult($first)['temporary_password']);

        // Immediate second attempt for the same person is refused by the cooldown;
        // resetUserPassword must NOT be called a second time (Mockery ->once() enforces this).
        $second = $this->callTool($token, self::TOOL, $args);
        $this->assertTrue((bool) $second->json('result.isError'));
        $this->assertStringContainsString('cooldown', (string) $second->json('result.content.0.text'));
    }
}
