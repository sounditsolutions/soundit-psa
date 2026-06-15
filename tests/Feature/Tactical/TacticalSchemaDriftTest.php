<?php

namespace Tests\Feature\Tactical;

use Tests\TestCase;

/**
 * Pinned-contract guard.
 *
 * This test does NOT auto-detect upstream Tactical drift (that would require hitting a
 * live /api/schema/ in CI, which we deliberately avoid). It pins the set of fields
 * SoundPSA's Tactical integration reads against a checked-in, trimmed /api/schema/
 * snapshot, so that when the snapshot is consciously refreshed from a real instance and
 * a field has moved/renamed, this test names exactly what broke and how to refresh.
 *
 * NOTE: the checked-in snapshot is best-effort (no live Tactical instance exists for this
 * deployment yet) — see tests/Fixtures/tactical/api_schema.json `_meta` and INSTALL.md §9.
 */
class TacticalSchemaDriftTest extends TestCase
{
    /**
     * Agent fields the daily device sync (TacticalDeviceSyncService) depends on.
     *
     * @var string[]
     */
    private const EXPECTED_AGENT_FIELDS = [
        'agent_id',
        'hostname',
        'client_name',
        'site_name',
        'logged_username',
        'operating_system',
        'public_ip',
        'local_ips',
        'cpu_model',
        'physical_disks',
        'graphics',
        'make_model',
        'serial_number',
        'status',
        'version',
        'last_seen',
        'needs_reboot',
        'has_patches_pending',
        'monitoring_type',
    ];

    /**
     * Alert fields the hourly reconciliation poll (TacticalReconcileAlerts) depends on.
     *
     * @var string[]
     */
    private const EXPECTED_ALERT_FIELDS = [
        'id',
        'resolved',
    ];

    private const REFRESH_HINT = 'Refresh the pinned snapshot from a live instance: enable SWAGGER_ENABLED in Tactical, '
        .'then `curl -s https://<tactical-host>/api/schema/ > tests/Fixtures/tactical/api_schema.json` and re-trim. See INSTALL.md §9.';

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $path = base_path('tests/Fixtures/tactical/api_schema.json');
        $this->assertFileExists($path, 'Pinned Tactical schema snapshot is missing.');

        return json_decode(file_get_contents($path), true);
    }

    /**
     * @return string[]
     */
    private function propertyNames(array $schema, string $component): array
    {
        $props = $schema['components']['schemas'][$component]['properties'] ?? null;
        $this->assertIsArray(
            $props,
            "Pinned snapshot is missing the `{$component}` schema component. ".self::REFRESH_HINT,
        );

        return array_keys($props);
    }

    public function test_agent_schema_contains_every_field_we_depend_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'AgentTable');

        foreach (self::EXPECTED_AGENT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical agent schema no longer exposes `{$field}` (used by TacticalDeviceSyncService). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_alert_schema_contains_every_field_we_depend_on(): void
    {
        $present = $this->propertyNames($this->schema(), 'Alert');

        foreach (self::EXPECTED_ALERT_FIELDS as $field) {
            $this->assertContains(
                $field,
                $present,
                "Tactical alert schema no longer exposes `{$field}` (used by TacticalReconcileAlerts). ".self::REFRESH_HINT,
            );
        }
    }

    public function test_snapshot_records_its_pinned_version_and_provenance(): void
    {
        $meta = $this->schema()['_meta'] ?? [];

        $this->assertArrayHasKey('pinned_tactical_version', $meta, 'Pinned snapshot must record the Tactical version it reflects.');
        $this->assertNotEmpty($meta['pinned_tactical_version']);
        $this->assertArrayHasKey('refresh_command', $meta, 'Pinned snapshot must document how to refresh it.');
    }
}
