<?php

namespace Tests\Feature\Agent;

use App\Enums\NoteType;
use App\Enums\TechnicianTier;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Agent\CloseAutoEligibility;
use App\Services\Technician\TechnicianTierClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The safety-critical core: how the agent's `confidence` becomes a tier for
 * propose_close, gated so an inflated scalar alone can NEVER auto-close.
 *
 * Covers CO-14 (the legacy tier map can never grant Auto for propose_close) and
 * CO-19 (the deterministic CloseAutoEligibility backstop must independently agree).
 */
class ConfidenceTierTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function classify(string $type, ?float $confidence, ?Ticket $ticket): TechnicianTier
    {
        return (new TechnicianTierClassifier)->classify($type, $confidence, $ticket);
    }

    /** A ticket that passes the deterministic backstop: Resolved, no recent client note. */
    private function eligibleTicket(): Ticket
    {
        return Ticket::factory()->create(['status' => TicketStatus::Resolved]);
    }

    private function setAutoThreshold(float $threshold): void
    {
        Setting::setValue('propose_close_auto_threshold', (string) $threshold);
    }

    private function setTiers(array $map): void
    {
        Setting::setValue('technician_action_tiers', json_encode($map));
    }

    /** Create a note with a controlled created_at (the column the backstop reads). */
    private function noteAt(Ticket $ticket, WhoType $who, Carbon $at): void
    {
        $this->travelTo($at, function () use ($ticket, $who) {
            TicketNote::create([
                'ticket_id' => $ticket->id,
                'who_type' => $who,
                'ai_authored' => false,
                'body' => 'note body',
                'note_type' => NoteType::Reply,
                'is_private' => false,
                'noted_at' => now(),
            ]);
        });
    }

    // ── classifier decision matrix ────────────────────────────────────────────

    public function test_auto_off_by_default_holds_even_at_full_confidence(): void
    {
        // No threshold setting at all → null → never auto. The core safety default.
        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 1.0, $this->eligibleTicket()),
        );
    }

    public function test_tier_map_cannot_grant_auto_for_propose_close(): void
    {
        // CO-14 BLOCKER: threshold unset, but operator wrote {"propose_close":"auto"}.
        // The map must NOT be a source of Auto for propose_close.
        $this->setTiers(['propose_close' => 'auto']);

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 1.0, $this->eligibleTicket()),
        );
    }

    public function test_tier_map_block_still_kills_propose_close(): void
    {
        // The map may still DOWNGRADE (Block) — an explicit operator denylist.
        $this->setAutoThreshold(0.95);
        $this->setTiers(['propose_close' => 'block']);

        $this->assertSame(
            TechnicianTier::Block,
            $this->classify('propose_close', 0.99, $this->eligibleTicket()),
        );
    }

    public function test_high_confidence_on_eligible_ticket_classifies_auto(): void
    {
        $this->setAutoThreshold(0.95);

        $this->assertSame(
            TechnicianTier::Auto,
            $this->classify('propose_close', 0.97, $this->eligibleTicket()),
        );
    }

    public function test_confidence_exactly_at_threshold_is_auto(): void
    {
        // Boundary: >= threshold is Auto.
        $this->setAutoThreshold(0.95);

        $this->assertSame(
            TechnicianTier::Auto,
            $this->classify('propose_close', 0.95, $this->eligibleTicket()),
        );
    }

    public function test_mid_band_below_threshold_holds_for_approval(): void
    {
        $this->setAutoThreshold(0.95);

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 0.80, $this->eligibleTicket()),
        );
    }

    public function test_null_confidence_holds_even_with_threshold_set(): void
    {
        $this->setAutoThreshold(0.95);

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', null, $this->eligibleTicket()),
        );
    }

    public function test_null_ticket_holds_fail_closed(): void
    {
        // No ticket to run the deterministic backstop against → never auto.
        $this->setAutoThreshold(0.95);

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 0.99, null),
        );
    }

    public function test_backstop_recent_client_note_forces_approve(): void
    {
        // CO-19: high confidence + threshold met, but the client wrote in yesterday.
        $this->setAutoThreshold(0.95);
        $ticket = $this->eligibleTicket();
        $this->noteAt($ticket, WhoType::EndUser, now()->subDay());

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 0.99, $ticket->fresh()),
        );
    }

    public function test_backstop_awaiting_us_status_forces_approve(): void
    {
        // CO-19: high confidence, no client note, but the ticket is awaiting US.
        $this->setAutoThreshold(0.95);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->assertSame(
            TechnicianTier::Approve,
            $this->classify('propose_close', 0.99, $ticket),
        );
    }

    public function test_other_action_types_are_unaffected_by_confidence(): void
    {
        // Sanity: the propose_close branch must not leak into the legacy path.
        // send_reply unmapped → Approve; send_ack mapped auto → Auto — both ignore
        // the confidence/ticket args entirely.
        $this->setAutoThreshold(0.95);
        $this->setTiers(['send_ack' => 'auto']);
        $ticket = $this->eligibleTicket();

        $this->assertSame(TechnicianTier::Approve, $this->classify('send_reply', 0.99, $ticket));
        $this->assertSame(TechnicianTier::Auto, $this->classify('send_ack', 0.99, $ticket));
        // And with no args at all (existing call shape) still works.
        $this->assertSame(TechnicianTier::Auto, (new TechnicianTierClassifier)->classify('send_ack'));
    }

    // ── CloseAutoEligibility backstop (CO-19) ─────────────────────────────────

    public function test_eligibility_true_for_each_auto_safe_status_with_no_recent_note(): void
    {
        foreach ([TicketStatus::Resolved, TicketStatus::PendingClient, TicketStatus::PendingThirdParty] as $status) {
            $ticket = Ticket::factory()->create(['status' => $status]);
            $this->assertTrue(
                CloseAutoEligibility::eligible($ticket),
                "{$status->value} with no recent client note should be auto-eligible",
            );
        }
    }

    public function test_eligibility_false_for_awaiting_us_statuses(): void
    {
        foreach ([TicketStatus::New, TicketStatus::InProgress] as $status) {
            $ticket = Ticket::factory()->create(['status' => $status]);
            $this->assertFalse(
                CloseAutoEligibility::eligible($ticket),
                "{$status->value} is awaiting us and must not be auto-eligible",
            );
        }
    }

    public function test_eligibility_false_for_closed_status(): void
    {
        // Already-closed is not a safe auto target (and not in the allowlist).
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed]);
        $this->assertFalse(CloseAutoEligibility::eligible($ticket));
    }

    public function test_recent_client_note_makes_ineligible(): void
    {
        $ticket = $this->eligibleTicket();
        $this->noteAt($ticket, WhoType::EndUser, now()->subDays(3));

        $this->assertFalse(CloseAutoEligibility::eligible($ticket));
    }

    public function test_old_client_note_outside_window_keeps_eligible(): void
    {
        // Default quiet window is 14 days; a 20-day-old client note does not block.
        $ticket = $this->eligibleTicket();
        $this->noteAt($ticket, WhoType::EndUser, now()->subDays(20));

        $this->assertTrue(CloseAutoEligibility::eligible($ticket));
    }

    public function test_recent_staff_or_system_note_does_not_block(): void
    {
        // Only END-USER (client) notes count as "inbound client activity".
        $ticket = $this->eligibleTicket();
        $this->noteAt($ticket, WhoType::Agent, now()->subDay());   // staff / AI technician
        $this->noteAt($ticket, WhoType::System, now()->subDay());  // system

        $this->assertTrue(CloseAutoEligibility::eligible($ticket));
    }

    public function test_soft_deleted_recent_client_note_still_blocks(): void
    {
        // Fail-closed: a recently soft-deleted client reply is still evidence of
        // recent client engagement — it must still withhold Auto.
        $ticket = $this->eligibleTicket();
        $this->noteAt($ticket, WhoType::EndUser, now()->subDays(2));
        TicketNote::where('ticket_id', $ticket->id)->delete(); // soft delete

        $this->assertFalse(CloseAutoEligibility::eligible($ticket));
    }

    public function test_quiet_window_honors_configured_days(): void
    {
        Setting::setValue('agent_auto_quiet_days', '3');
        $ticket = $this->eligibleTicket();
        // 5 days old: outside a 3-day window → does not block.
        $this->noteAt($ticket, WhoType::EndUser, now()->subDays(5));
        $this->assertTrue(CloseAutoEligibility::eligible($ticket));

        // 2 days old: inside a 3-day window → blocks.
        $this->noteAt($ticket, WhoType::EndUser, now()->subDays(2));
        $this->assertFalse(CloseAutoEligibility::eligible($ticket->fresh()));
    }
}
