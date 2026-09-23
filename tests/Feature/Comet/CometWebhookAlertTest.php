<?php

namespace Tests\Feature\Comet;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\CometJobDisposition;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Comet\CometAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comet webhook → alert lifecycle (psa-enpew).
 *
 * Vendor-shaped payloads here are serialized by the VENDOR's own SDK classes
 * (\Comet\StreamableEvent + \Comet\BackupJobDetail via toArray()), so the
 * fixtures carry exactly the wire shape the vendored SDK defines — integer
 * Type 4201 (Def.php:2115 SEVT_JOB_COMPLETED), Data = the job object with
 * Classification on the 4000-range and Status on the 5000/6000/7000 ranges
 * (Def.php:610-841) — and cannot drift into the shape our code merely wishes
 * for (CLAUDE.md fixture rule). Each event carries a unique job GUID unless a
 * test pins one, exactly as real jobs do. Deliberately OFF-shape payloads
 * (missing identity, malformed EndTime) are raw arrays on purpose: they pin
 * the drop paths for payloads the SDK would never serialize.
 *
 * HONEST LIMIT: an SDK-serialized fixture proves what the SDK defines, not
 * what a live Comet webhook sends — no captured live payload exists, and prod
 * evidence (zero Comet alerts ever, thousands of webhook POSTs a day) proves
 * only that the OLD pipeline matched nothing, not which of its guards
 * (string Type, classification scale 4-vs-4001, exact status 7002) rejected
 * the traffic. These tests therefore pin BOTH classification scales, the full
 * status ranges, and the loud-drop behaviour, so the pipeline works whichever
 * shape arrives; the settings delivery stamps close the loop against real
 * traffic post-deploy.
 *
 * The response contract is pinned throughout: `status` is the service's real
 * disposition — a dropped or ignored event is NEVER reported as processed.
 */
class CometWebhookAlertTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookKey = 'test-comet-webhook-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'admin');
        Setting::setEncrypted('comet_admin_password', 'pw');
        Setting::setEncrypted('comet_webhook_key', $this->webhookKey);
    }

    /** Vendor-serialized SEVT_JOB_COMPLETED event wrapping a vendor-serialized job. */
    private function jobCompletedEvent(array $attrs = []): array
    {
        $job = new \Comet\BackupJobDetail;
        // Real jobs are GUID-unique; a repeated GUID is a replayed delivery.
        $job->GUID = $attrs['guid'] ?? (string) Str::uuid();
        $job->Username = $attrs['username'] ?? 'acme-backup';
        $job->DeviceID = $attrs['device'] ?? 'dev-1';
        $job->SourceGUID = $attrs['source_guid'] ?? 'item-1';
        $job->DestinationGUID = $attrs['destination_guid'] ?? 'vault-1';
        $job->Classification = $attrs['classification'] ?? \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $attrs['status'] ?? \Comet\Def::JOB_STATUS_FAILED_ERROR;
        $job->StartTime = $attrs['start'] ?? now()->subHours(2)->timestamp;
        $job->EndTime = $attrs['end'] ?? now()->subHour()->timestamp;
        $job->TotalSize = $attrs['total_size'] ?? 5 * (1024 ** 3);

        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_COMPLETED;
        $event->Timestamp = now()->timestamp;
        $event->Data = $job->toArray(false);

        return $event->toArray(false);
    }

    private function postEvent(array $payload)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->webhookKey}"])
            ->postJson('/api/webhooks/comet', $payload);
    }

    private function linkedAsset(array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'hostname' => 'ACME-SRV-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'dev-1',
        ], $overrides));
    }

    // ── The headline regression: real vendor events must land ─────────────────

    public function test_vendor_shaped_backup_failure_event_creates_an_alert(): void
    {
        $asset = $this->linkedAsset();

        $response = $this->postEvent($this->jobCompletedEvent());

        $response->assertOk()->assertJsonPath('status', 'alert_created');
        $this->assertNotNull($response->json('alert_id'));

        $alert = Alert::sole();
        $this->assertSame(AlertSource::Comet, $alert->source);
        $this->assertSame('dev-1:item-1:vault-1:backup', $alert->source_alert_id);
        $this->assertSame($asset->id, $alert->asset_id);
        $this->assertSame($asset->client_id, $alert->client_id);
        $this->assertSame('ACME-SRV-01', $alert->hostname);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame('Backup Failed (error) on ACME-SRV-01', $alert->title);
        $this->assertStringContainsString('Status: Failed (error)', $alert->message);
        $this->assertStringContainsString('Protected item: item-1', $alert->message);
        $this->assertStringContainsString('Storage vault: vault-1', $alert->message);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_ERROR, $alert->metadata['status']);
        $this->assertSame('item-1', $alert->metadata['source_guid']);
    }

    public function test_every_vendor_failure_subtype_raises_an_alert(): void
    {
        // The old code alerted on 7002 alone (and even that never fired in
        // prod). The vendor defines failure as the whole 7000-7999 range —
        // timeout, quota, and missed-schedule failures are exactly the ones
        // an MSP must see.
        $subtypes = [
            \Comet\Def::JOB_STATUS_FAILED_TIMEOUT,
            \Comet\Def::JOB_STATUS_FAILED_WARNING,
            \Comet\Def::JOB_STATUS_FAILED_ERROR,
            \Comet\Def::JOB_STATUS_FAILED_QUOTA,
            \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED,
            \Comet\Def::JOB_STATUS_FAILED_CANCELLED,
            \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING,
            \Comet\Def::JOB_STATUS_FAILED_ABANDONED,
        ];

        foreach ($subtypes as $i => $status) {
            $this->postEvent($this->jobCompletedEvent(['status' => $status, 'device' => "dev-{$i}"]))
                ->assertOk()->assertJsonPath('status', 'alert_created');
        }

        $this->assertSame(count($subtypes), Alert::count(), 'every failed-range subtype must raise an alert');
    }

    // ── Dual-scale classification: the pinned psa-enpew requirement ──────────
    // Production evidence proves the old pipeline matched nothing, NOT which
    // guard was responsible, so backup classification is accepted on BOTH the
    // SDK scale (4001) and the legacy small-int scale (4) — not a swap.

    public function test_legacy_scale_classification_4_with_integer_type_creates_an_alert(): void
    {
        $this->linkedAsset();

        $response = $this->postEvent($this->jobCompletedEvent(['classification' => 4]));

        $response->assertOk()->assertJsonPath('status', 'alert_created');
        $alert = Alert::sole();
        $this->assertSame(AlertStatus::Active, $alert->status);
        // Canonical key carries the scale-free 'backup' token, not the raw code
        $this->assertSame('dev-1:item-1:vault-1:backup', $alert->source_alert_id);
        $this->assertStringContainsString('Job type: Backup', $alert->message);
    }

    public function test_success_under_one_scale_resolves_a_failure_raised_under_the_other(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        // Raised under the SDK scale (4001)…
        $this->postEvent($this->jobCompletedEvent(['classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'end' => $failEnd]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        // …resolved by a success carrying the legacy scale (4).
        $this->postEvent($this->jobCompletedEvent([
            'classification' => 4,
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'end' => $failEnd + 600,
        ]))->assertOk()->assertJsonPath('status', 'alert_resolved');

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);
    }

    public function test_backup_success_resolves_the_open_alert_for_that_device(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $failEnd]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        $response = $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd + 600]));
        $response->assertOk()->assertJsonPath('status', 'alert_resolved');
        $this->assertNotNull($response->json('alert_id'));

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);
    }

    // ── Series identity, recurrence and event ordering ───────────────────────

    public function test_failure_success_later_failure_reopens_the_series_without_a_duplicate_key_error(): void
    {
        // The alerts table is unique on (source, source_alert_id): a second
        // incident on the same series must reopen the resolved row, not 500.
        $this->linkedAsset();
        $t0 = now()->subHours(3)->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 600]));
        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0 + 1200]))
            ->assertOk()->assertJsonPath('status', 'alert_reopened');

        $alert = Alert::sole()->fresh();
        $this->assertSame(AlertStatus::Active, $alert->status, 'a new failure after resolution reopens the series');
        $this->assertNull($alert->resolved_at);
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title);
        $this->assertSame(1, $alert->refired_count);
    }

    public function test_a_success_after_resolution_advances_the_watermark_so_a_delayed_old_failure_cannot_reopen(): void
    {
        // The psa-enpew.5 regression: failure@t0 → success@t0+600 (resolved)
        // → newer success@t0+1800 → DELAYED failure@t0+1200 arrives last.
        // Without recording the newer success on the resolved row, the stale
        // failure would reopen a series the newest event says is healthy.
        $this->linkedAsset();
        $t0 = now()->subHours(3)->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 600]))
            ->assertJsonPath('status', 'alert_resolved');

        // Newer success on the already-resolved series: watermark must advance.
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 1800]))
            ->assertOk()->assertJsonPath('status', 'no_open_alert');

        // The delayed older failure must now be provably stale.
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0 + 1200]))
            ->assertOk()->assertJsonPath('status', 'stale_ignored');

        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status,
            'a failure older than the newest success must not reopen the series');
    }

    public function test_refire_with_a_different_outcome_refreshes_the_alert_title(): void
    {
        // warning (7001) then quota (7003): one deduped alert whose title must
        // say quota — a stale title misstates the real failure state.
        $this->linkedAsset();
        $t0 = now()->subHours(2)->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'end' => $t0]));
        $this->assertSame('Backup Completed with warnings on ACME-SRV-01', Alert::sole()->title);

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0 + 600]))
            ->assertOk()->assertJsonPath('status', 'alert_refired');

        $alert = Alert::sole()->fresh();
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title);
        $this->assertStringContainsString('Failed (quota exceeded)', $alert->message);
        $this->assertSame(1, $alert->refired_count);
        $this->assertSame(AlertStatus::Active, $alert->status);
    }

    public function test_a_stale_success_cannot_resolve_a_newer_failure(): void
    {
        // Out-of-order/replayed delivery: a success that ENDED before the
        // recorded failure must not present a broken backup as recovered.
        Log::spy();
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd - 3600]))
            ->assertOk()->assertJsonPath('status', 'stale_ignored');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status, 'stale success must be rejected');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Stale/replayed success rejected'))
            ->atLeast()->once();
    }

    public function test_a_success_with_an_end_time_equal_to_the_failures_is_not_strictly_newer_and_does_not_resolve(): void
    {
        // psa-enpew.6: `<` let an equal-time success resolve. Equal cannot
        // prove the success postdates the failure — the alert stays open.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0]))
            ->assertOk()->assertJsonPath('status', 'stale_ignored');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status);
    }

    public function test_a_stale_replayed_failure_does_not_clobber_newer_state(): void
    {
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'end' => $t0 - 7200]))
            ->assertOk()->assertJsonPath('status', 'stale_ignored');

        $alert = Alert::sole()->fresh();
        $this->assertSame('Backup Failed (quota exceeded) on ACME-SRV-01', $alert->title, 'older replay must not rewrite the newer outcome');
        $this->assertSame(0, $alert->refired_count);
        $this->assertSame($t0, $alert->fired_at->timestamp);
    }

    public function test_a_replayed_delivery_with_the_same_job_guid_is_ignored(): void
    {
        // Comet retries deliveries; the same job GUID arriving again is the
        // same completion, never a second failure — refired_count must not
        // creep on network retries.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $event = $this->jobCompletedEvent(['guid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'end' => $t0]);
        $this->postEvent($event)->assertJsonPath('status', 'alert_created');
        $this->postEvent($event)->assertOk()->assertJsonPath('status', 'stale_ignored');

        $this->assertSame(0, Alert::sole()->fresh()->refired_count);
    }

    // ── The refusal REASON, on both arms of staleAgainst() (#3232) ────────────
    //
    // staleAgainst() refuses for two reasons and returns the first that
    // matches: `same_job_guid` (which never reads a timestamp) and
    // `not_strictly_newer`. Each of the three refusal records is emitted for
    // BOTH reasons, so none of them may describe a time comparison — a
    // replayed GUID can be arbitrarily NEWER than the recorded state.
    //
    // These tests assert the message by EQUALITY, not by banning the phrase
    // that was deleted: a control that bans one wording lets the next wording
    // of the same false claim through. And each sweeps EVERY captured record
    // for that site, because proving a clean record exists does not prove a
    // false one was not emitted beside it.

    /**
     * Capture every record the app really writes.
     *
     * Log::spy() + shouldHaveReceived()->withArgs() cannot be used to COLLECT
     * records: Mockery re-evaluates the matcher during verification, so each
     * write is seen more than once and a count assertion is meaningless.
     * Log::listen() fires exactly once per write, which is what "no false
     * record was emitted beside the clean one" needs.
     *
     * @var list<array{0:string,1:array}>
     */
    private array $captured = [];

    private function captureLogRecords(): void
    {
        $this->captured = [];
        Log::listen(function ($record) {
            $this->captured[] = [$record->message, $record->context];
        });
    }

    private function recordsMatching(string $level, string $needle): array
    {
        return array_values(array_filter(
            $this->captured,
            fn (array $r) => str_contains($r[0], $needle),
        ));
    }

    public function test_a_replayed_guid_newer_than_the_failure_is_refused_without_claiming_it_is_older(): void
    {
        $this->captureLogRecords();
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;
        $guid = 'aaaaaaaa-0000-4000-8000-00000000abcd';

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd, 'guid' => $guid,
        ]))->assertJsonPath('status', 'alert_created');

        // Same GUID = same completion arriving twice, so it must be refused —
        // but this delivery ENDED 9999s AFTER the failure, so no record of the
        // refusal may say it fails to postdate it.
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd + 9999, 'guid' => $guid,
        ]))->assertOk()->assertJsonPath('status', 'stale_ignored');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status, 'a replayed GUID must not resolve the alert');

        $records = $this->recordsMatching('warning', 'success rejected');
        $this->assertCount(1, $records, 'exactly one rejection record, so a false one cannot ride alongside a clean one');
        [$message, $context] = $records[0];
        $this->assertSame('[Comet Alert] Stale/replayed success rejected, alert stays open', $message);
        $this->assertSame('same_job_guid', $context['stale_because'] ?? null);
        // The comparands, so the refusal can be checked from the record alone.
        $this->assertSame($guid, $context['event_guid'] ?? null);
        $this->assertSame($guid, $context['last_event_guid'] ?? null);
        $this->assertSame($failEnd + 9999, $context['event_time'] ?? null);
        $this->assertSame($failEnd, $context['last_event_time'] ?? null);
    }

    public function test_the_timestamp_arm_is_reported_as_itself_at_every_site(): void
    {
        // Positive control for the tests above: the OTHER arm must keep
        // reporting itself, so deleting the clause cannot be mistaken for
        // losing the distinction between the two reasons. This also pins the
        // timestamp arm at the WARNING site; the two INFO sites are pinned by
        // the two tests that follow, so a site-local hardcoded stale_because
        // cannot survive at any of the three.
        $this->captureLogRecords();
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd]));
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $failEnd - 3600]))
            ->assertOk()->assertJsonPath('status', 'stale_ignored');

        $records = $this->recordsMatching('warning', 'success rejected');
        $this->assertCount(1, $records);
        [$message, $context] = $records[0];
        $this->assertSame('[Comet Alert] Stale/replayed success rejected, alert stays open', $message);
        $this->assertSame('not_strictly_newer', $context['stale_because'] ?? null, 'the timestamp arm must name itself, not the GUID arm');
        $this->assertSame($failEnd - 3600, $context['event_time'] ?? null);
        $this->assertSame($failEnd, $context['last_event_time'] ?? null);
    }

    public function test_a_replayed_guid_on_a_resolved_series_is_refused_without_claiming_newer_state(): void
    {
        $this->captureLogRecords();
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;
        $resolvingGuid = 'bbbbbbbb-0000-4000-8000-00000000beef';

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]))
            ->assertJsonPath('status', 'alert_created');
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 60, 'guid' => $resolvingGuid,
        ]))->assertOk();
        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status, 'precondition: the series is resolved');

        // Redelivery of the resolving success. The recorded state IS this very
        // event, so nothing newer has been recorded.
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 60 + 9999, 'guid' => $resolvingGuid,
        ]))->assertOk()->assertJsonPath('status', 'stale_ignored');

        $records = $this->recordsMatching('info', 'Stale/replayed success ignored');
        $this->assertCount(1, $records);
        [$message, $context] = $records[0];
        $this->assertSame('[Comet Alert] Stale/replayed success ignored', $message);
        $this->assertSame('same_job_guid', $context['stale_because'] ?? null);
        $this->assertSame($resolvingGuid, $context['last_event_guid'] ?? null);
    }

    public function test_the_timestamp_arm_is_reported_as_itself_on_the_resolved_path(): void
    {
        // Pins the not_strictly_newer arm at the second of the three sites.
        $this->captureLogRecords();
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0]))
            ->assertJsonPath('status', 'alert_created');
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 + 60, 'guid' => 'bbbbbbbb-0000-4000-8000-0000000000b2',
        ]))->assertOk();
        $this->assertSame(AlertStatus::Resolved, Alert::sole()->fresh()->status);

        // A DIFFERENT GUID that is older: the GUID arm cannot match, so this
        // must traverse the timestamp arm.
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'end' => $t0 - 3600, 'guid' => 'dddddddd-0000-4000-8000-0000000000d1',
        ]))->assertOk()->assertJsonPath('status', 'stale_ignored');

        $records = $this->recordsMatching('info', 'Stale/replayed success ignored');
        $this->assertCount(1, $records);
        $this->assertSame('not_strictly_newer', $records[0][1]['stale_because'] ?? null);
    }

    public function test_a_replayed_guid_on_the_failure_path_is_refused_without_claiming_newer_or_equal_state(): void
    {
        $this->captureLogRecords();
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;
        $guid = 'cccccccc-0000-4000-8000-00000000cafe';

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0, 'guid' => $guid,
        ]))->assertJsonPath('status', 'alert_created');

        // Replayed GUID, 9999s newer: the recorded state is neither newer nor
        // equal. FAILED_ABANDONED is the vendor's own "stopped unexpectedly or
        // manually marked abandoned" status, so a second record for one job
        // GUID carrying a different status and end time is traffic Comet
        // really produces, not a synthetic payload.
        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_FAILED_ABANDONED, 'end' => $t0 + 9999, 'guid' => $guid,
        ]))->assertOk()->assertJsonPath('status', 'stale_ignored');

        $records = $this->recordsMatching('info', 'failure event ignored');
        $this->assertCount(1, $records);
        [$message, $context] = $records[0];
        $this->assertSame('[Comet Alert] Stale/replayed failure event ignored', $message);
        $this->assertSame('same_job_guid', $context['stale_because'] ?? null);
        $this->assertSame($t0 + 9999, $context['event_time'] ?? null);
        $this->assertSame($t0, $context['last_event_time'] ?? null);
    }

    public function test_the_timestamp_arm_is_reported_as_itself_on_the_failure_path(): void
    {
        // Pins the not_strictly_newer arm at the third site.
        $this->captureLogRecords();
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $t0, 'guid' => 'cccccccc-0000-4000-8000-0000000000c2',
        ]))->assertJsonPath('status', 'alert_created');

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'end' => $t0 - 3600, 'guid' => 'eeeeeeee-0000-4000-8000-0000000000e1',
        ]))->assertOk()->assertJsonPath('status', 'stale_ignored');

        $records = $this->recordsMatching('info', 'failure event ignored');
        $this->assertCount(1, $records);
        $this->assertSame('not_strictly_newer', $records[0][1]['stale_because'] ?? null);
    }

    public function test_success_for_one_protected_item_does_not_resolve_another_items_failure(): void
    {
        // A device can back up multiple protected items to multiple vaults;
        // item B succeeding says nothing about item A. Item B has no series
        // row of its own, so its success is an honest no_open_alert.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-A', 'end' => $t0]));
        $this->postEvent($this->jobCompletedEvent([
            'source_guid' => 'item-B',
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'end' => $t0 + 600,
        ]))->assertOk()->assertJsonPath('status', 'no_open_alert');

        $alert = Alert::sole();
        $this->assertSame('dev-1:item-A:vault-1:backup', $alert->source_alert_id);
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status, "item B's success must not clear item A's alert");
    }

    public function test_two_protected_items_failing_raise_two_separate_alerts(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-A']));
        $this->postEvent($this->jobCompletedEvent(['source_guid' => 'item-B']));

        $this->assertSame(2, Alert::count());
        $this->assertEqualsCanonicalizing(
            ['dev-1:item-A:vault-1:backup', 'dev-1:item-B:vault-1:backup'],
            Alert::pluck('source_alert_id')->all(),
        );
    }

    public function test_unmatched_device_still_creates_an_unlinked_alert(): void
    {
        $this->postEvent($this->jobCompletedEvent(['device' => 'never-synced', 'username' => 'orphan-user']))
            ->assertOk()->assertJsonPath('status', 'alert_created');

        $alert = Alert::sole();
        $this->assertNull($alert->asset_id);
        $this->assertNull($alert->client_id);
        $this->assertSame('orphan-user', $alert->hostname);
    }

    // ── Series identity is required, never degraded (psa-enpew.6) ────────────

    public function test_failure_without_device_identity_is_dropped_loudly_not_keyed_blind(): void
    {
        // Without a DeviceID there is no series to key — fail loud, no write.
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['device' => '']))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'missing_device_identity')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_device_identity')
            ->atLeast()->once();
    }

    public function test_failure_missing_either_identity_guid_is_dropped_not_degraded_to_a_device_wide_key(): void
    {
        // Coercing a missing SourceGUID/DestinationGUID to '' would key the
        // series device-wide — the exact degradation psa-enpew.6 forbids.
        Log::spy();

        foreach ([
            ['source_guid' => ''],
            ['destination_guid' => ''],
            ['source_guid' => '', 'destination_guid' => ''],
        ] as $broken) {
            $this->postEvent($this->jobCompletedEvent($broken))
                ->assertOk()
                ->assertJsonPath('status', 'dropped_unmatched')
                ->assertJsonPath('reason', 'missing_series_identity');
        }

        $this->assertSame(0, Alert::count(), 'no device-level fallback alert may exist');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_series_identity')
            ->atLeast()->times(3);
    }

    public function test_a_success_with_missing_identity_cannot_resolve_an_open_failure(): void
    {
        // The .6 adversarial probe: real failure with full identity, then an
        // empty-identity success. Under the old device-level fallback both
        // mapped to the same degraded key and the failure was cleared.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['end' => $t0]));
        $this->assertSame(AlertStatus::Active, Alert::sole()->status);

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'source_guid' => '',
            'destination_guid' => '',
            'end' => $t0 + 600,
        ]))->assertOk()->assertJsonPath('status', 'dropped_unmatched');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status,
            'an identityless success must never resolve an open failure');
    }

    // ── EndTime is the ordering authority; unorderable events are dropped ────

    public function test_a_success_with_no_orderable_end_time_never_resolves(): void
    {
        // psa-enpew.6: substituting now() for a missing/zero EndTime made an
        // unorderable success look newest and resolve a real failure. The SDK
        // defaults EndTime to 0 when unset — that is not an orderable time.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['end' => $t0]));

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'end' => 0,
        ]))->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'unorderable_end_time');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status);
    }

    public function test_a_success_with_a_malformed_end_time_never_resolves(): void
    {
        // Deliberately off-shape (raw array): a string EndTime cannot order
        // events, so it must drop loudly instead of resolving.
        $this->linkedAsset();
        $t0 = now()->subHour()->timestamp;

        $this->postEvent($this->jobCompletedEvent(['end' => $t0]));

        $this->postEvent([
            'Type' => \Comet\Def::SEVT_JOB_COMPLETED,
            'Data' => [
                'GUID' => (string) Str::uuid(),
                'DeviceID' => 'dev-1',
                'SourceGUID' => 'item-1',
                'DestinationGUID' => 'vault-1',
                'Classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP,
                'Status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
                'EndTime' => 'not-a-timestamp',
            ],
        ])->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'unorderable_end_time');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status);
    }

    public function test_a_failure_with_no_orderable_end_time_is_dropped_loudly(): void
    {
        // Symmetric with the success side: alerting on an unorderable failure
        // with a substituted receipt time would poison the series watermark
        // (and re-fire on every retry). The drop is loud — WARNING + the
        // operator-card unmatched stamp — never silent.
        Log::spy();
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['end' => 0]))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'unorderable_end_time');

        $this->assertSame(0, Alert::count());
        $this->assertNotNull(Setting::getValue('comet_webhook_last_unmatched_at'));
    }

    // ── The loud unmatched-event signal (silent non-matching was the defect) ──

    public function test_job_completed_with_non_integer_status_warns_and_is_dropped(): void
    {
        Log::spy();

        $this->postEvent([
            'Type' => \Comet\Def::SEVT_JOB_COMPLETED,
            'Data' => ['DeviceID' => 'dev-1', 'Classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'Status' => 'failed'],
        ])->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'missing_or_non_integer_status')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_or_non_integer_status')
            ->atLeast()->once();
    }

    public function test_job_completed_with_status_outside_every_known_range_warns(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['status' => 4242]))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'status_outside_known_ranges')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'status_outside_known_ranges')
            ->atLeast()->once();
    }

    public function test_job_completed_with_unrecognized_classification_warns(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['classification' => 9999]))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'unrecognized_classification')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'unrecognized_classification')
            ->atLeast()->once();
    }

    public function test_a_running_range_status_on_a_completed_event_is_a_loud_contradiction(): void
    {
        // A job-COMPLETED event carrying a running-range status matches no
        // completion route. The old INFO line was invisible under prod's
        // LOG_LEVEL=warning — psa-enpew.8 requires this pairing to be loud.
        Log::spy();
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_RUNNING_ACTIVE]))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'running_status_on_completed_event')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'running_status_on_completed_event')
            ->atLeast()->once();
    }

    public function test_job_completed_event_without_data_payload_is_a_loud_drop(): void
    {
        Log::spy();

        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_COMPLETED;

        $this->postEvent($event->toArray(false))
            ->assertOk()
            ->assertJsonPath('status', 'dropped_unmatched')
            ->assertJsonPath('reason', 'missing_job_data');

        $this->assertSame(0, Alert::count());
        $this->assertNotNull(Setting::getValue('comet_webhook_last_unmatched_at'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'matched no route')
                && ($context['reason'] ?? null) === 'missing_job_data')
            ->atLeast()->once();
    }

    public function test_the_warning_context_carries_only_safe_structural_fields(): void
    {
        Log::spy();

        $this->postEvent($this->jobCompletedEvent(['classification' => 9999, 'username' => 'real-customer-name']));

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                if (! str_contains($message, 'matched no route')) {
                    return false;
                }
                // Bounded: reason + classification + status + identity-field
                // presence booleans, never usernames/hostnames/payload bodies.
                $this->assertEqualsCanonicalizing(
                    ['reason', 'classification', 'status', 'has_device_id', 'has_source_guid', 'has_destination_guid'],
                    array_keys($context),
                );

                return true;
            })
            ->atLeast()->once();
    }

    // ── Deliberate ignores must NOT warn (8.6k events/day would flood) ───────

    public function test_known_non_backup_classifications_are_ignored_without_warning(): void
    {
        Log::spy();
        $this->linkedAsset();

        foreach ([
            \Comet\Def::JOB_CLASSIFICATION_RESTORE,
            \Comet\Def::JOB_CLASSIFICATION_RETENTION,
            5, // legacy restore
            7, // legacy retention
        ] as $classification) {
            $this->postEvent($this->jobCompletedEvent(['classification' => $classification]))
                ->assertOk()
                ->assertJsonPath('status', 'ignored_non_backup')
                ->assertJsonPath('alert_id', null);
        }

        $this->assertSame(0, Alert::count());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_other_sevt_event_families_are_ignored_without_warning(): void
    {
        Log::spy();

        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_NEW;
        $this->postEvent($event->toArray(false))->assertOk()->assertJsonPath('status', 'ignored_unhandled_event_type');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_non_backup_success_does_not_resolve_a_backup_alert(): void
    {
        $this->linkedAsset();
        $failEnd = now()->subHour()->timestamp;
        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'end' => $failEnd]));

        $this->postEvent($this->jobCompletedEvent([
            'status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS,
            'classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION,
            'end' => $failEnd + 600,
        ]))->assertOk()->assertJsonPath('status', 'ignored_non_backup');

        $this->assertSame(AlertStatus::Active, Alert::sole()->fresh()->status,
            'a successful retention pass must not clear a backup-failure alert');
    }

    public function test_a_string_type_event_is_not_routed_and_warns_as_unrecognized(): void
    {
        // The old code matched Type === 'job.completed'; the vendored SDK
        // defines Type as an integer SEVT_ code. Whether any Comet build ever
        // sent the string form is unverified — what is pinned here is that an
        // out-of-catalog Type is never processed as a job AND never dropped
        // silently.
        Log::spy();
        $this->linkedAsset();

        $this->postEvent([
            'Type' => 'job.completed',
            'Data' => [
                'Username' => 'acme-backup',
                'DeviceID' => 'dev-1',
                'Classification' => 4,
                'Status' => 7002,
            ],
        ])->assertOk()->assertJsonPath('status', 'ignored_unrecognized_event_type');

        $this->assertSame(0, Alert::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unrecognized event Type'))
            ->atLeast()->once();
    }

    public function test_alerts_disabled_setting_reports_itself_and_suppresses_alert_creation(): void
    {
        Setting::setValue('comet_alert_enabled', '0');
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent())
            ->assertOk()
            ->assertJsonPath('status', 'alerts_disabled')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
    }

    public function test_success_without_prior_alert_is_an_honest_no_open_alert(): void
    {
        $this->linkedAsset();

        $this->postEvent($this->jobCompletedEvent(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS]))
            ->assertOk()
            ->assertJsonPath('status', 'no_open_alert')
            ->assertJsonPath('alert_id', null);

        $this->assertSame(0, Alert::count());
    }

    public function test_the_service_reports_dispositions_for_direct_malformed_payloads(): void
    {
        $service = app(CometAlertService::class);

        $outcome = $service->handleJobCompleted(['Status' => 'failed']);
        $this->assertSame(CometJobDisposition::DroppedUnmatched, $outcome->disposition);
        $this->assertNull($outcome->alert);

        $outcome = $service->handleJobCompleted([]);
        $this->assertSame(CometJobDisposition::DroppedUnmatched, $outcome->disposition);
        $this->assertSame('missing_or_non_integer_status', $outcome->reason);
    }

    // ── Operator-visible delivery proof ───────────────────────────────────────

    public function test_webhook_delivery_stamps_tell_received_recognized_unmatched_and_alert_apart(): void
    {
        $this->linkedAsset();
        $this->assertNull(Setting::getValue('comet_webhook_last_received_at'));

        // A non-job event advances "received" and nothing else.
        $event = new \Comet\StreamableEvent;
        $event->Type = \Comet\Def::SEVT_JOB_NEW;
        $this->postEvent($event->toArray(false));

        $this->assertNotNull(Setting::getValue('comet_webhook_last_received_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_recognized_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_unmatched_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_alert_at'));

        // An unrouteable job event advances "unmatched" — NOT "recognized":
        // a matcher break must never render as recognized traffic (psa-enpew.7).
        $this->postEvent($this->jobCompletedEvent(['classification' => 9999]));

        $this->assertNull(Setting::getValue('comet_webhook_last_recognized_at'));
        $this->assertNotNull(Setting::getValue('comet_webhook_last_unmatched_at'));
        $this->assertSame('unrecognized_classification', Setting::getValue('comet_webhook_last_unmatched_reason'));
        $this->assertNull(Setting::getValue('comet_webhook_last_alert_at'));

        // A deliberately-ignored non-backup completion IS recognized traffic,
        // but raises no alert.
        $this->postEvent($this->jobCompletedEvent(['classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION]));

        $this->assertNotNull(Setting::getValue('comet_webhook_last_recognized_at'));
        $this->assertNull(Setting::getValue('comet_webhook_last_alert_at'));

        // A routed backup failure advances the alert stamp.
        $this->postEvent($this->jobCompletedEvent());

        $this->assertNotNull(Setting::getValue('comet_webhook_last_alert_at'));
    }

    // ── Auth + error handling ─────────────────────────────────────────────────

    public function test_missing_or_wrong_webhook_key_is_rejected(): void
    {
        $this->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer wrong-key'])
            ->postJson('/api/webhooks/comet', $this->jobCompletedEvent())->assertStatus(401);

        $this->assertSame(0, Alert::count());
    }

    public function test_processing_errors_return_a_generic_message_not_exception_details(): void
    {
        $this->mock(CometAlertService::class)
            ->shouldReceive('handleJobCompleted')
            ->andThrow(new \RuntimeException('SQLSTATE[42S02]: secret table detail'));

        $response = $this->postEvent($this->jobCompletedEvent());

        $response->assertStatus(500)->assertJsonPath('message', 'Internal error processing webhook.');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }
}
