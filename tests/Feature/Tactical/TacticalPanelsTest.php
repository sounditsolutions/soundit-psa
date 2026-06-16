<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commit 1 (P4 chunk 2): the asset-page lazy Tactical panels reached through the
 * shared deviceData AJAX branch — software / patches / checks-health. Each is a
 * bounded live read via TacticalInsightService::read(); each degrades to the
 * existing {error:…} payload (200, never a 500) on offline/timeout/error.
 *
 * Binding amendments under test:
 *   G — three states (data / genuinely-empty / could-not-load) and stdout redaction.
 *   F — patches lead count-first; a shape mismatch is "couldn't read", not "no patches".
 */
class TacticalPanelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TacticalConfig::isConfigured() must be true for the Tactical branch.
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://tactical.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function linkedAsset(array $taOverrides = []): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create(array_merge([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
            'has_patches_pending' => true,
            'checks_failing' => 1,
            'checks_total' => 4,
            'synced_at' => now()->subMinutes(10),
        ], $taOverrides));

        return $asset->refresh();
    }

    private function fetchSection(Asset $asset, string $section)
    {
        return $this->actingAs(User::factory()->create())
            ->getJson(route('assets.deviceData', ['asset' => $asset, 'section' => $section]));
    }

    // ── software ──────────────────────────────────────────────────────────────

    public function test_software_section_returns_mapped_data(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Google Chrome', 'version' => '120.0.1', 'publisher' => 'Google LLC'],
                ['name' => '7-Zip', 'version' => '23.01', 'publisher' => 'Igor Pavlov'],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'software');

        $resp->assertOk();
        $resp->assertJsonPath('tactical', true);
        $resp->assertJsonPath('software.0.name', 'Google Chrome');
        $resp->assertJsonPath('software.0.version', '120.0.1');
        $resp->assertJsonPath('software.0.publisher', 'Google LLC');
        $this->assertCount(2, $resp->json('software'));
    }

    public function test_software_empty_is_distinct_from_could_not_load(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new Response(200, [], json_encode([]))]);

        $resp = $this->fetchSection($asset, 'software');

        $resp->assertOk();
        // (b) loaded-and-genuinely-empty: software present (empty), no error.
        $this->assertSame([], $resp->json('software'));
        $this->assertArrayNotHasKey('error', $resp->json());
    }

    public function test_software_offline_degrades_to_error_not_500(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new ConnectException('offline', new Request('GET', 'software/AGENT-1/'))]);

        $resp = $this->fetchSection($asset, 'software');

        // (c) could-not-load: degrade payload, 200, no software key.
        $resp->assertOk();
        $this->assertArrayHasKey('error', $resp->json());
        $this->assertArrayNotHasKey('software', $resp->json());
    }

    // ── checks-health ──────────────────────────────────────────────────────────

    public function test_checks_section_returns_failing_checks_mapped(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Disk C', 'check_result' => ['status' => 'failing', 'retcode' => 1, 'stdout' => 'low space on C:']],
                ['name' => 'Ping GW', 'check_result' => ['status' => 'passing']],
                ['name' => 'CPU Load', 'check_result' => ['status' => 'passing']],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'checks');

        $resp->assertOk();
        $resp->assertJsonPath('tactical', true);
        $resp->assertJsonPath('checks_total', 3);
        $resp->assertJsonPath('checks_failing', 1);
        $this->assertCount(1, $resp->json('failing_checks'));
        $resp->assertJsonPath('failing_checks.0.name', 'Disk C');
        $resp->assertJsonPath('failing_checks.0.retcode', 1);
    }

    public function test_checks_all_passing_is_an_empty_failing_list_not_an_error(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Ping GW', 'check_result' => ['status' => 'passing']],
                ['name' => 'CPU Load', 'check_result' => ['status' => 'passing']],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'checks');

        $resp->assertOk();
        // (b) genuinely empty (all passing): zero failing, no error.
        $resp->assertJsonPath('checks_failing', 0);
        $this->assertSame([], $resp->json('failing_checks'));
        $this->assertArrayNotHasKey('error', $resp->json());
    }

    public function test_checks_offline_degrades_to_error_not_clean(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new ConnectException('offline', new Request('GET', 'agents/AGENT-1/checks/'))]);

        $resp = $this->fetchSection($asset, 'checks');

        // (c) could-not-load: a degraded checks read must NEVER read as "all passing".
        $resp->assertOk();
        $this->assertArrayHasKey('error', $resp->json());
        $this->assertArrayNotHasKey('failing_checks', $resp->json());
    }

    public function test_planted_secret_in_check_stdout_is_redacted_and_clipped_in_the_panel(): void
    {
        $asset = $this->linkedAsset();

        // Build the secret by concatenation so no contiguous credential literal
        // lives in the test source (secret-guard).
        $secret = 'db password = '.'S3cr3tP'.'@ssw0rd!';
        $longTail = str_repeat('x', 500);

        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Backup Job', 'check_result' => ['status' => 'failing', 'stdout' => $secret.' '.$longTail]],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'checks');

        $resp->assertOk();
        $stdout = $resp->json('failing_checks.0.stdout');
        $this->assertStringContainsString('[REDACTED:credential]', $stdout);
        $this->assertStringNotContainsString('S3cr3tP'.'@ssw0rd!', $stdout);
        // Length-clipped (~200 chars) — the 500-char tail must not render whole.
        $this->assertLessThanOrEqual(220, strlen($stdout));
    }

    // ── patches (count-first, shape UNVERIFIED — amendment F) ───────────────────

    public function test_patches_section_leads_with_a_compliance_count(): void
    {
        $asset = $this->linkedAsset();
        // winupdate serializer shape (guess): a list of update rows. `installed`
        // false => pending. severity rolls up when present.
        $this->bindClient([
            new Response(200, [], json_encode([
                ['id' => 1, 'kb' => 'KB5001', 'title' => 'Cumulative Update', 'severity' => 'Critical', 'installed' => false],
                ['id' => 2, 'kb' => 'KB5002', 'title' => 'Defender Update', 'severity' => 'Important', 'installed' => false],
                ['id' => 3, 'kb' => 'KB4999', 'title' => 'Old Update', 'severity' => 'Critical', 'installed' => true],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'patches');

        $resp->assertOk();
        $resp->assertJsonPath('tactical', true);
        // Count-first: 2 pending (the installed one excluded).
        $resp->assertJsonPath('pending_count', 2);
        // Severity rollup present.
        $this->assertSame(1, $resp->json('severity.critical'));
        $this->assertSame(1, $resp->json('severity.important'));
        // Full list is secondary/opt-in but present for "show all".
        $this->assertCount(2, $resp->json('patches'));
    }

    public function test_patches_empty_is_no_pending_updates_not_an_error(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new Response(200, [], json_encode([]))]);

        $resp = $this->fetchSection($asset, 'patches');

        $resp->assertOk();
        // (b) genuinely empty: zero pending, no error, no "couldn't read" flag.
        $resp->assertJsonPath('pending_count', 0);
        $this->assertArrayNotHasKey('error', $resp->json());
        $this->assertArrayNotHasKey('shape_error', $resp->json());
    }

    public function test_patches_shape_mismatch_is_couldnt_read_not_no_patches(): void
    {
        $asset = $this->linkedAsset();
        // The shape is UNVERIFIED. A payload whose rows lack the expected fields
        // must NOT be rendered as "0 pending / fully patched".
        $this->bindClient([
            new Response(200, [], json_encode([
                ['unexpected' => 'garbage', 'foo' => 'bar'],
                ['something' => 'else'],
            ])),
        ]);

        $resp = $this->fetchSection($asset, 'patches');

        $resp->assertOk();
        // A shape we don't recognise => an explicit "couldn't read patch detail",
        // never an empty list presented as compliant.
        $this->assertArrayHasKey('shape_error', $resp->json());
        $this->assertNull($resp->json('pending_count'));
    }

    public function test_patches_offline_degrades_to_error_not_500(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new ConnectException('offline', new Request('GET', 'winupdate/AGENT-1/'))]);

        $resp = $this->fetchSection($asset, 'patches');

        $resp->assertOk();
        $this->assertArrayHasKey('error', $resp->json());
        $this->assertArrayNotHasKey('pending_count', $resp->json());
    }

    // ── routing / gating ────────────────────────────────────────────────────────

    public function test_checks_is_an_allowed_section(): void
    {
        $asset = $this->linkedAsset();
        $this->bindClient([new Response(200, [], json_encode([]))]);

        // 'checks' was not previously in $allowedSections — must not 422.
        $this->fetchSection($asset, 'checks')->assertOk();
    }

    public function test_unlinked_asset_without_ninja_or_level_is_unprocessable(): void
    {
        // No Tactical, no Ninja, no Level → the existing 422 contract holds.
        $asset = Asset::factory()->create(['hostname' => 'NOLINK']);

        $this->fetchSection($asset, 'software')->assertStatus(422);
    }
}
