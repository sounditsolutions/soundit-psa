<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\CloseBandEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CloseBandEvaluator (psa-91f2) — the repeatable calibration instrument.
 *
 * Buckets held propose_close TechnicianRuns by Chet's self-reported confidence
 * and tallies the operator OUTCOME (terminal run state) per band, so we can read
 * the approval rate BY CONFIDENCE BAND and identify the "auto-safe" bucket that
 * would defensibly set propose_close_auto_threshold. The operator's approve/decline
 * IS the label — no separate labelled set required for this instrument.
 */
class CloseBandEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    /** Create a propose_close run at a given confidence + terminal state. */
    private function closeRun(float $confidence, TechnicianRunState $state, string $actionType = 'propose_close'): TechnicianRun
    {
        return $this->rawRun($confidence, $state, $actionType);
    }

    /** Lower-level: allows a null confidence (which closeRun's float signature forbids). */
    private function rawRun(?float $confidence, TechnicianRunState $state, string $actionType = 'propose_close'): TechnicianRun
    {
        static $seq = 0;
        $seq++;
        $ticket = Ticket::factory()->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', 'eval-test:'.$seq),
            'state' => $state,
            'proposed_content' => 'reason',
            'proposed_meta' => $confidence === null ? [] : ['confidence' => $confidence],
            'confidence' => $confidence,
        ]);
    }

    /** The single band whose [low, high) window covers $needle. */
    private function bandCovering(array $bands, float $needle): object
    {
        foreach ($bands as $b) {
            if ($needle >= $b->low && $needle < $b->high) {
                return $b;
            }
        }
        $this->fail("No band covers confidence {$needle}");
    }

    private function totalAcross(array $bands): int
    {
        return array_sum(array_map(fn ($b) => $b->total, $bands));
    }

    // ── outcome mapping ──────────────────────────────────────────────────────

    public function test_an_approved_close_is_bucketed_into_its_band_and_counted_as_approved(): void
    {
        $this->closeRun(0.97, TechnicianRunState::Done);

        $bands = app(CloseBandEvaluator::class)->evaluate();

        $top = $this->bandCovering($bands, 0.97);
        $this->assertSame(1, $top->total, 'the 0.95+ band must contain the one run');
        $this->assertSame(1, $top->approved, 'a Done propose_close counts as approved');
        $this->assertSame(0, $top->declined);
        $this->assertSame(1.0, $top->approveRate(), 'approveRate = approved / (approved + declined)');
    }

    public function test_a_declined_close_counts_as_declined_and_lowers_the_approve_rate(): void
    {
        // Same band [0.90, 0.95): three approved, one declined.
        $this->closeRun(0.91, TechnicianRunState::Done);
        $this->closeRun(0.92, TechnicianRunState::Done);
        $this->closeRun(0.93, TechnicianRunState::Done);
        $this->closeRun(0.94, TechnicianRunState::Denied);

        $band = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(), 0.92);

        $this->assertSame(4, $band->total);
        $this->assertSame(3, $band->approved);
        $this->assertSame(1, $band->declined);
        $this->assertSame(0.75, $band->approveRate());
    }

    public function test_a_corrected_close_is_counted_separately_and_excluded_from_the_approve_rate(): void
    {
        $this->closeRun(0.82, TechnicianRunState::Done);
        $this->closeRun(0.83, TechnicianRunState::Superseded); // operator corrected/re-assessed

        $band = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(), 0.82);

        $this->assertSame(2, $band->total);
        $this->assertSame(1, $band->approved);
        $this->assertSame(1, $band->corrected);
        $this->assertSame(0, $band->declined);
        $this->assertSame(1.0, $band->approveRate(), 'corrected is not a clean decline; it stays out of the denominator');
    }

    public function test_a_pending_proposal_is_counted_but_excluded_from_the_approve_rate(): void
    {
        $this->closeRun(0.72, TechnicianRunState::Done);
        $this->closeRun(0.73, TechnicianRunState::AwaitingApproval); // still held — no verdict yet

        $band = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(), 0.72);

        $this->assertSame(2, $band->total);
        $this->assertSame(1, $band->approved);
        $this->assertSame(1, $band->pending);
        $this->assertSame(1.0, $band->approveRate(), 'a pending proposal has no verdict, so it is not in the denominator');
    }

    public function test_a_band_with_no_decided_proposals_has_a_null_approve_rate(): void
    {
        $this->closeRun(0.61, TechnicianRunState::AwaitingApproval);

        $band = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(), 0.61);

        $this->assertSame(1, $band->total);
        $this->assertNull($band->approveRate(), 'no decided proposals → no rate (reported as —, never 0%)');
    }

    // ── band boundaries: low-inclusive, high-exclusive ───────────────────────

    public function test_band_edges_are_low_inclusive_and_high_exclusive(): void
    {
        // Each confidence sits exactly on a band's lower edge — it belongs to THAT band.
        $this->closeRun(0.50, TechnicianRunState::Done);
        $this->closeRun(0.70, TechnicianRunState::Done);
        $this->closeRun(0.80, TechnicianRunState::Done);
        $this->closeRun(0.90, TechnicianRunState::Done);
        $this->closeRun(0.95, TechnicianRunState::Done);
        $this->closeRun(1.00, TechnicianRunState::Done); // the ceiling lands in the top band

        $bands = app(CloseBandEvaluator::class)->evaluate();

        $this->assertSame(0.50, $this->bandCovering($bands, 0.50)->low);
        $this->assertSame(0.70, $this->bandCovering($bands, 0.70)->low);
        $this->assertSame(0.80, $this->bandCovering($bands, 0.80)->low);
        $this->assertSame(0.90, $this->bandCovering($bands, 0.90)->low, '0.90 is the auto-floor edge → belongs to [0.90,0.95), not [0.80,0.90)');
        $this->assertSame(0.95, $this->bandCovering($bands, 0.95)->low);
        $this->assertSame(6, $this->totalAcross($bands), 'all six edge runs are surfaced');
        $this->assertSame(2, $bands[4]->total, 'the top band is inclusive of the ceiling — it holds both 0.95 and 1.00');
    }

    // ── population filters ───────────────────────────────────────────────────

    public function test_non_propose_close_runs_are_ignored(): void
    {
        $this->closeRun(0.99, TechnicianRunState::Done, 'send_reply');
        $this->closeRun(0.99, TechnicianRunState::Flagged, 'flag_attention');

        $bands = app(CloseBandEvaluator::class)->evaluate();

        $this->assertSame(0, $this->totalAcross($bands), 'only propose_close is scored');
    }

    public function test_runs_without_a_confidence_are_ignored(): void
    {
        $this->rawRun(null, TechnicianRunState::Done); // a propose_close with no self-reported confidence

        $bands = app(CloseBandEvaluator::class)->evaluate();

        $this->assertSame(0, $this->totalAcross($bands), 'a run with no confidence cannot be banded');
    }

    public function test_confidence_below_the_approve_floor_is_not_surfaced(): void
    {
        $this->closeRun(0.40, TechnicianRunState::Done); // below the 0.50 approve floor

        $bands = app(CloseBandEvaluator::class)->evaluate();

        $this->assertSame(0, $this->totalAcross($bands), 'sub-floor confidence falls outside every band');
    }

    // ── recency window (psa-91f2 fast-follow: --since scopes to recent judgment) ──

    public function test_since_scopes_to_runs_created_within_the_window(): void
    {
        $this->closeRun(0.97, TechnicianRunState::Denied); // recent
        $old = $this->closeRun(0.97, TechnicianRunState::Done);
        // Backdate the old run beyond the window (raw update bypasses timestamp management).
        TechnicianRun::where('id', $old->id)->update(['created_at' => now()->subDays(30)]);

        $all = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(), 0.97);
        $this->assertSame(2, $all->total, 'all-time scores both runs');

        $recent = $this->bandCovering(app(CloseBandEvaluator::class)->evaluate(sinceDays: 14), 0.97);
        $this->assertSame(1, $recent->total, 'only the run created within 14 days is scored');
        $this->assertSame(1, $recent->declined, 'and it is the recent decline');
        $this->assertSame(0, $recent->approved, 'the 30-day-old approve is excluded');
    }
}
