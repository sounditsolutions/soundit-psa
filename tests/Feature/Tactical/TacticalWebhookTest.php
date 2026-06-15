<?php

namespace Tests\Feature\Tactical;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Jobs\ProcessTacticalWebhook;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\TacticalWebhook;
use App\Services\Tactical\TacticalAlertService;
use App\Services\Tactical\TacticalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TacticalWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookKey = 'test-tactical-webhook-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('tactical_webhook_key', $this->webhookKey);
    }

    private function validWebhookKey(): string
    {
        return $this->webhookKey;
    }

    private function fixture(string $name): array
    {
        $path = base_path("tests/Fixtures/tactical/{$name}");

        return json_decode(file_get_contents($path), true);
    }

    public function test_valid_webhook_persists_queues_and_acks_fast(): void
    {
        Queue::fake();
        $payload = $this->fixture('alert_failure.json');

        // Controller acks fast with 204 No Content (per plan Step 5: `return response()->noContent()`).
        $this->withHeaders(['X-Webhook-Key' => $this->validWebhookKey()])
            ->postJson('/api/webhooks/tactical', $payload)->assertNoContent();

        $this->assertDatabaseHas('tactical_webhooks', ['event' => 'alert_failure', 'status' => 'pending']);
        Queue::assertPushed(ProcessTacticalWebhook::class);
    }

    public function test_invalid_key_rejected_and_not_persisted(): void
    {
        Queue::fake();

        $this->withHeaders(['X-Webhook-Key' => 'wrong'])
            ->postJson('/api/webhooks/tactical', ['event' => 'alert_failure'])->assertStatus(401);

        $this->assertDatabaseCount('tactical_webhooks', 0);
        Queue::assertNotPushed(ProcessTacticalWebhook::class);
    }

    public function test_duplicate_delivery_is_deduped(): void
    {
        Queue::fake();
        $p = $this->fixture('alert_failure.json'); // carries a tactical alert id
        $h = ['X-Webhook-Key' => $this->validWebhookKey()];

        $this->withHeaders($h)->postJson('/api/webhooks/tactical', $p)->assertNoContent();
        $this->withHeaders($h)->postJson('/api/webhooks/tactical', $p)->assertNoContent(); // replay

        $this->assertDatabaseCount('tactical_webhooks', 1);
        Queue::assertPushed(ProcessTacticalWebhook::class, 1);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->withHeaders(['X-Webhook-Key' => $this->validWebhookKey()])
            ->postJson('/api/webhooks/tactical', ['garbage' => true])->assertStatus(422);
    }

    public function test_unknown_event_is_skipped_not_failed(): void
    {
        $row = TacticalWebhook::factory()->create(['event' => 'something_else', 'status' => 'pending']);

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        $this->assertEquals('skipped', $row->fresh()->status);
    }

    public function test_below_threshold_alert_persists_as_skipped_with_payload_retained(): void
    {
        // severity below tactical_alert_min_severity (default 'warning') -> handled, but row
        // retained as skipped, payload intact.
        $payload = $this->fixture('alert_failure.json');
        $payload['severity'] = 'info';

        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_failure',
            'status' => 'pending',
            'payload' => $payload,
        ]);

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        $fresh = $row->fresh();
        $this->assertEquals('skipped', $fresh->status);
        // No alert created (below threshold dropped by the service)
        $this->assertDatabaseCount('alerts', 0);
        // Payload retained intact
        $this->assertEquals('info', $fresh->payload['severity']);
        $this->assertEquals($payload['alert_id'], $fresh->payload['alert_id']);
    }

    public function test_above_threshold_alert_is_processed_and_creates_alert(): void
    {
        $payload = $this->fixture('alert_failure.json');

        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_failure',
            'status' => 'pending',
            'payload' => $payload,
        ]);

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        $this->assertEquals('processed', $row->fresh()->status);
        $this->assertDatabaseHas('alerts', [
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => (string) $payload['alert_id'],
        ]);
    }

    public function test_resolved_event_resolves_matching_open_alert(): void
    {
        $failure = $this->fixture('alert_failure.json');

        // Pre-create the open alert (as if a prior failure webhook fired it).
        $alert = Alert::create([
            'source' => AlertSource::Tactical,
            'source_alert_id' => (string) $failure['alert_id'],
            'severity' => AlertSeverity::Error,
            'status' => AlertStatus::Active,
            'title' => 'Disk Space - C:',
            'hostname' => $failure['hostname'],
            'fired_at' => now(),
        ]);

        $resolved = $this->fixture('alert_resolved.json');
        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_resolved',
            'status' => 'pending',
            'payload' => $resolved,
        ]);

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        $this->assertEquals('processed', $row->fresh()->status);
        $this->assertEquals(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_job_is_idempotent_on_already_processed_row(): void
    {
        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_failure',
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // Should no-op (guarded by isPending) — no exception, status unchanged.
        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        $this->assertEquals('processed', $row->fresh()->status);
    }

    public function test_hourly_poll_reconciles_a_webhook_the_job_dropped(): void
    {
        // Tactical must be "configured" for the reconcile command to run.
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key');

        // Simulate a failed/never-processed alert: the unified Alert is still OPEN in PSA
        // because the resolve webhook was dropped (8s/no-retry).
        $alert = Alert::create([
            'source' => AlertSource::Tactical,
            'source_alert_id' => '84213',
            'severity' => AlertSeverity::Error,
            'status' => AlertStatus::Active,
            'title' => 'Disk Space - C:',
            'hostname' => 'WS-FINANCE-04',
            'fired_at' => now()->subHours(2),
        ]);

        // The backstop poll sees Tactical reporting this alert resolved.
        $mock = $this->mock(TacticalClient::class);
        $mock->shouldReceive('patch')
            ->with('alerts/', ['timeFilter' => 30])
            ->andReturn([
                ['id' => 84213, 'resolved' => true],
            ]);

        $this->artisan('tactical:reconcile-alerts')->assertExitCode(0);

        $this->assertEquals(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_terminal_failed_hook_marks_webhook_failed_not_pending(): void
    {
        // The job uses Laravel-native retry: handle() lets exceptions propagate and
        // markFailed() runs ONLY from the terminal failed() hook (after $tries is
        // exhausted), where attempts is still 1. The row must end 'failed' — not be
        // reset to 'pending' — so it surfaces in the webhook-health failed badge.
        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_failure',
            'status' => 'pending',
        ]);

        (new ProcessTacticalWebhook($row->id))->failed(new \RuntimeException('boom'));

        $fresh = $row->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertSame('boom', $fresh->error);
        $this->assertEquals(1, $fresh->attempts);
    }

    public function test_mark_failed_sets_failed_status_unconditionally(): void
    {
        // Direct model contract: a single markFailed (attempts goes 0 -> 1) is terminal.
        $row = TacticalWebhook::factory()->create([
            'event' => 'alert_failure',
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $row->markFailed('kaput');

        $this->assertEquals('failed', $row->fresh()->status);
        $this->assertEquals(1, $row->fresh()->attempts);
    }

    public function test_failure_and_resolve_are_distinct_rows_and_both_dispatch(): void
    {
        Queue::fake();
        $h = ['X-Webhook-Key' => $this->validWebhookKey()];

        $failure = $this->fixture('alert_failure.json');   // alert_id 84213
        $resolved = $this->fixture('alert_resolved.json');  // same alert_id 84213
        $this->assertSame($failure['alert_id'], $resolved['alert_id']); // guard the fixture invariant

        // Distinct events for the same alert id must NOT collide (both must process)...
        $this->withHeaders($h)->postJson('/api/webhooks/tactical', $failure)->assertNoContent();
        $this->withHeaders($h)->postJson('/api/webhooks/tactical', $resolved)->assertNoContent();
        // ...but a replay of either event DOES collide.
        $this->withHeaders($h)->postJson('/api/webhooks/tactical', $failure)->assertNoContent();

        $this->assertDatabaseCount('tactical_webhooks', 2);
        $this->assertEquals(2, TacticalWebhook::distinct('dedup_key')->count('dedup_key'));
        $this->assertDatabaseHas('tactical_webhooks', ['event' => 'alert_failure', 'dedup_key' => 'alert_failure:84213']);
        $this->assertDatabaseHas('tactical_webhooks', ['event' => 'alert_resolved', 'dedup_key' => 'alert_resolved:84213']);
        Queue::assertPushed(ProcessTacticalWebhook::class, 2);
    }
}
