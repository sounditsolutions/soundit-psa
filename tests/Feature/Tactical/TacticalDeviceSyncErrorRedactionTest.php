<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * A per-agent write failure must not carry the row we were writing into
 * operator-facing output.
 *
 * The per-agent catch in syncDevices() used to interpolate $e->getMessage()
 * straight into SyncResult::$errorMessages and into the warning log line. For a
 * QueryException that message contains the statement AND its bindings, and the
 * bindings are the asset: hostname, serial, logged-in username, LAN address.
 * SyncResult::$errorMessages is not a private diagnostic — the integrations
 * settings view renders it, StaffTacticalAdminToolExecutor returns it over MCP,
 * and it is written to storage/logs/laravel.log, which has no rotation.
 *
 * These tests force a genuine QueryException (the assets table is gone by the
 * time the sync tries to write) and assert on what escapes.
 *
 * DRIVER CONSTRAINT — every case here that forces its failure with Schema::drop()
 * is sqlite-only by construction, and that is now MORE than the write guards: the
 * client-map, queued-action pre-scan and not-seen-sweep cases added for psa #359
 * and #380 force their failures the same way. Do not read this note as an
 * enumeration of two; read it as "any case calling skipUnlessSqlite()". The failure
 * is forced with Schema::drop(), and DDL is not transactional on MariaDB (which
 * is what prod runs): it implicitly commits, so RefreshDatabase can no longer
 * roll the case back and the damage escapes into later tests. syncDevices()
 * upserts tactical_assets via updateOrCreate(), so a pre-seeded row updates
 * rather than violating the unique index — there is no DDL-free way to fail
 * that same statement. The helper therefore skips off sqlite rather than
 * corrupting the run. A green CI here is NOT cross-driver proof of the
 * redaction; it is proof on sqlite only.
 */
class TacticalDeviceSyncErrorRedactionTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $agents */
    private function syncService(array $agents): TacticalDeviceSyncService
    {
        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode($agents))])),
            'timeout' => 30,
        ]);

        return new TacticalDeviceSyncService(new TacticalClient($http));
    }

    /** @return array<string, mixed> */
    private function agent(): array
    {
        return [
            'agent_id' => 'AGENT-REDACT',
            'hostname' => 'ACME-RECEPTION-01',
            'client_name' => 'Acme',
            'site_name' => 'Main',
            'status' => 'online',
            'operating_system' => 'Windows 11 Pro, 64 bit',
            'plat' => 'windows',
            'monitoring_type' => 'workstation',
            'serial_number' => 'SN-CONFIDENTIAL-9',
            'cpu_model' => ['Intel Core i7-9700'],
            'physical_disks' => ['SAMSUNG SSD 500GB'],
            'local_ips' => '192.168.0.42',
            'logged_username' => 'rwhitfield',
            'last_seen' => '2026-07-29 12:00:00',
            'needs_reboot' => true,
        ];
    }

    /** The client-identifying fields that must never reach an error string. */
    private const LEAKY = [
        'ACME-RECEPTION-01',
        'SN-CONFIDENTIAL-9',
        'rwhitfield',
        '192.168.0.42',
    ];

    private function syncWithBrokenAssetWrites(): \App\Services\SyncResult
    {
        // See the class docblock: the Schema::drop() below implicitly commits on
        // MariaDB and would defeat RefreshDatabase's rollback for the whole run.
        $this->skipUnlessSqlite();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);

        // Guarantees a real QueryException from inside the per-agent try block,
        // with the agent payload as the statement's bindings.
        Schema::drop('assets');

        return $this->syncService([$this->agent()])->syncDevices();
    }

    public function test_a_write_failure_is_recorded_without_the_client_row(): void
    {
        $result = $this->syncWithBrokenAssetWrites();

        $this->assertSame(1, $result->errors, 'the agent should have been recorded as failed');
        $message = $result->errorMessages[0] ?? '';

        foreach (self::LEAKY as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $message,
                "client device data ({$secret}) reached SyncResult::errorMessages"
            );
        }

        $this->assertStringNotContainsString('insert into', strtolower($message), 'the statement itself leaked');
    }

    public function test_the_recorded_error_still_identifies_the_agent_and_the_failure(): void
    {
        $result = $this->syncWithBrokenAssetWrites();
        $message = $result->errorMessages[0] ?? '';

        $this->assertStringContainsString('AGENT-REDACT', $message, 'the failing agent must stay identifiable');
        $this->assertStringContainsString('database write failed', $message, 'the operator needs the failure class');
    }

    public function test_the_warning_log_line_does_not_carry_the_client_row(): void
    {
        Log::spy();

        $this->syncWithBrokenAssetWrites();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                if ($message !== '[TacticalSync] Agent skipped after a write failure') {
                    return false;
                }

                $rendered = json_encode($context);
                foreach (self::LEAKY as $secret) {
                    if (str_contains($rendered, $secret)) {
                        return false;
                    }
                }

                return ($context['agent_id'] ?? null) === 'AGENT-REDACT';
            })
            ->once();
    }

    /**
     * The other two catches that recordError() into SyncResult::$errorMessages.
     *
     * SCOPE OF THESE GUARDS, stated because the name could imply more: they
     * substitute TacticalClient wholesale and hand-construct the exception, so
     * TacticalClient::resolveAndPin() never runs. They prove that safeFailure()
     * withholds an exception message on these two paths. They prove NOTHING
     * about the SSRF pin's own behaviour, and in particular they cannot observe
     * that resolveAndPin() writes the host and the resolved IP to the
     * application log before it throws — a surface this branch does not close.
     * Exercising the real pin here, and the pin's log line, are both ticketed.
     */
    private const LEAKY_HOST = 'rmm.internal.example';

    private const LEAKY_ADDRESS = '10.20.30.40';

    /** The shape TacticalClient::resolveAndPin() throws, reproduced by hand. */
    private function pinRefusal(): TacticalClientException
    {
        return new TacticalClientException(
            "Tactical API host '".self::LEAKY_HOST."' resolved to a private or reserved address (".self::LEAKY_ADDRESS.'); refused.'
        );
    }

    private function syncWithFailingFetch(): \App\Services\SyncResult
    {
        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);

        $client = Mockery::mock(TacticalClient::class);
        $client->shouldReceive('getAgents')->once()->andThrow($this->pinRefusal());

        return (new TacticalDeviceSyncService($client))->syncDevices();
    }

    public function test_a_fetch_failure_publishes_no_exception_message(): void
    {
        $result = $this->syncWithFailingFetch();

        $this->assertSame(1, $result->errors, 'the fetch failure should have been recorded');
        $message = $result->errorMessages[0] ?? '';

        $this->assertStringNotContainsString(self::LEAKY_HOST, $message, 'the Tactical host reached SyncResult::errorMessages');
        $this->assertStringNotContainsString(self::LEAKY_ADDRESS, $message, 'a resolved address reached SyncResult::errorMessages');
        $this->assertStringContainsString('Failed to fetch agents', $message, 'the operator needs to know which step failed');
        $this->assertStringContainsString('TacticalClientException', $message, 'the operator needs the failure class');
    }

    public function test_the_fetch_warning_log_line_publishes_no_exception_message(): void
    {
        Log::spy();

        $this->syncWithFailingFetch();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                if ($message !== '[TacticalSync] Failed to fetch agents') {
                    return false;
                }

                $rendered = json_encode($context);

                return ! str_contains($rendered, self::LEAKY_HOST)
                    && ! str_contains($rendered, self::LEAKY_ADDRESS);
            })
            ->once();
    }

    private function syncWithFailingAssetLock(): \App\Services\SyncResult
    {
        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);

        // The lock KEY digests the hostname, so a cache-layer error quoting the
        // key it failed on leaks the hostname too.
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->andThrow(
            new \RuntimeException('Redis connection refused for key tactical-sync:asset:1:'.sha1(strtolower('ACME-RECEPTION-01')))
        );
        Cache::shouldReceive('lock')->andReturn($lock);

        return $this->syncService([$this->agent()])->syncDevices();
    }

    public function test_an_asset_lock_failure_publishes_neither_the_hostname_nor_the_message(): void
    {
        $result = $this->syncWithFailingAssetLock();

        $this->assertSame(1, $result->errors, 'the lock failure should have been recorded');
        $message = $result->errorMessages[0] ?? '';

        foreach (self::LEAKY as $secret) {
            $this->assertStringNotContainsString($secret, $message, "client device data ({$secret}) reached SyncResult::errorMessages");
        }

        $this->assertStringNotContainsString(
            sha1(strtolower('ACME-RECEPTION-01')),
            $message,
            'the lock key digests the hostname and must not be published either'
        );
        $this->assertStringContainsString('AGENT-REDACT', $message, 'the failing agent must stay identifiable');
        $this->assertStringContainsString('asset lock', $message, 'the operator needs to know which step failed');
    }

    // ---- psa #359: the two reads that sat ABOVE every guard ------------------
    //
    // safeFailure() was not incomplete from these; it was UNREACHABLE. A
    // QueryException from either left syncDevices() entirely, reached
    // IntegrationsController::syncTacticalDevices()'s catch (\Throwable) and was
    // interpolated raw into a flashed message. Same sqlite-only driver
    // constraint as the write guards above, and for the same reason.

    private function skipUnlessSqlite(): void
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->markTestSkipped("guard forces its failure with DDL and is sqlite-only; driver is {$driver}");
        }
    }

    public function test_a_client_map_read_failure_is_redacted_to_a_failure_class_and_does_not_escape(): void
    {
        $this->skipUnlessSqlite();

        // Fails the very first read in syncDevices(), above every existing catch.
        Schema::drop('clients');

        // The pre-#359 behaviour was a QueryException thrown out of this call.
        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertSame(1, $result->errors, 'the failure must be recorded, not thrown');
        $message = $result->errorMessages[0] ?? '';

        $this->assertStringContainsString('client map read', $message, 'the operator needs to know which read failed');
        $this->assertStringContainsString('database client map read failed', $message);
        $this->assertStringNotContainsString('select', strtolower($message), 'the statement itself leaked');
        $this->assertStringNotContainsString('tactical_site_id', $message, 'schema text leaked');
    }

    /**
     * The LOG half, which the three guards above already pin and the guards added
     * here did not (psa #380 review, contract:4). SyncResult is not the only sink —
     * storage/logs/laravel.log has no rotation, and "improve the diagnostics" is
     * exactly the change that reopens a redaction on the log side while the
     * operator-facing assertion stays green.
     *
     * Each case pins ITS OWN warning line by name and requires exactly one match,
     * the same shape the three cases above use. An assertion that merely finds
     * SOME clean warning would pass while the line beside it leaked.
     *
     * @param  list<string>  $forbidden
     */
    private function assertWarningCarriesNoStatement(string $line, array $forbidden): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($line, $forbidden): bool {
                if ($message !== $line) {
                    return false;
                }

                $rendered = strtolower(json_encode($context));
                foreach ($forbidden as $term) {
                    if (str_contains($rendered, strtolower($term))) {
                        return false;
                    }
                }

                return true;
            })
            ->once();
    }

    public function test_the_client_map_warning_line_does_not_carry_the_statement(): void
    {
        $this->skipUnlessSqlite();
        Log::spy();

        Schema::drop('clients');
        $this->syncService([$this->agent()])->syncDevices();

        $this->assertWarningCarriesNoStatement(
            '[TacticalSync] Failed to read the client/site mapping',
            ['select ', 'tactical_site_id']
        );
    }

    public function test_the_prescan_warning_line_does_not_carry_the_statement(): void
    {
        $this->skipUnlessSqlite();
        Log::spy();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
        Schema::drop('technician_runs');
        $this->syncService([$this->agent()])->syncDevices();

        $this->assertWarningCarriesNoStatement(
            '[TacticalSync] Failed to pre-scan queued offline actions',
            ['select ', 'technician_runs']
        );
    }

    public function test_the_not_seen_sweep_warning_line_carries_neither_statement_nor_bindings(): void
    {
        $this->skipUnlessSqlite();
        Log::spy();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
        Schema::drop('tactical_assets');
        $this->syncService([$this->agent()])->syncDevices();

        // The sweep is the only WRITE of the three, so its bindings are the ones
        // that would carry row data if the message were ever restored.
        $this->assertWarningCarriesNoStatement(
            '[TacticalSync] Failed to sweep not-seen agents offline',
            array_merge(['update ', 'tactical_assets'], self::LEAKY)
        );
    }

    public function test_a_queued_action_prescan_failure_degrades_rather_than_aborting_the_sync(): void
    {
        $this->skipUnlessSqlite();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);

        // Fails only the pre-scan; the agent loop below it can still reconcile.
        Schema::drop('technician_runs');

        $result = $this->syncService([$this->agent()])->syncDevices();

        $message = implode(' | ', $result->errorMessages);
        $this->assertStringContainsString('queued-action pre-scan', $message, 'the degraded step must be named');
        $this->assertStringNotContainsString('select', strtolower($message), 'the statement itself leaked');

        // Degrade, do not abort: losing the pre-scan costs a deferred dispatch,
        // not the sync. A guard that returned early here would be a regression
        // dressed as a fix, so pin the agent still landing.
        $this->assertSame(1, $result->created + $result->updated, 'the agent should still have been reconciled');
    }

    /**
     * psa #380 blocking finding: the NOT-SEEN OFFLINE SWEEP is the third unguarded
     * DB operation in syncDevices(), it is a WRITE, and it is the one #359 was
     * actually reported against. It sits below the per-agent loop's own catch with
     * no try of its own, so a QueryException there escaped the method entirely and
     * was flashed with its statement and bindings.
     *
     * Guarding the two READS the ticket enumerated did not close it — which is the
     * lesson: enumerating the sites a ticket names cannot close a class whose sink
     * is unchanged.
     */
    public function test_a_not_seen_sweep_failure_is_redacted_and_does_not_escape_the_method(): void
    {
        $this->skipUnlessSqlite();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);

        // The sweep runs on a full sync after the per-agent loop. Dropping the table
        // it writes fails that statement; the per-agent write above it fails too,
        // which is why the assertions below read the SWEEP's own message rather than
        // the joined list — the per-agent line legitimately names its agent.
        Schema::drop('tactical_assets');

        // Pre-fix behaviour was an uncaught QueryException thrown out of this call.
        $result = $this->syncService([$this->agent()])->syncDevices();

        $sweep = '';
        foreach ($result->errorMessages as $line) {
            if (str_starts_with($line, 'Failed to sweep not-seen agents offline')) {
                $sweep = $line;
            }
        }

        $this->assertNotSame('', $sweep, 'the sweep failure was not recorded at all');

        // THE BOUND VALUES FIRST, because that is what #359 is about (psa #380
        // review, diff:3) and the first failing assertion is the one a reader sees.
        // The sweep is the one statement here whose bindings carry real row data:
        // whereNotIn('agent_id', $seenAgentIds) binds every agent observed this run,
        // so an unredacted message publishes the fleet's agent ids.
        $this->assertStringNotContainsString('AGENT-REDACT', $sweep, 'a bound agent id leaked');

        foreach (self::LEAKY as $secret) {
            $this->assertStringNotContainsString($secret, $sweep, "client device data ({$secret}) leaked");
        }

        $this->assertStringNotContainsString('update', strtolower($sweep), 'the statement itself leaked');
        $this->assertStringNotContainsString('tactical_assets', $sweep, 'schema text leaked');
    }
}
