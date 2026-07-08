<?php

namespace App\Services\Agent\Escalation;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\User;
use App\Support\AgentConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * Task 4 — "never silently stuck" degradation sweep for flag_attention escalations.
 *
 * Finds Flagged TechnicianRun records whose escalation has not been acknowledged
 * (still Flagged) and whose proposed_meta['escalation']['notified_at'] is older
 * than the configured reping window. For each, advances the escalation chain and
 * re-delivers via EscalationNotifier::deliverTo().
 *
 * Chain advancement:
 *   - Next step = min(current_step + 1, chain_last_index)
 *   - Scan forward from next step for an available chain member (operatorAvailable).
 *   - If none available, re-ping the current recipient (never drop).
 *   - If the chain is empty, re-ping the current recipient_user_id from meta.
 *
 * Fail-soft per run: a bad run never aborts the sweep; the others still get
 * re-delivered. Dormant when AgentConfig::escalationEnabled() is false.
 *
 * Recipient is ALWAYS server-side: chain membership comes from
 * TechnicianConfig::escalationChain() (operator configuration), never from
 * anything the agent or a client wrote.
 */
class EscalationSweep
{
    public function __construct(
        private readonly EscalationNotifier $notifier,
    ) {}

    /**
     * Run one sweep pass. Returns the number of escalations re-delivered.
     */
    public function sweep(): int
    {
        if (! AgentConfig::escalationEnabled()) {
            return 0;
        }

        $window = TechnicianConfig::agentEscalationRepingMinutes();
        $cutoff = now()->subMinutes($window);

        // Load all Flagged flag_attention runs — the meta filter runs in PHP since
        // proposed_meta is a JSON column (no portable JSON-path query for all DBs).
        $candidates = TechnicianRun::where('action_type', 'flag_attention')
            ->where('state', TechnicianRunState::Flagged->value)
            ->get()
            ->filter(function (TechnicianRun $run) use ($cutoff): bool {
                $esc = $run->proposed_meta['escalation'] ?? null;
                if (! is_array($esc) || ! isset($esc['notified_at'])) {
                    return false; // no escalation state yet — not a candidate
                }

                try {
                    $notifiedAt = \Illuminate\Support\Carbon::parse($esc['notified_at']);
                } catch (\Throwable) {
                    return false;
                }

                return $notifiedAt->lte($cutoff);
            });

        $count = 0;

        foreach ($candidates as $run) {
            try {
                if ($this->driveOne($run)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // Fail-soft: a single failing run must never abort the sweep.
                Log::warning('[EscalationSweep] Re-delivery failed for one run; continuing', [
                    'run_id' => $run->id,
                    'ticket_id' => $run->ticket_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Advance the chain for a single run and re-deliver. Returns true if delivery
     * was dispatched (even if the channel itself fails — channel fail-soft is inside
     * EscalationNotifier).
     */
    private function driveOne(TechnicianRun $run): bool
    {
        $meta = $run->proposed_meta ?? [];
        $esc = $meta['escalation'] ?? [];
        $currentStep = (int) ($esc['step'] ?? 0);
        $blocker = (string) ($meta['reason'] ?? '');

        $ticket = $run->ticket;
        if ($ticket === null) {
            Log::warning('[EscalationSweep] Run has no ticket (deleted?); skipping', ['run_id' => $run->id]);

            return false;
        }

        $chain = TechnicianConfig::escalationChain();

        // ── Advance the chain ────────────────────────────────────────────────────
        // nextStep is the "intended" next index, clamped at the last chain member.
        // When at the end, nextStep == currentStep — chain is exhausted and we
        // re-ping the same person (never drop).
        if (count($chain) === 0) {
            // Chain not configured: re-ping the last recorded recipient.
            $fallbackId = isset($esc['recipient_user_id']) ? (int) $esc['recipient_user_id'] : null;
            $recipient = $fallbackId ? User::find($fallbackId) : null;
            $this->notifier->deliverTo($ticket, $run, $recipient, $blocker, $currentStep);

            return true;
        }

        $nextStep = min($currentStep + 1, max(0, count($chain) - 1));

        // Scan forward from nextStep for the first AVAILABLE chain member.
        $chosenUserId = null;
        $chosenStep = $nextStep;
        for ($i = $nextStep; $i < count($chain); $i++) {
            if (TechnicianConfig::operatorAvailable($chain[$i])) {
                $chosenUserId = $chain[$i];
                $chosenStep = $i;
                break;
            }
        }

        // If no available member found from nextStep onward, re-ping the current recipient.
        if ($chosenUserId === null) {
            $fallbackId = isset($esc['recipient_user_id']) ? (int) $esc['recipient_user_id'] : null;
            $recipient = $fallbackId ? User::find($fallbackId) : null;
            Log::info('[EscalationSweep] No available chain member — re-pinging current recipient', [
                'run_id' => $run->id,
                'recipient_user_id' => $fallbackId,
            ]);
            $this->notifier->deliverTo($ticket, $run, $recipient, $blocker, $currentStep);

            return true;
        }

        $recipient = User::find($chosenUserId);

        Log::info('[EscalationSweep] Re-delivering escalation', [
            'run_id' => $run->id,
            'ticket_id' => $run->ticket_id,
            'from_step' => $currentStep,
            'to_step' => $chosenStep,
            'recipient_user_id' => $chosenUserId,
        ]);

        $this->notifier->deliverTo($ticket, $run, $recipient, $blocker, $chosenStep);

        return true;
    }
}
