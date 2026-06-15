<?php

namespace Tests\Feature\Tactical;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Services\Tactical\TacticalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization tests for TacticalAlertService — lock in CURRENT failure/resolve
 * behaviour (severity threshold, transient/noise filtering, empty-output skip, and
 * resolve-by-alert-id). No behaviour change.
 */
class TacticalAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tactical/{$name}")), true);
    }

    private function service(): TacticalAlertService
    {
        return app(TacticalAlertService::class);
    }

    public function test_above_threshold_failure_upserts_an_alert_with_mapped_severity(): void
    {
        $payload = $this->fixture('alert_failure.json'); // severity: error

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNotNull($alert);
        $this->assertSame(AlertSource::Tactical, $alert->source);
        $this->assertSame(AlertSeverity::Error, $alert->severity);
        $this->assertSame((string) $payload['alert_id'], $alert->source_alert_id);
        $this->assertSame('WS-FINANCE-04', $alert->hostname);
        $this->assertDatabaseHas('alerts', [
            'source' => AlertSource::Tactical->value,
            'source_alert_id' => (string) $payload['alert_id'],
            'status' => AlertStatus::Active->value,
        ]);
    }

    public function test_below_threshold_failure_is_dropped(): void
    {
        $payload = $this->fixture('alert_failure.json');
        $payload['severity'] = 'info'; // default min severity is 'warning'

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNull($alert);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_transient_noise_is_dropped(): void
    {
        $payload = $this->fixture('alert_failure.json');
        $payload['check_output'] = 'The operation could not be completed. A retry should be performed';

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNull($alert);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_empty_check_output_is_dropped(): void
    {
        $payload = $this->fixture('alert_failure.json');
        $payload['alert_type'] = 'check';
        $payload['check_output'] = '';

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNull($alert);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_availability_alert_for_non_server_is_dropped(): void
    {
        $payload = $this->fixture('alert_failure.json');
        $payload['alert_type'] = 'availability';
        $payload['monitoring_type'] = 'workstation';

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNull($alert);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_resolved_event_resolves_the_matching_open_alert(): void
    {
        // Fire a failure first to create the open alert.
        $failure = $this->fixture('alert_failure.json');
        $alert = $this->service()->handleAlertFailure($failure);
        $this->assertSame(AlertStatus::Active, $alert->status);

        // Now resolve it.
        $resolved = $this->fixture('alert_resolved.json');
        $returned = $this->service()->handleAlertResolved($resolved);

        $this->assertNotNull($returned);
        $this->assertTrue($alert->is($returned));
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_resolved_event_with_no_open_alert_returns_null(): void
    {
        $resolved = $this->fixture('alert_resolved.json');
        $resolved['alert_id'] = 999999; // nothing open for this id
        $resolved['hostname'] = 'UNKNOWN-HOST';

        $returned = $this->service()->handleAlertResolved($resolved);

        $this->assertNull($returned);
    }

    public function test_refired_failure_updates_existing_alert_not_a_duplicate(): void
    {
        $payload = $this->fixture('alert_failure.json');

        $first = $this->service()->handleAlertFailure($payload);
        $second = $this->service()->handleAlertFailure($payload);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame(1, $second->fresh()->refired_count);
    }
}
