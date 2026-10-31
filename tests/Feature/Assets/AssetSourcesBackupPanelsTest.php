<?php

namespace Tests\Feature\Assets;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetSources\SourcePanel;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Asset Sources S4 (design/mockups/assets/design-r3.md §2.2, §4, §8 S4): the Comet and
 * Servosity panels. Comet reuses the asset page's one job read (AssetController::show);
 * Servosity shows its stored enabled flag and never a run outcome.
 *
 * G-5: Http::preventStrayRequests with no catch-all. Comet's SDK is not Http-based, so
 * CometClient is bound to a scripted fake: getJobsForUser answers from a queue (an empty
 * queue throws), and every other method throws because the fake never builds the SDK
 * server. G-13: synthetic data only.
 */
class AssetSourcesBackupPanelsTest extends TestCase
{
    use AssetSourcesFixtures;
    use RefreshDatabase;

    /** @var \ArrayObject<int, string> usernames asked, one entry per getJobsForUser call */
    private \ArrayObject $cometCalls;

    /** @var \ArrayObject<int, array<int, \Comet\BackupJobDetail>|\Throwable> */
    private \ArrayObject $cometScript;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);

        $this->cometCalls = new \ArrayObject;
        $this->cometScript = new \ArrayObject;
        $calls = $this->cometCalls;
        $script = $this->cometScript;
        $this->app->bind(CometClient::class, fn () => new class($calls, $script) extends CometClient
        {
            // The parent constructor (which builds the SDK server) is never run, so any
            // unscripted method reaches an uninitialised server and throws.
            public function __construct(private \ArrayObject $calls, private \ArrayObject $script) {}

            public function getJobsForUser(string $username): array
            {
                $this->calls[] = $username;
                if (count($this->script) === 0) {
                    throw new \LogicException('unscripted Comet getJobsForUser call');
                }
                $next = $this->script[0];
                $rest = array_slice($this->script->getArrayCopy(), 1);
                $this->script->exchangeArray($rest);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        });
    }

    private function show(Asset $asset): TestResponse
    {
        return $this->actingAs(User::factory()->tech()->create())->get(route('assets.show', $asset))->assertOk();
    }

    private function panel(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-panel="'.$source.'"');
        $this->assertNotFalse($start, "panel {$source} is rendered");
        $next = strpos($html, 'data-source-panel="', $start + 20);
        $end = $next === false ? strpos($html, '</script>', $start) : $next;

        return substr($html, $start, $end - $start);
    }

    private function sources(string $html): string
    {
        $start = strpos($html, 'id="tab-sources"');
        $this->assertNotFalse($start);

        return substr($html, $start);
    }

    private function backupTab(string $html): string
    {
        $start = strpos($html, 'id="tab-backup"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'id="tab-sources"', $start) - $start);
    }

    private function railEntry(string $html, string $source): string
    {
        $start = strpos($html, 'data-source-rail="'.$source.'"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    /** @return array{kind: string, value: string, note: string} */
    private function chip(string $panel, string $label): array
    {
        $ok = preg_match('/data-chip-kind="([a-z_]+)">\s*<div[^>]*>'.preg_quote($label, '/').'<\/div>\s*<div class="fw-semibold">([^<]*)<\/div>\s*<div class="small text-muted" data-chip-note>([^<]*)<\/div>/', $panel, $m);
        $this->assertSame(1, $ok, "chip {$label} is rendered");

        return ['kind' => $m[1], 'value' => html_entity_decode(trim($m[2]), ENT_QUOTES), 'note' => html_entity_decode(trim($m[3]), ENT_QUOTES)];
    }

    /** A Comet-linked asset whose job read can be asked (it carries a username and device id). */
    private function cometAsset(array $overrides = []): Asset
    {
        return $this->assetLinkedEverywhere(array_merge(['comet_username' => 'synthetic-backup-user'], $overrides));
    }

    /** Vendor-shaped job (the SDK's own BackupJobDetail with Def constants). */
    private function job(Asset $asset, int $status, int $hoursAgo): \Comet\BackupJobDetail
    {
        $job = new \Comet\BackupJobDetail;
        $job->Username = 'synthetic-backup-user';
        $job->DeviceID = (string) $asset->comet_device_id;
        $job->Classification = \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $status;
        $job->StartTime = now()->subHours($hoursAgo)->timestamp;
        $job->EndTime = now()->subHours($hoursAgo)->addMinutes(20)->timestamp;

        return $job;
    }

    private function cometAlert(Asset $asset, string $title): Alert
    {
        return Alert::create([
            'asset_id' => $asset->id, 'source' => 'comet', 'severity' => AlertSeverity::Error,
            'status' => AlertStatus::Active, 'title' => $title, 'fired_at' => now()->subMinutes(30),
            'source_alert_id' => 'synthetic-'.uniqid(),
        ]);
    }

    // ── Comet: one read, posture vocabulary, unavailable (M4B.2, M4B.1, M5.3) ─────────

    public function test_one_admin_get_jobs_for_user_per_render(): void
    {
        // M4B.2: the panel reuses the page's job read; a second read for the panel fails here.
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];
        // A spare answer, so a second read would render rather than throw: the count below is
        // what catches it.
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];

        $panel = $this->panel($this->show($asset)->getContent(), 'comet');

        $this->assertSame(['synthetic-backup-user'], $this->cometCalls->getArrayCopy(), 'exactly one getJobsForUser per show render');
        $this->assertSame('Last backup succeeded', $this->chip($panel, 'Backup posture')['value'], 'the panel shows that one read');
    }

    public function test_posture_chip_uses_the_derive_posture_vocabulary_and_never_says_ok(): void
    {
        // M4B.1.
        $this->configureAllSources();
        $cases = [
            'Last backup succeeded' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'Last backup failed' => \Comet\Def::JOB_STATUS_FAILED_ERROR,
            'Backup in progress' => \Comet\Def::JOB_STATUS_RUNNING_ACTIVE,
            'Last backup result unknown' => 4999,
            'No backup jobs observed' => null,
        ];
        foreach ($cases as $label => $status) {
            $asset = $this->cometAsset();
            $this->cometScript[] = $status === null ? [] : [$this->job($asset, $status, 6)];
            $panel = $this->panel($this->show($asset)->getContent(), 'comet');

            $chip = $this->chip($panel, 'Backup posture');
            $this->assertSame($label, $chip['value'], $label);
            $this->assertSame('live', $chip['kind'], $label);
            $this->assertDoesNotMatchRegularExpression('/fw-semibold">\s*OK\s*</i', $panel, $label);
            $this->assertStringNotContainsString('All clear', $panel, $label);
        }
        $this->assertSame(array_values(SourcePanel::COMET_POSTURE), [
            'Last backup succeeded', 'Last backup failed', 'Backup in progress', 'Last backup result unknown', 'No backup jobs observed', 'Unavailable',
        ]);
        $this->assertCount(5, $this->cometCalls);
    }

    public function test_last_success_and_failure_chips_come_from_the_read(): void
    {
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $ok = $this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 30);
        $bad = $this->job($asset, \Comet\Def::JOB_STATUS_FAILED_QUOTA, 6);
        $this->cometScript[] = [$ok, $bad];

        $panel = $this->panel($this->show($asset)->getContent(), 'comet');

        $this->assertSame('Last backup failed', $this->chip($panel, 'Backup posture')['value']);
        $fmt = fn (\Comet\BackupJobDetail $j) => \Illuminate\Support\Carbon::createFromTimestamp($j->StartTime)->toAppTz()->format('M j, Y g:i A T');
        $this->assertSame($fmt($ok), $this->chip($panel, 'Last success')['value']);
        $this->assertSame($fmt($bad), $this->chip($panel, 'Last failure')['value']);
    }

    public function test_comet_lookup_failure_reads_unavailable_never_no_jobs(): void
    {
        // M5.3 (C-56): a failed read is Unavailable with the Backup tab's reason copy; never
        // "no jobs", never empty, and no success/failure chips that could read as a clean history.
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $this->cometScript[] = new CometClientException('Comet API job lookup failed (code 0)');

        $panel = $this->panel($this->show($asset)->getContent(), 'comet');

        $chip = $this->chip($panel, 'Backup posture');
        $this->assertSame('Unavailable', $chip['value']);
        $this->assertSame('Backup job history unavailable — the Comet server could not be reached. Backup state is unknown, not passing.', $chip['note']);
        $this->assertStringNotContainsString('No backup jobs observed', $panel);
        $this->assertStringNotContainsString('No jobs', $panel);
        $this->assertStringNotContainsString('>Last success<', $panel);
        $this->assertStringNotContainsString('>Last failure<', $panel);
        $this->assertCount(1, $this->cometCalls);
    }

    public function test_no_synced_username_reads_unavailable_with_its_own_reason_and_no_vendor_call(): void
    {
        // M5.3, the other reason: nothing could be asked, so it is not "the server could not be reached".
        $this->configureAllSources();
        $panel = $this->panel($this->show($this->assetLinkedEverywhere())->getContent(), 'comet');

        $chip = $this->chip($panel, 'Backup posture');
        $this->assertSame('Unavailable', $chip['value']);
        $this->assertSame('Job history cannot be looked up — this asset has no synced Comet username. Backup state is unknown, not passing.', $chip['note']);
        $this->assertStringNotContainsString('No backup jobs observed', $panel);
        $this->assertCount(0, $this->cometCalls);
    }

    public function test_comet_chips_age_from_jobs_checked_at_never_backup_synced_at(): void
    {
        // M-age: backup_synced_at is the daily storage sync's stamp (shared with Ninja backup).
        $this->configureAllSources();
        $asset = $this->cometAsset(['backup_synced_at' => now()->subDays(3)]);
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];

        $panel = $this->panel($this->show($asset)->getContent(), 'comet');

        foreach (['Backup posture', 'Last success', 'Last failure'] as $label) {
            $chip = $this->chip($panel, $label);
            $this->assertSame(['live', 'read from Comet just now'], [$chip['kind'], $chip['note']], $label);
        }
        $this->assertStringNotContainsString('3 days ago', $panel);
        $this->assertStringNotContainsString('data-chip-kind="snapshot"', $panel);
        $this->assertStringContainsString('Sync time not tracked for this source', $panel, 'footer unchanged');
    }

    public function test_tickets_route_claims_no_comet_posture_and_reads_nothing(): void
    {
        // The tickets route passes cometJobData => null: the panel must not claim a posture.
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $html = $this->actingAs(User::factory()->tech()->create())->get(route('assets.tickets', $asset))->assertOk()->getContent();
        $panel = $this->panel($html, 'comet');

        $chip = $this->chip($panel, 'Backup posture');
        $this->assertSame(['none', 'Not read on this page', ''], [$chip['kind'], $chip['value'], $chip['note']]);
        foreach (SourcePanel::COMET_POSTURE as $word) {
            $this->assertStringNotContainsString('>'.$word.'<', $panel, $word);
        }
        $this->assertStringNotContainsString('>Last success<', $panel);
        $this->assertCount(0, $this->cometCalls);
        // The link to the job table goes back to the asset page (Backup is not a tab on this route).
        $this->assertStringContainsString('href="'.route('assets.show', $asset).'#tab-backup"', $panel);
    }

    // ── Comet: attention and the rail marker (M-mk) ──────────────────────────────────────────

    public function test_a_failed_last_backup_is_panel_attention_and_never_feeds_the_rail_marker(): void
    {
        // M-mk: a failed last backup with no stored alert renders no Comet marker (§1).
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_FAILED_ERROR, 6)];

        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'comet');

        $this->assertMatchesRegularExpression('/data-attention-item="backup">\s*<strong>Last backup failed<\/strong>\s*<span[^>]*>reported by Comet<\/span>/', $panel);
        $this->assertStringNotContainsString('data-attention-marker', $this->railEntry($html, 'comet'));
        $this->assertStringNotContainsString('data-default-source="comet"', $html, 'the live posture does not pick the default either');

        // Positive control: a stored Comet alert does mark the rail, and is listed in the panel.
        $asset2 = $this->cometAsset();
        $this->cometAlert($asset2, 'Backup job failed on DESK-EXAMPLE-01');
        $this->cometScript[] = [$this->job($asset2, \Comet\Def::JOB_STATUS_FAILED_ERROR, 6)];
        $html2 = $this->show($asset2)->getContent();
        $this->assertStringContainsString('data-attention-marker="1"', $this->railEntry($html2, 'comet'));
        $panel2 = $this->panel($html2, 'comet');
        $this->assertStringContainsString('Open alert: Backup job failed on DESK-EXAMPLE-01', $panel2);
        $this->assertStringContainsString('data-attention-item="backup"', $panel2);
    }

    public function test_no_failed_backup_item_unless_the_read_says_last_backup_failed(): void
    {
        $this->configureAllSources();
        foreach ([\Comet\Def::JOB_STATUS_STOP_SUCCESS, \Comet\Def::JOB_STATUS_RUNNING_ACTIVE] as $status) {
            $asset = $this->cometAsset();
            $this->cometScript[] = [$this->job($asset, $status, 6)];
            $panel = $this->panel($this->show($asset)->getContent(), 'comet');
            $this->assertStringNotContainsString('data-attention-item="backup"', $panel, (string) $status);
            $this->assertStringContainsString('Nothing in the stored data for this source needs a look.', $panel);
        }
        // Unavailable is not a failure either.
        $asset = $this->cometAsset();
        $this->cometScript[] = new CometClientException('Comet API job lookup failed (code 0)');
        $this->assertStringNotContainsString('data-attention-item="backup"', $this->panel($this->show($asset)->getContent(), 'comet'));
    }

    // ── Comet: activity, read-only, no button (M-tog, M-btn) ──────────────────────────────────

    public function test_comet_recent_activity_links_the_backup_tab_job_table_and_does_not_copy_it(): void
    {
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];
        $html = $this->show($asset)->getContent();
        $panel = $this->panel($html, 'comet');

        $this->assertMatchesRegularExpression('/data-comet-jobs-link>Recent Backup Jobs ·\s*<a href="#tab-backup" data-show-tab="#tab-backup">View in Backup<\/a>/', $panel);
        // The job table (its Duration column and status badges) is not copied into the panel.
        $this->assertStringNotContainsString('<th>Duration</th>', $panel);
        $this->assertStringNotContainsString('">Success</span>', $panel);
        $this->assertStringContainsString('">Success</span>', $this->backupTab($html), 'positive control: the job row status badge');
        $this->assertStringNotContainsString('data-open-in', $panel);
        // The Backup tab keeps its job table (design-r3 §4).
        $backup = $this->backupTab($html);
        $this->assertStringContainsString('Recent Backup Jobs', $backup);
        $this->assertStringContainsString('<th>Duration</th>', $backup);
        $this->assertStringContainsString('Backup Storage (Comet)', $backup);
    }

    public function test_backup_toggles_stay_on_the_backup_tab_and_never_in_sources(): void
    {
        // M-tog: the toggles are vendor writes (design-r3 §5).
        $this->configureAllSources();
        $asset = $this->cometAsset(['servosity_backup_enabled' => true]);
        $asset->client->forceFill(['comet_group_id' => 'synthetic-group', 'servosity_company_id' => 4242])->save();
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];
        $html = $this->show($asset->refresh())->getContent();

        $sources = $this->sources($html);
        foreach ([route('assets.comet.toggle-backup', $asset), route('assets.servosity.toggle-backup', $asset)] as $url) {
            $this->assertStringNotContainsString($url, $sources, $url);
            $this->assertStringContainsString($url, $this->backupTab($html), 'positive control: '.$url);
        }
        $this->assertStringNotContainsString('<form', $this->panel($html, 'comet'));
        $this->assertStringNotContainsString('<form', $this->panel($html, 'servosity'));
        $this->assertStringNotContainsString('form-switch', $sources);
    }

    public function test_no_sync_now_on_comet_or_servosity(): void
    {
        // M-btn.
        $this->configureAllSources();
        $asset = $this->cometAsset();
        $this->cometScript[] = [$this->job($asset, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)];
        $html = $this->show($asset)->getContent();

        foreach (['comet', 'servosity'] as $source) {
            $this->assertStringNotContainsString('data-sync-now', $this->panel($html, $source), $source);
            $this->assertNotContains($source, SourceSyncPipeline::WIRED, $source);
            $this->assertNotContains($source, SourcePanel::SYNC_NOW_READY, $source);
        }
        $this->assertStringContainsString('data-sync-now="tactical"', $this->panel($html, 'tactical'), 'positive control');
    }

    // ── Servosity (M4B.3) ─────────────────────────────────────────────────────────────────

    public function test_servosity_panel_shows_no_run_outcome(): void
    {
        // M4B.3: the enabled flag only, with no age; no run outcome, ever.
        $this->configureAllSources();
        foreach ([true => 'Yes', false => 'No'] as $enabled => $word) {
            $panel = $this->panel($this->show($this->assetLinkedEverywhere(['servosity_backup_enabled' => (bool) $enabled]))->getContent(), 'servosity');

            $chip = $this->chip($panel, 'Backup enabled');
            $this->assertSame(['none', $word, ''], [$chip['kind'], $chip['value'], $chip['note']]);
            $this->assertMatchesRegularExpression('/data-no-outcome>Job outcome is not available from Servosity&#039;s API\.</', $panel);
            foreach (['succeeded', 'Succeeded', 'Last success', 'Last failure', 'Last backup', 'backup failed', 'Backup posture', 'data-attention-item'] as $claim) {
                $this->assertStringNotContainsString($claim, $panel, $claim);
            }
            $this->assertStringContainsString('Nothing in the stored data for this source needs a look.', $panel);
            $this->assertStringContainsString('Sync time not tracked for this source · updated by its scheduled sync; this source cannot be read for one asset', $panel);
            $this->assertStringNotContainsString('data-sync-now', $panel);
            $this->assertStringNotContainsString('data-open-in', $panel);
        }
        $this->assertCount(0, $this->cometCalls);
    }
}
