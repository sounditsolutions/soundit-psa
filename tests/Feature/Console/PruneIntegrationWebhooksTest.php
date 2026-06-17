<?php

namespace Tests\Feature\Console;

use App\Models\NinjaWebhook;
use App\Models\TacticalWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneIntegrationWebhooksTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function tacticalWebhook(string $status, string $createdAt): TacticalWebhook
    {
        // created_at/updated_at are not in $fillable, so use DB::table to seed
        // a controlled timestamp and then retrieve the hydrated model.
        $id = DB::table('tactical_webhooks')->insertGetId([
            'event'      => 'alert_failure',
            'agent_id'   => 'agent-1',
            'payload'    => json_encode(['test' => true]),
            'status'     => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return TacticalWebhook::findOrFail($id);
    }

    private function ninjaWebhook(string $status, string $createdAt): NinjaWebhook
    {
        $id = DB::table('ninja_webhooks')->insertGetId([
            'activity_type'   => 'DEVICE_STATUS_CHANGE',
            'ninja_device_id' => 1,
            'payload'         => json_encode(['test' => true]),
            'status'          => $status,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
        ]);

        return NinjaWebhook::findOrFail($id);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** Old + processed rows from both tables are deleted. */
    public function test_deletes_old_terminal_rows_from_both_tables(): void
    {
        $old = now()->subDays(31)->toDateTimeString();

        $tacticalOldProcessed = $this->tacticalWebhook('processed', $old);
        $tacticalOldSkipped   = $this->tacticalWebhook('skipped',   $old);
        $tacticalOldFailed    = $this->tacticalWebhook('failed',     $old);
        $ninjaOldProcessed    = $this->ninjaWebhook('processed', $old);
        $ninjaOldSkipped      = $this->ninjaWebhook('skipped',   $old);
        $ninjaOldFailed       = $this->ninjaWebhook('failed',    $old);

        $this->artisan('integrations:prune-webhooks')->assertSuccessful();

        $this->assertDatabaseMissing('tactical_webhooks', ['id' => $tacticalOldProcessed->id]);
        $this->assertDatabaseMissing('tactical_webhooks', ['id' => $tacticalOldSkipped->id]);
        $this->assertDatabaseMissing('tactical_webhooks', ['id' => $tacticalOldFailed->id]);
        $this->assertDatabaseMissing('ninja_webhooks',    ['id' => $ninjaOldProcessed->id]);
        $this->assertDatabaseMissing('ninja_webhooks',    ['id' => $ninjaOldSkipped->id]);
        $this->assertDatabaseMissing('ninja_webhooks',    ['id' => $ninjaOldFailed->id]);
    }

    /** Recent processed rows (within the 30-day window) are kept. */
    public function test_keeps_recent_terminal_rows(): void
    {
        $recent = now()->subDays(5)->toDateTimeString();

        $tacticalRecent = $this->tacticalWebhook('processed', $recent);
        $ninjaRecent    = $this->ninjaWebhook('processed',    $recent);

        $this->artisan('integrations:prune-webhooks')->assertSuccessful();

        $this->assertDatabaseHas('tactical_webhooks', ['id' => $tacticalRecent->id]);
        $this->assertDatabaseHas('ninja_webhooks',    ['id' => $ninjaRecent->id]);
    }

    /** Old pending rows are never deleted regardless of age. */
    public function test_keeps_old_pending_rows(): void
    {
        $old = now()->subDays(60)->toDateTimeString();

        $tacticalOldPending = $this->tacticalWebhook('pending', $old);
        $ninjaOldPending    = $this->ninjaWebhook('pending',    $old);

        $this->artisan('integrations:prune-webhooks')->assertSuccessful();

        $this->assertDatabaseHas('tactical_webhooks', ['id' => $tacticalOldPending->id]);
        $this->assertDatabaseHas('ninja_webhooks',    ['id' => $ninjaOldPending->id]);
    }

    /** --dry-run reports the count but deletes nothing. */
    public function test_dry_run_reports_count_without_deleting(): void
    {
        $old = now()->subDays(31)->toDateTimeString();

        $tw = $this->tacticalWebhook('processed', $old);
        $nw = $this->ninjaWebhook('processed',    $old);

        $this->artisan('integrations:prune-webhooks --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        // Nothing deleted
        $this->assertDatabaseHas('tactical_webhooks', ['id' => $tw->id]);
        $this->assertDatabaseHas('ninja_webhooks',    ['id' => $nw->id]);
    }

    /** Output mentions both table names when rows are actually deleted. */
    public function test_output_reports_deleted_counts(): void
    {
        $old = now()->subDays(31)->toDateTimeString();

        $this->tacticalWebhook('processed', $old);
        $this->ninjaWebhook('processed',    $old);

        // Use expectsOutputToContain for a combined token present in both table names.
        // The info line is: "Pruned N row(s) from tactical_webhooks and N row(s) from ninja_webhooks ..."
        // We chain both expectations before assertSuccessful() triggers the run.
        $this->artisan('integrations:prune-webhooks')
            ->expectsOutputToContain('tactical_webhooks')
            ->assertSuccessful();

        // Verify ninja table name also appeared — do it in a second run so the
        // mock output expectations are set up fresh before the command executes.
        $this->artisan('integrations:prune-webhooks')
            ->expectsOutputToContain('ninja_webhooks')
            ->assertSuccessful();
    }
}
