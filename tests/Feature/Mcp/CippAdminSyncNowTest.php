<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Cipp\CippContactSyncService;
use App\Services\Cipp\CippSyncOutcome;
use App\Services\SyncResult;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class CippAdminSyncNowTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_sync_people_now';

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

    private function mappedClient(): Client
    {
        return Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.test',
        ]);
    }

    /**
     * Bind a CippContactSyncService double.
     *
     * @param  CippSyncOutcome  $outcome  what syncClientContacts() reports — only Synced
     *                                    means the tenant was actually read.
     * @return object{count: int} call counter, live for the test's duration
     */
    private function mockSync(CippSyncOutcome $outcome, int $created = 0, int $updated = 0): object
    {
        $calls = new class
        {
            public int $count = 0;
        };

        $mock = Mockery::mock(CippContactSyncService::class);
        $mock->shouldReceive('syncClientContacts')
            ->andReturnUsing(function (Client $client, SyncResult $result, bool $dryRun = false) use ($outcome, $created, $updated, $calls): CippSyncOutcome {
                $calls->count++;
                if ($outcome === CippSyncOutcome::Synced) {
                    $result->created += $created;
                    $result->updated += $updated;
                }

                return $outcome;
            });
        $this->app->instance(CippContactSyncService::class, $mock);

        return $calls;
    }

    /**
     * A sync that commits people and THEN throws — the partial-write case (the real
     * service persists each person inside the loop, before stale cleanup and enrichment).
     *
     * @return object{count: int} call counter, live for the test's duration
     */
    private function mockSyncThrowingAfter(int $created): object
    {
        $calls = new class
        {
            public int $count = 0;
        };

        $mock = Mockery::mock(CippContactSyncService::class);
        $mock->shouldReceive('syncClientContacts')
            ->andReturnUsing(function (Client $client, SyncResult $result, bool $dryRun = false) use ($created, $calls): CippSyncOutcome {
                $calls->count++;
                $result->created += $created;

                throw new \RuntimeException('deadlock during stale cleanup');
            });
        $this->app->instance(CippContactSyncService::class, $mock);

        return $calls;
    }

    public function test_tool_is_registered_client_scoped_and_grant_gated(): void
    {
        $this->configureCipp();

        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames());
        $this->assertSame('cipp', McpToolRegistry::integrationForToolName(self::TOOL));

        $granted = $this->listTools($this->token([self::TOOL]));
        $names = array_column($granted, 'name');
        $this->assertContains(self::TOOL, $names);

        $definition = collect($granted)->firstWhere('name', self::TOOL);
        $this->assertArrayHasKey('client_id', $definition['inputSchema']['properties']);
        $this->assertContains('client_id', $definition['inputSchema']['required']);
        $this->assertContains('reason', $definition['inputSchema']['required']);

        // Ungranted token must not see it.
        $ungranted = array_column($this->listTools($this->token(['list_clients'])), 'name');
        $this->assertNotContains(self::TOOL, $ungranted);
    }

    public function test_client_id_is_required(): void
    {
        $this->configureCipp();
        $this->configureAiActor();

        $response = $this->callTool($this->token([self::TOOL]), self::TOOL, ['reason' => 'ticket 42']);

        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('client_id is required', (string) $response->json('result.content.0.text'));
    }

    public function test_reason_is_required(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
        ]));

        $this->assertSame('reason is required', $result['error'] ?? null);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'result_status' => 'rejected',
        ]);
    }

    public function test_client_without_tenant_mapping_is_refused(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = Client::factory()->create(['name' => 'Unmapped', 'cipp_tenant_domain' => null]);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'ticket 42',
        ]));

        $this->assertStringContainsString('no CIPP tenant mapping', (string) ($result['error'] ?? ''));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'rejected',
        ]);
    }

    public function test_successful_sync_returns_counts_and_audits_executed(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSync(CippSyncOutcome::Synced, created: 2, updated: 1);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'new hire mailbox created this morning',
        ]));

        $this->assertTrue($result['success'] ?? false);
        $this->assertTrue($result['synced'] ?? false);
        $this->assertSame(2, $result['created'] ?? null);
        $this->assertSame(1, $result['updated'] ?? null);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'executed',
        ]);
    }

    /**
     * The distinction this tool exists to protect: a sync that never ran because
     * another pass holds the lock must NOT come back looking like a completed sync
     * that found nothing. Red-checks against a version that returns the untouched
     * SyncResult ("no changes") for the skipped case.
     */
    public function test_sync_already_in_flight_reports_in_flight_not_no_changes(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSync(CippSyncOutcome::SkippedLocked);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'checking for the new mailbox',
        ]));

        $this->assertTrue($result['in_flight'] ?? false);
        $this->assertFalse($result['synced'] ?? true);
        $this->assertFalse($result['success'] ?? true);
        $this->assertStringNotContainsString('no changes', json_encode($result));

        // A skipped run is not an executed one — it must not start a cooldown either.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'blocked',
        ]);
        $this->assertDatabaseMissing('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_cooldown_blocks_a_second_run_inside_the_window(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSync(CippSyncOutcome::Synced, created: 1);

        $token = $this->token([self::TOOL]);
        $first = $this->decodedResult($this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'first pass',
        ]));
        $this->assertTrue($first['success'] ?? false);

        $second = $this->decodedResult($this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'immediately again',
        ]));

        $this->assertStringContainsString('cooldown active', (string) ($second['error'] ?? ''));
        $this->assertSame(1, TechnicianActionLog::where('action_type', self::TOOL)
            ->where('result_status', 'executed')->count());
    }

    /**
     * The considered departure from the Tactical sync-now shape: there is no 24-hour
     * idempotent short-circuit. Two on-demand syncs in the same working day must BOTH
     * run — answering the second with {idempotent: true} and no sync would reinstate
     * exactly the invisible-mailbox problem the tool was built to remove.
     */
    public function test_second_sync_later_the_same_day_runs_again(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $calls = $this->mockSync(CippSyncOutcome::Synced, created: 1);

        $token = $this->token([self::TOOL]);
        $this->callTool($token, self::TOOL, ['client_id' => $client->id, 'reason' => 'morning pass']);

        $this->travel(31)->minutes();

        $second = $this->decodedResult($this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'afternoon: new mailbox reported',
        ]));

        $this->assertTrue($second['success'] ?? false, 'a same-day repeat sync must actually run');
        $this->assertArrayNotHasKey('idempotent', $second);
        $this->assertSame(2, $calls->count, 'the upstream sync must be called both times');
        $this->assertSame(2, TechnicianActionLog::where('action_type', self::TOOL)
            ->where('result_status', 'executed')->count());
    }

    /**
     * OFF=OFF. With the integration switched on but not configured, the tool is not
     * live, so it never reaches the executor: it is absent from tools/list and the
     * call is refused. Publication and dispatch answer the same predicate (psa-wzjzz),
     * so this asserts the refusal, not one particular refusal string.
     */
    public function test_tool_is_not_live_when_cipp_is_not_configured(): void
    {
        Setting::setValue('cipp_enabled', '1');
        $this->configureAiActor();
        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.onmicrosoft.test']);
        $calls = $this->mockSync(CippSyncOutcome::Synced, created: 1);

        $token = $this->token([self::TOOL]);
        $this->assertNotContains(self::TOOL, array_column($this->listTools($token), 'name'));

        $response = $this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'ticket 42',
        ]);

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame(0, $calls->count, 'an unconfigured CIPP must never reach the sync service');
    }

    /**
     * A read that came back with nothing is not a completed sync. CIPP answering with an
     * empty user list (throttled, degraded, a tenant filter that matched nothing) must
     * never be reported as success/"no changes" — that is the nothing-found-versus-
     * nothing-read ambiguity this tool exists to remove.
     */
    public function test_degraded_upstream_read_is_not_reported_as_a_completed_sync(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSync(CippSyncOutcome::NoUsersRead);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'looking for the new mailbox',
        ]));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertFalse($result['synced'] ?? true);
        $this->assertFalse($result['roster_verified'] ?? true);
        $this->assertStringNotContainsString('no changes', json_encode($result));
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'error',
        ]);
    }

    /** A roster the group filter could not verify is reported the same way, not as success. */
    public function test_unverified_roster_is_not_reported_as_a_completed_sync(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSync(CippSyncOutcome::RosterUnverified);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'roster check',
        ]));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertFalse($result['synced'] ?? true);
        $this->assertFalse($result['roster_verified'] ?? true);
        $this->assertStringContainsString('group filter', (string) ($result['error'] ?? ''));
    }

    /**
     * The cooldown is what bounds upstream cost, so a FAILING sync must arm it too — an
     * agent retry loop against a degraded tenant is the case it exists for.
     */
    public function test_a_failed_sync_arms_the_cooldown(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $calls = $this->mockSyncThrowingAfter(created: 0);

        $token = $this->token([self::TOOL]);
        $this->callTool($token, self::TOOL, ['client_id' => $client->id, 'reason' => 'first attempt']);

        $second = $this->decodedResult($this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'retry immediately',
        ]));

        $this->assertStringContainsString('cooldown active', (string) ($second['error'] ?? ''));
        $this->assertSame(1, $calls->count, 'the retry must not reach CIPP');
    }

    /** A degraded read arms it too — same upstream call, same cost to bound. */
    public function test_a_degraded_read_arms_the_cooldown(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $calls = $this->mockSync(CippSyncOutcome::NoUsersRead);

        $token = $this->token([self::TOOL]);
        $this->callTool($token, self::TOOL, ['client_id' => $client->id, 'reason' => 'first attempt']);

        $second = $this->decodedResult($this->callTool($token, self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'retry immediately',
        ]));

        $this->assertStringContainsString('cooldown active', (string) ($second['error'] ?? ''));
        $this->assertSame(1, $calls->count, 'the retry must not reach CIPP');
    }

    /**
     * People are committed as the sync goes, so a late throw leaves rows written. The
     * tool must not claim nothing changed, and the audit row must carry the counts.
     */
    public function test_partial_sync_failure_reports_what_was_written(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = $this->mappedClient();
        $this->mockSyncThrowingAfter(created: 12);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'refresh after new hire',
        ]));

        $this->assertStringNotContainsString('no people were changed', json_encode($result));
        $this->assertSame(12, $result['created'] ?? null);
        $this->assertTrue($result['partial'] ?? false);
        $this->assertFalse($result['synced'] ?? true);

        $log = TechnicianActionLog::where('action_type', self::TOOL)
            ->where('result_status', 'error')->firstOrFail();
        $this->assertStringContainsString('12 created', (string) $log->summary);
    }

    /**
     * Scope parity with the scheduled pass, which selects operational() clients only: an
     * on-demand call must not resurrect a roster the nightly sync stopped maintaining.
     */
    public function test_non_operational_client_is_refused(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $client = Client::factory()->create([
            'name' => 'Offboarded',
            'cipp_tenant_domain' => 'offboarded.onmicrosoft.test',
            'is_active' => false,
        ]);
        $calls = $this->mockSync(CippSyncOutcome::Synced, created: 1);

        $result = $this->decodedResult($this->callTool($this->token([self::TOOL]), self::TOOL, [
            'client_id' => $client->id,
            'reason' => 'agent asked for a refresh',
        ]));

        $this->assertStringContainsString('not operational', (string) ($result['error'] ?? ''));
        $this->assertSame(0, $calls->count, 'a non-operational client must never reach the sync service');
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => self::TOOL,
            'client_id' => $client->id,
            'result_status' => 'rejected',
        ]);
    }
}
