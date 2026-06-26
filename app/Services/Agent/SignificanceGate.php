<?php

namespace App\Services\Agent;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AgentConfig;
use Illuminate\Support\Facades\Log;

/**
 * SignificanceGate — cheap Haiku "worth a look?" filter that fronts the TechnicianAgent.
 *
 * Decides cheaply whether an open ticket deserves the agent's deeper (Opus) look.
 * Uses a lightweight prompt (subject + status + age + last note) — NOT ContextBuilder.
 *
 * Escalates-when-unsure: errors and ambiguous answers return true (wake the agent).
 * A missed ticket is always worse than a wasted Opus call.
 *
 * In production the caller constructs: new SignificanceGate(new AiClient(AgentConfig::significanceModel()))
 * or uses the convenience factory: SignificanceGate::haiku()
 */
class SignificanceGate
{
    public function __construct(private readonly AiClient $ai) {}

    /**
     * Convenience factory that builds a Haiku-configured instance for production use.
     * Tests should inject a mock via the constructor instead.
     */
    public static function haiku(): self
    {
        return new self(new AiClient(AgentConfig::significanceModel()));
    }

    /**
     * Assess whether a ticket is worth the TechnicianAgent's closer look.
     *
     * @return bool true = wake the agent (worth a look), false = skip (clearly active).
     */
    public function assess(Ticket $ticket): bool
    {
        $context = $this->buildContext($ticket);

        try {
            $response = $this->ai->complete(
                'You are triaging an MSP ticket queue. Given only the summary below, decide if this ticket is worth a technician\'s closer look for possible closing — i.e. it appears resolved, abandoned, or junk/spam/automated-noise (delivery-failure bounces, monitoring or cron notifications, unsubscribe confirmations, marketing, and the like) — as opposed to a real ticket that is clearly still active and progressing. Answer with only YES or NO.',
                $context,
                10,
            );

            $text = strtoupper(trim($response->text));

            // Only skip when the model is unambiguously "NO" (exact match).
            // str_starts_with would incorrectly skip "NOT SURE", "NOBODY", etc.
            // Escalate-when-unsure: anything other than a bare "NO" → wake the agent.
            if ($text === 'NO') {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Escalate-when-unsure: a missed ticket is worse than a wasted Opus call.
            Log::warning('[SignificanceGate] Error assessing ticket — escalating to agent', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Build a lightweight context string for the Haiku prompt.
     * Intentionally minimal — this is the cost-saving point vs. full ContextBuilder.
     */
    private function buildContext(Ticket $ticket): string
    {
        $ageDays = $ticket->updated_at
            ? (int) now()->diffInDays($ticket->updated_at)
            : 0;

        $status = $ticket->status instanceof TicketStatus
            ? $ticket->status->value
            : ($ticket->status ?? 'unknown');

        $lines = [
            "Subject: {$ticket->subject}",
            "Status: {$status}",
            "Last updated: {$ageDays} day(s) ago",
        ];

        $lastNote = $ticket->notes()
            ->orderByDesc('noted_at')
            ->first();

        if ($lastNote) {
            $who = $lastNote->author_name ?? 'Unknown';
            $body = mb_substr($lastNote->body ?? '', 0, 300);
            $lines[] = "Last note (by {$who}): {$body}";
        }

        return implode("\n", $lines);
    }
}
