<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `agent:eval-close-band` (psa-91f2) — the operator-facing calibration report.
 * A thin, read-only renderer over CloseBandEvaluator: it prints the per-band
 * approval table so a human can read which confidence band is auto-safe.
 *
 * Assertions run against Artisan::output() rather than the PendingCommand
 * expectsOutput* helpers, which do not reliably capture $this->table() output.
 */
class AgentEvalCloseBandCommandTest extends TestCase
{
    use RefreshDatabase;

    private function closeRun(float $confidence, TechnicianRunState $state): TechnicianRun
    {
        static $seq = 0;
        $seq++;
        $ticket = Ticket::factory()->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'cmd-test:'.$seq),
            'state' => $state,
            'proposed_content' => 'reason',
            'proposed_meta' => ['confidence' => $confidence],
            'confidence' => $confidence,
        ]);
    }

    public function test_it_renders_the_band_table_with_computed_rates_and_succeeds(): void
    {
        $this->closeRun(0.97, TechnicianRunState::Done);   // 0.95–1.00 band → 1/1 approved
        $this->closeRun(0.82, TechnicianRunState::Denied); // 0.80–0.90 band → 0/1 approved

        $code = Artisan::call('agent:eval-close-band');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('0.95–1.00', $output, 'a band label is rendered');
        $this->assertStringContainsString('100.0%', $output, 'the top band approve rate (1/1)');
        $this->assertStringContainsString('0.0%', $output, 'the 0.80–0.90 band approve rate (0/1)');
    }

    public function test_it_succeeds_on_an_empty_dataset(): void
    {
        $code = Artisan::call('agent:eval-close-band');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No held propose_close proposals', Artisan::output());
    }

    public function test_it_shows_an_other_column_for_edge_states(): void
    {
        $this->closeRun(0.97, TechnicianRunState::Done);
        $this->closeRun(0.96, TechnicianRunState::Cancelled); // edge state → the Other bucket

        $code = Artisan::call('agent:eval-close-band');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Other', Artisan::output(),
            'the Other column is rendered so N reconciles when edge states occur');
    }

    public function test_since_option_scopes_the_report_to_the_window(): void
    {
        $old = $this->closeRun(0.97, TechnicianRunState::Denied);
        TechnicianRun::where('id', $old->id)->update(['created_at' => now()->subDays(30)]);

        $code = Artisan::call('agent:eval-close-band', ['--since' => 14]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No held propose_close proposals', Artisan::output(),
            'the only run is older than the 14-day window, so nothing is scored');
    }

    public function test_since_rejects_a_non_positive_value(): void
    {
        $code = Artisan::call('agent:eval-close-band', ['--since' => 0]);

        $this->assertSame(1, $code, 'a non-positive --since is a usage error');
        $this->assertStringContainsString('positive', Artisan::output());
    }
}
