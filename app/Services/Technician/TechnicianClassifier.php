<?php

namespace App\Services\Technician;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * The "can I own this?" classifier (spec §4.2/§7). It NEVER trusts the model's
 * self-reported confidence on its own: independent deterministic signals set a
 * ceiling the model score is clamped to, so an injected "confidence: high" in the
 * (fenced) ticket body cannot unlock ownership. Fail-closed on any error.
 */
class TechnicianClassifier
{
    private const SYSTEM_PROMPT =
        'You assess whether an IT MSP support ticket is one a knowledgeable assistant could confidently '
        .'draft a competent first response for. Respond ONLY with a JSON object '
        .'{"ownable": boolean, "confidence": number between 0 and 1, "reason": short string}. '
        .PromptFence::UNTRUSTED_INPUT_NOTICE;

    public function __construct(private readonly AiClient $ai) {}

    public function classify(Ticket $ticket): TechnicianAssessment
    {
        $signals = $this->signals($ticket);

        if (! AiConfig::isConfigured()) {
            return new TechnicianAssessment(0.0, false, ['ai-not-configured'], 0);
        }

        $fence = new PromptFence;
        $user = "Assess this ticket.\n\n".$fence->fence(
            'TICKET',
            ($ticket->subject ?? '')."\n\n".strip_tags((string) ($ticket->description ?? '')),
        );

        try {
            $res = $this->ai->completeJson(self::SYSTEM_PROMPT, $user, 300);
        } catch (\Throwable $e) {
            Log::warning('[Technician] Classifier AI error', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            return new TechnicianAssessment(0.0, false, ['classifier-error'], 0);
        }

        $modelScore = (float) ($res['confidence'] ?? 0);
        $modelOwnable = (bool) ($res['ownable'] ?? false);
        $tokens = $this->ai->cumulativeInputTokens() + $this->ai->cumulativeOutputTokens();

        // Independent ceiling — the model cannot inflate past what signals support.
        $confidence = round(max(0.0, min($modelScore, $signals['ceiling'])), 3); // 3dp: deterministic across DB drivers (v2)
        $ownable = $modelOwnable && $confidence >= 0.5 && $signals['contactResolved'];

        $reasons = $signals['reasons'];
        if (isset($res['reason']) && is_string($res['reason'])) {
            $reasons[] = 'model: '.mb_substr($res['reason'], 0, 200);
        }

        return new TechnicianAssessment($confidence, $ownable, $reasons, $tokens);
    }

    /**
     * Deterministic, injection-proof signals that bound the confidence (v2 note:
     * 1A implements ONLY the contact-resolution ceiling; spec §7's novelty/runbook/
     * SLA signals are deferred and MUST be added before any tier ramps send_reply
     * toward AUTO — until then confidence only gates *drafting*, never a send).
     *
     * @return array{contactResolved: bool, ceiling: float, reasons: string[]}
     */
    private function signals(Ticket $ticket): array
    {
        $contactResolved = (bool) ($ticket->contact?->email);
        $ceiling = 1.0;
        $reasons = [];

        if (! $contactResolved) {
            $ceiling = min($ceiling, 0.4);
            $reasons[] = 'no-resolved-contact-email';
        }

        return ['contactResolved' => $contactResolved, 'ceiling' => $ceiling, 'reasons' => $reasons];
    }
}
