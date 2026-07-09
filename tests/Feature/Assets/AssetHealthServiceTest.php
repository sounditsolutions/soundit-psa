<?php

namespace Tests\Feature\Assets;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\AssetHealthGrade;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\AssetHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(?AiClient $ai = null): AssetHealthService
    {
        return $ai ? new AssetHealthService($ai) : new AssetHealthService;
    }

    public function test_unknown_when_no_monitoring_signals(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => null,
            'level_id' => null,
            'rmm_online' => null,
            'last_seen_at' => null,
        ]);

        $result = $this->service()->compute($asset);

        $this->assertNull($result->score);
        $this->assertSame(AssetHealthGrade::Unknown, $result->grade);
        $this->assertFalse($result->isKnown());
    }

    public function test_fully_healthy_asset_scores_100(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => true,
            'last_seen_at' => now(),
            'backup_synced_at' => now(),
            'needs_reboot' => false,
            'm365_is_compliant' => true,
            'm365_compliance_state' => 'compliant',
        ]);

        $result = $this->service()->compute($asset);

        $this->assertSame(100, $result->score);
        $this->assertSame(AssetHealthGrade::Good, $result->grade);
        $this->assertEmpty($result->notableFactors());
    }

    public function test_offline_asset_loses_connectivity_points(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $result = $this->service()->compute($asset);

        // 100 - 30 (offline). Alerts known (RMM-linked) but none open.
        $this->assertSame(70, $result->score);
        $this->assertSame(AssetHealthGrade::Fair, $result->grade);
        $connectivity = collect($result->factors)->firstWhere('key', 'connectivity');
        $this->assertSame('bad', $connectivity['status']);
        $this->assertSame(-30, $connectivity['points']);
    }

    public function test_alert_penalty_is_capped(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => true,
            'last_seen_at' => now(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            Alert::create([
                'asset_id' => $asset->id,
                'client_id' => $asset->client_id,
                'source' => AlertSource::Ninja->value,
                'source_alert_id' => "crit-{$i}",
                'severity' => AlertSeverity::Critical->value,
                'status' => AlertStatus::Active->value,
                'title' => "Critical {$i}",
                'fired_at' => now(),
            ]);
        }

        $result = $this->service()->compute($asset);

        // 3 x 25 = 75 raw, capped at 40 → 100 - 40 = 60.
        $alerts = collect($result->factors)->firstWhere('key', 'alerts');
        $this->assertSame(-40, $alerts['points']);
        $this->assertSame(60, $result->score);
    }

    public function test_stale_backup_penalised(): void
    {
        $asset = Asset::factory()->create([
            'comet_backup_enabled' => true,
            'backup_synced_at' => now()->subHours(50),
        ]);

        $result = $this->service()->compute($asset);

        $backup = collect($result->factors)->firstWhere('key', 'backup');
        $this->assertSame('bad', $backup['status']);
        $this->assertSame(-18, $backup['points']);
        $this->assertSame(82, $result->score);
    }

    public function test_m365_noncompliant_and_defender_capped(): void
    {
        $asset = Asset::factory()->create([
            'm365_is_compliant' => false,
            'm365_compliance_state' => 'noncompliant',
            'm365_defender_status' => 'Disabled',
        ]);

        $result = $this->service()->compute($asset);

        // 15 (noncompliant) + 10 (defender) = 25, capped at 20 → 80.
        $m365 = collect($result->factors)->firstWhere('key', 'm365');
        $this->assertSame(-20, $m365['points']);
        $this->assertSame(80, $result->score);
    }

    public function test_defender_running_is_not_penalised(): void
    {
        $asset = Asset::factory()->create([
            'm365_is_compliant' => true,
            'm365_compliance_state' => 'compliant',
            'm365_defender_status' => 'Running',
        ]);

        $result = $this->service()->compute($asset);

        $m365 = collect($result->factors)->firstWhere('key', 'm365');
        $this->assertSame('ok', $m365['status']);
        $this->assertSame(0, $m365['points']);
    }

    public function test_unrecognised_defender_status_is_not_penalised(): void
    {
        // "unknown ≠ unhealthy" — an unfamiliar Defender status must not dock points.
        $asset = Asset::factory()->create([
            'm365_is_compliant' => true,
            'm365_compliance_state' => 'compliant',
            'm365_defender_status' => 'SignaturesUpToDate',
        ]);

        $result = $this->service()->compute($asset);

        $m365 = collect($result->factors)->firstWhere('key', 'm365');
        $this->assertSame('ok', $m365['status']);
        $this->assertSame(0, $m365['points']);
    }

    public function test_needs_reboot_penalised(): void
    {
        $asset = Asset::factory()->create(['needs_reboot' => true]);

        $result = $this->service()->compute($asset);

        $patch = collect($result->factors)->firstWhere('key', 'patch');
        $this->assertSame(-10, $patch['points']);
        $this->assertSame(90, $result->score);
    }

    public function test_open_urgent_ticket_penalised(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => true,
            'last_seen_at' => now(),
        ]);

        $ticket = Ticket::factory()->create([
            'client_id' => $asset->client_id,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P1->value,
            'closed_at' => null,
        ]);
        $asset->tickets()->attach($ticket);

        $result = $this->service()->compute($asset);

        $tickets = collect($result->factors)->firstWhere('key', 'tickets');
        $this->assertSame(-8, $tickets['points']);
        $this->assertSame('bad', $tickets['status']);
        $this->assertSame(92, $result->score);
    }

    public function test_closed_tickets_do_not_penalise(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => true,
            'last_seen_at' => now(),
        ]);

        // TicketFactory defaults to Closed.
        $asset->tickets()->attach(Ticket::factory()->create(['client_id' => $asset->client_id]));

        $result = $this->service()->compute($asset);

        $tickets = collect($result->factors)->firstWhere('key', 'tickets');
        $this->assertSame('ok', $tickets['status']);
        $this->assertSame(100, $result->score);
    }

    public function test_score_clamped_at_zero(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subDays(3),
            'comet_backup_enabled' => true,
            'backup_synced_at' => now()->subHours(60),
            'needs_reboot' => true,
            'last_boot_at' => now()->subDays(40),
            'm365_is_compliant' => false,
            'm365_compliance_state' => 'noncompliant',
            'm365_defender_status' => 'Disabled',
        ]);

        for ($i = 0; $i < 5; $i++) {
            Alert::create([
                'asset_id' => $asset->id,
                'client_id' => $asset->client_id,
                'source' => AlertSource::Ninja->value,
                'source_alert_id' => "crit-{$i}",
                'severity' => AlertSeverity::Critical->value,
                'status' => AlertStatus::Active->value,
                'title' => "Critical {$i}",
                'fired_at' => now(),
            ]);
        }

        $result = $this->service()->compute($asset);

        $this->assertSame(0, $result->score);
        $this->assertSame(AssetHealthGrade::Poor, $result->grade);
    }

    public function test_refresh_persists_columns(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $this->service()->refresh($asset);
        $asset->refresh();

        $this->assertSame(70, $asset->health_score);
        $this->assertSame(AssetHealthGrade::Fair, $asset->health_grade);
        $this->assertNotEmpty($asset->health_summary);
        $this->assertIsArray($asset->health_breakdown);
        $this->assertFalse($asset->health_summary_is_ai);
        $this->assertNotNull($asset->health_computed_at);
    }

    public function test_refresh_if_stale_returns_cache_when_fresh(): void
    {
        $asset = Asset::factory()->scored(50)->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subDays(3),
        ]);

        // Score is fresh (scored() stamps health_computed_at = now), so the real
        // signals (which would compute a different number) must be ignored.
        $result = $this->service()->refreshIfStale($asset);

        $this->assertSame(50, $result->score);
    }

    public function test_refresh_if_stale_recomputes_when_stale(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
            'health_score' => 5,
            'health_grade' => 'poor',
            'health_computed_at' => now()->subHours(24),
        ]);

        $result = $this->service()->refreshIfStale($asset);
        $asset->refresh();

        $this->assertSame(70, $result->score);
        $this->assertSame(70, $asset->health_score);
    }

    public function test_deterministic_narrative_mentions_the_problem(): void
    {
        $asset = Asset::factory()->create([
            'hostname' => 'DESK-1',
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        [$text, $isAi] = $this->service()->narrative($asset, $this->service()->compute($asset), useAi: false);

        $this->assertFalse($isAi);
        $this->assertStringContainsString('70/100', $text);
        $this->assertStringContainsString('connectivity', $text);
    }

    public function test_unknown_asset_narrative_explains_missing_data(): void
    {
        $asset = Asset::factory()->create([
            'ninja_id' => null,
            'rmm_online' => null,
            'last_seen_at' => null,
        ]);

        [$text] = $this->service()->narrative($asset, $this->service()->compute($asset), useAi: false);

        $this->assertStringContainsString('enough monitoring data', $text);
    }

    public function test_ai_narrative_used_when_configured(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $fake = new class extends AiClient
        {
            public int $calls = 0;

            public function complete(string $system, string|array $userMessage, int $maxTokens = 4096): AiResponse
            {
                $this->calls++;

                return new AiResponse('OFFLINE — call the client.');
            }
        };

        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $this->service($fake)->refresh($asset, useAi: true);
        $asset->refresh();

        $this->assertSame('OFFLINE — call the client.', $asset->health_summary);
        $this->assertTrue($asset->health_summary_is_ai);
        $this->assertSame(1, $fake->calls);
    }

    public function test_unchanged_ai_narrative_is_reused_without_new_ai_call(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $fake = new class extends AiClient
        {
            public int $calls = 0;

            public function complete(string $system, string|array $userMessage, int $maxTokens = 4096): AiResponse
            {
                $this->calls++;

                return new AiResponse('AI explanation #'.$this->calls);
            }
        };

        $asset = Asset::factory()->create([
            'ninja_id' => 'ninja-1',
            'rmm_online' => false,
            'last_seen_at' => now()->subHours(2),
        ]);

        $service = $this->service($fake);
        $service->refresh($asset, useAi: true);
        $asset->refresh();
        $this->assertSame(1, $fake->calls);
        $firstSummary = $asset->health_summary;

        // Signals unchanged → the cached AI narrative should be reused.
        $service->refresh($asset, useAi: true);
        $asset->refresh();

        $this->assertSame(1, $fake->calls);
        $this->assertSame($firstSummary, $asset->health_summary);
    }
}
