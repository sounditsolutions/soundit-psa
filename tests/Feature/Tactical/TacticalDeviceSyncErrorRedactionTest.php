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
use Illuminate\Log\Events\MessageLogged;
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

    // ---- log capture ---------------------------------------------------------
    //
    // These cases used to assert on the log with Log::spy() plus
    // shouldHaveReceived('warning')->withArgs($closure)->once(), and that shape
    // failed three ways (psa #380 review, context:5 / context:7 / diff:10):
    //
    //  - the closure was the ADJUDICATOR, so every outcome collapsed to Mockery's
    //    "warning was not called 1 times". A real leak, a renamed log line and an
    //    encoder that returned nothing were indistinguishable in the failure
    //    output — the reader is told the line was never logged, which is false;
    //  - json_encode() returns FALSE on invalid UTF-8, and a QueryException's
    //    bindings are precisely where invalid UTF-8 arrives from. str_contains()
    //    against the resulting '' found nothing, so the guard PASSED — vacuously,
    //    at the exact moment it could not see the context it was guarding;
    //  - the closure typed its first parameter `string`, so a warning logged with
    //    a non-string message raised a TypeError out of the matcher rather than
    //    failing the assertion.
    //
    // So: capture the real records, then adjudicate them with ordinary
    // assertions. Log::listen() sees what the logger actually received, the
    // matching is untyped, and each failure names itself.

    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    private function captureLogRecords(): void
    {
        $this->capturedLogs = [];

        Log::listen(function (MessageLogged $record): void {
            $this->capturedLogs[] = $record;
        });
    }

    /**
     * The one warning logged under $line, or a failure that says what was logged.
     *
     * Exactly one, deliberately: an assertion that merely found SOME clean
     * warning would pass while the line beside it leaked.
     *
     * @return array<mixed>
     */
    private function warningContext(string $line): array
    {
        $matches = [];
        $seen = [];

        foreach ($this->capturedLogs as $record) {
            if ($record->level !== 'warning') {
                continue;
            }

            // No type hint and no cast: a non-string message must fail this
            // assertion, not raise a TypeError on the way to it.
            $seen[] = is_string($record->message) ? $record->message : get_debug_type($record->message);

            if ($record->message === $line) {
                $matches[] = $record;
            }
        }

        $this->assertCount(
            1,
            $matches,
            "expected exactly one warning logged as '{$line}'; warnings actually logged: "
                .($seen === [] ? '(none)' : implode(' | ', $seen))
        );

        return is_array($matches[0]->context) ? $matches[0]->context : [$matches[0]->context];
    }

    /**
     * Render a log context for scanning, failing rather than passing when it
     * cannot be rendered.
     *
     * EVERY FLAG HERE IS LOAD-BEARING, and the one that is ABSENT most of all:
     *
     *  - JSON_INVALID_UTF8_SUBSTITUTE keeps the ASCII around a bad byte
     *    sequence visible, so a leaked hostname stays findable next to an
     *    unencodable binding instead of taking the whole render down with it.
     *  - JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE, because the
     *    forbidden terms are matched RAW against this string. Default encoding
     *    writes `/` as `\/` and non-ASCII as `\uXXXX`, so a leaked value
     *    carrying either would be invisible to str-contains matching. This repo
     *    has already paid for that once from the other direction —
     *    EndpointInsight's docblock, "§11.1: JSON escaping slips
     *    PEM/connection-strings past WikiRedactor".
     *  - NOT JSON_PARTIAL_OUTPUT_ON_ERROR. It was here, and it made the
     *    assertion below DEAD CODE (psa #380 review r3, diff:5, found by all
     *    three seats): that flag is precisely what stops json_encode returning
     *    false on recursion / INF / NAN / unsupported types — it substitutes
     *    null or 0 and returns a string with the offending subtree silently
     *    dropped. A helper whose whole purpose is to stop a guard passing
     *    without firing had its own guard unable to fire, and a docblock
     *    claiming the opposite.
     *
     * With it gone the assertion is reachable, and an unrenderable context is a
     * failed test rather than a scan over a document the secret was dropped
     * from.
     *
     * @param  array<mixed>  $context
     */
    private function renderContext(array $context): string
    {
        $rendered = json_encode(
            $context,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $this->assertIsString(
            $rendered,
            'the warning context could not be rendered for scanning ('.json_last_error_msg()
                .'), so this guard could not see what it was guarding'
        );

        return $rendered;
    }

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
        $this->captureLogRecords();

        $this->syncWithBrokenAssetWrites();

        $context = $this->assertWarningCarriesNoStatement(
            '[TacticalSync] Agent skipped after a write failure',
            self::LEAKY
        );

        $this->assertSame('AGENT-REDACT', $context['agent_id'] ?? null, 'the failing agent must stay identifiable');
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
        $this->captureLogRecords();

        $this->syncWithFailingFetch();

        $this->assertWarningCarriesNoStatement(
            '[TacticalSync] Failed to fetch agents',
            [self::LEAKY_HOST, self::LEAKY_ADDRESS]
        );
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

    // ---- psa #359: the operations that sat OUTSIDE every guard ---------------
    //
    // THREE, not two, and the header said two until the r3 review pointed at the
    // sweep cases filed directly beneath it (context:7). The ticket enumerated
    // two READS; the first gate on this branch found the third — the not-seen
    // offline SWEEP, a WRITE below the per-agent loop — and that is the one #359
    // was actually reported against. A count in a header is the same trap the
    // service's SCOPE docblock warns about, so this one names a property instead.
    //
    // safeFailure() was not incomplete from any of them; it was UNREACHABLE. A
    // QueryException left syncDevices() entirely, reached
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

    /** The last line syncDevices() logs, and therefore proof it reached its end. */
    private const COMPLETION_LINE = '[TacticalSync] Device sync complete';

    /** @return list<string> every message logged at any level, in order. */
    private function loggedMessages(): array
    {
        $out = [];

        foreach ($this->capturedLogs as $record) {
            $out[] = is_string($record->message) ? $record->message : get_debug_type($record->message);
        }

        return $out;
    }

    /**
     * The client map ABORTS; the pre-scan and the sweep DEGRADE. The three guards
     * are otherwise identical in shape, so nothing in the suite noticed which was
     * which — a change that swapped an abort for a degrade, or the reverse, stayed
     * green (psa #380 review, diff:8 and diff:9). The redaction cases above cannot
     * see it either: they assert on a message that both behaviours produce.
     *
     * ABORT is the correct behaviour here and it is only observable from outside
     * by what does NOT happen. Without the map there is nothing to match an agent
     * onto, so a run that carried on would still call Tactical, walk the whole
     * payload and `continue` past every agent — an outbound request and a full
     * pass, reported as a sync that reconciled nothing with one error beside it.
     *
     * THE "no clients mapped" ASSERTION IS THE LOAD-BEARING ONE, and it is here
     * because the first draft of this case was not: dropping the catch's
     * `return $result;` lands execution on the `empty($clientMap)` check directly
     * below, which returns too. Counters, error count and the completion line are
     * all identical, so the obvious pins stayed green through the exact mutation
     * they were written for. What does change is the reason the run gives for
     * stopping: it reports the operator's Tactical mapping as EMPTY — a
     * configuration problem they would go and look at — when the truth is that
     * the database read failed. Attribution is the observable, so pin that.
     */
    public function test_a_client_map_read_failure_aborts_the_sync_before_the_fetch(): void
    {
        $this->skipUnlessSqlite();
        $this->captureLogRecords();

        Schema::drop('clients');

        // Substituted rather than mocked at the HTTP layer: shouldNotReceive()
        // fails AT the call, naming it, instead of leaving the reader to infer an
        // abort from a counter that a degrade would also leave at zero.
        $client = Mockery::mock(TacticalClient::class);
        $client->shouldNotReceive('getAgents');

        $result = (new TacticalDeviceSyncService($client))->syncDevices();

        $this->assertSame(1, $result->errors, 'the failure must be recorded, not thrown');
        $this->assertCount(1, $result->errorMessages, 'an abort records its one failure and stops; more means it carried on');
        $this->assertSame(
            0,
            $result->created + $result->updated + $result->deactivated,
            'nothing may be reconciled once the client map is gone'
        );

        $this->assertNotContains(
            '[TacticalSync] No clients mapped to Tactical RMM sites',
            $this->loggedMessages(),
            'the client-map READ FAILURE was reported as an empty mapping — the guard fell through '
                .'to the empty-map path instead of aborting on its own terms'
        );

        $this->assertNotContains(
            self::COMPLETION_LINE,
            $this->loggedMessages(),
            'syncDevices() ran to its end instead of aborting on the client-map failure'
        );
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
     * @return array<mixed> the matched context, for any further pinning
     */
    private function assertWarningCarriesNoStatement(string $line, array $forbidden): array
    {
        $context = $this->warningContext($line);
        $rendered = $this->renderContext($context);

        foreach ($forbidden as $term) {
            $this->assertStringNotContainsString(
                strtolower($term),
                strtolower($rendered),
                "'{$term}' reached the context of '{$line}': {$rendered}"
            );
        }

        return $context;
    }

    public function test_the_client_map_warning_line_does_not_carry_the_statement(): void
    {
        $this->skipUnlessSqlite();
        $this->captureLogRecords();

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
        $this->captureLogRecords();

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
        $this->captureLogRecords();

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

    /**
     * The other half of diff:9 — the sweep DEGRADES where the client map aborts.
     *
     * The distinction is not cosmetic. The sweep is the last thing syncDevices()
     * does; returning early there would discard a run that has already reconciled
     * every agent, to save a status flag the next run recomputes anyway.
     *
     * WHAT THIS PINS, exactly: that execution reaches the end of the method.
     * COMPLETION_LINE is logged unconditionally after the sweep block and nowhere
     * else, so a guard that grew a `return $result;` fails here. WHAT IT DOES NOT
     * PIN: that the run's own counters survive. Dropping tactical_assets is the
     * only way to fail the sweep, and it fails the per-agent upsert above it too,
     * so created/updated are zero here for a reason that has nothing to do with
     * the sweep. Failing the sweep ALONE needs a mid-run trigger this suite does
     * not have; stated rather than faked.
     */
    public function test_a_not_seen_sweep_failure_degrades_rather_than_aborting_the_method(): void
    {
        $this->skipUnlessSqlite();
        $this->captureLogRecords();

        Client::factory()->create(['tactical_site_id' => 'Acme|Main', 'is_active' => true]);
        Schema::drop('tactical_assets');

        $result = $this->syncService([$this->agent()])->syncDevices();

        $this->assertContains(
            'Failed to sweep not-seen agents offline',
            array_map(
                static fn (string $line): string => explode(':', $line, 2)[0],
                $result->errorMessages
            ),
            'the sweep failure was not recorded, so this case is not exercising the guard'
        );

        $this->assertContains(
            self::COMPLETION_LINE,
            $this->loggedMessages(),
            'the sweep guard aborted syncDevices() instead of degrading'
        );
    }
}
