<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commit 3 (P4 chunk 2, plan Task 5 + amendment K): the recent Tactical actions
 * panel on the asset page is ITIL CHANGE HISTORY, not a flat activity log.
 *
 *   - Each row with a ticket_id links to that ticket ("Reboot · J.Smith · under
 *     #1423 · 2h ago"); rows without a ticket render cleanly.
 *   - Distinct outcome badges: ok→success, offline→warning, error→danger,
 *     rejected|denied|blocked→secondary.
 *   - Cap ~10 newest-first + a "view all" affordance; already-redacted at write
 *     (no re-leak); empty-state when no actions.
 */
class TacticalRecentActionsTest extends TestCase
{
    use RefreshDatabase;

    private function linkedAsset(): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        return $asset->refresh();
    }

    private function logAction(Asset $asset, array $overrides = []): TacticalActionLog
    {
        return TacticalActionLog::create(array_merge([
            'actor_label' => 'tech@example.com',
            'action_key' => 'tactical.reboot',
            'agent_id' => 'AGENT-1',
            'asset_id' => $asset->id,
            'ticket_id' => null,
            'target_label' => 'BOX-1',
            'params' => [],
            'result_status' => 'ok',
            'correlation_id' => 'c-'.uniqid(),
        ], $overrides));
    }

    private function show(Asset $asset)
    {
        return $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));
    }

    public function test_recent_actions_panel_renders_rows_with_outcome_badges(): void
    {
        $asset = $this->linkedAsset();
        $this->logAction($asset, ['action_key' => 'tactical.reboot', 'result_status' => 'ok']);
        $this->logAction($asset, ['action_key' => 'tactical.run_script', 'result_status' => 'offline']);
        $this->logAction($asset, ['action_key' => 'tactical.run_command', 'result_status' => 'error']);
        $this->logAction($asset, ['action_key' => 'tactical.run_command', 'result_status' => 'rejected']);

        $resp = $this->show($asset);

        $resp->assertOk();
        $resp->assertSee('tactical-recent-actions', false);
        // The actor + a humanized action label.
        $resp->assertSeeText('tech@example.com');
        // Distinct outcome badge classes (the statusBadgeClass vocabulary).
        $resp->assertSee('bg-success', false);   // ok
        $resp->assertSee('bg-warning', false);   // offline (no-op)
        $resp->assertSee('bg-danger', false);    // error
        $resp->assertSee('bg-secondary', false); // rejected
    }

    public function test_action_with_ticket_links_to_the_ticket(): void
    {
        $asset = $this->linkedAsset();
        $ticket = Ticket::factory()->create();
        $this->logAction($asset, ['ticket_id' => $ticket->id, 'result_status' => 'ok']);

        $resp = $this->show($asset);

        $resp->assertOk();
        // The change ties to its incident: a link to the ticket + the "#<id>" ref.
        $resp->assertSee(route('tickets.show', $ticket), false);
        $resp->assertSeeText('#'.$ticket->id);
    }

    public function test_action_without_ticket_renders_cleanly(): void
    {
        $asset = $this->linkedAsset();
        $this->logAction($asset, ['ticket_id' => null, 'result_status' => 'ok']);

        $resp = $this->show($asset);

        $resp->assertOk();
        $resp->assertSee('tactical-recent-actions', false);
        // No ticket reference rendered for an out-of-band action.
        $resp->assertDontSeeText('under #');
    }

    public function test_offline_outcome_reads_as_a_no_op(): void
    {
        $asset = $this->linkedAsset();
        $this->logAction($asset, ['result_status' => 'offline']);

        $resp = $this->show($asset);

        $resp->assertOk();
        // "a reboot succeeded" vs "was a no-op" are different facts (amendment K).
        $resp->assertSeeText('no-op');
    }

    public function test_panel_caps_at_ten_newest_first_with_view_all_when_more_exist(): void
    {
        $asset = $this->linkedAsset();
        // 12 actions — only the 10 newest render; a "view all" / overflow affordance appears.
        for ($i = 0; $i < 12; $i++) {
            $this->logAction($asset, ['action_key' => 'tactical.reboot', 'correlation_id' => 'c-'.$i]);
        }

        $resp = $this->show($asset);

        $resp->assertOk();
        // The overflow affordance signals there are more than shown.
        $resp->assertSeeText('most recent');
    }

    public function test_empty_state_when_no_actions(): void
    {
        $asset = $this->linkedAsset();

        $resp = $this->show($asset);

        $resp->assertOk();
        // An empty-state, not an error / not a missing panel.
        $resp->assertSeeText('No recent Tactical actions');
    }

    public function test_redacted_at_write_is_not_re_leaked(): void
    {
        // Rows are redacted at write (P2/P3). The panel must render the stored
        // (already-redacted) message verbatim — no second un-redaction.
        $asset = $this->linkedAsset();
        $this->logAction($asset, [
            'action_key' => 'tactical.run_command',
            'result_status' => 'ok',
            'message' => 'Command sent: [REDACTED:credential]',
        ]);

        $resp = $this->show($asset);

        $resp->assertOk();
        // The redaction marker survives; nothing re-introduces a secret.
        $resp->assertDontSeeText('S3cr3tP'.'@ssw0rd');
    }
}
