<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
 * DRIVER CONSTRAINT — these guards are sqlite-only by construction. The failure
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
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->markTestSkipped("redaction guard forces its failure with DDL and is sqlite-only; driver is {$driver}");
        }

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
}
