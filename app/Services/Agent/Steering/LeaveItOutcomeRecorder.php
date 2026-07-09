<?php

namespace App\Services\Agent\Steering;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Log;

/**
 * LeaveItOutcomeRecorder (psa-3q0c) — make a correction-driven "leave-it" VISIBLE.
 *
 * When an operator declines + corrects a proposal, the run is re-assessed
 * (correction-driven). If the agent then produces NO new proposal (chooses to
 * leave the ticket as-is), the old proposal was already superseded — so without
 * this the cockpit card just VANISHES and the operator can't tell whether their
 * correction did anything.
 *
 * The Mayor's UX call (psa-3q0c / psa-rmus FIX 2): reuse the AssistantConversation
 * provenance machinery — record the leave-it outcome as an ASSISTANT turn on the
 * SAME daily ticket_correction conversation the operator's correction lives on.
 * The cockpit then surfaces it as "✓ Re-assessed from your correction → decided to
 * leave as-is: <reason>". Provenance is inherent: the conversation IS the correction
 * thread (its user_id = operator, context_id = ticket).
 *
 * Safety: the turn is assistant-role, so it is IGNORED by the two consumers that
 * read this conversation — ContextBuilder::recentCorrectionsSection() (operator
 * directive) and LessonCapture — both of which filter to user-role messages only.
 * Strictly additive, and fail-soft: a recording error never breaks the agent run.
 */
class LeaveItOutcomeRecorder
{
    /** Human-readable prefix; also the marker that identifies a leave-it turn. */
    public const NOTE_PREFIX = '✓ Re-assessed from your correction → decided to leave as-is';

    /** Cap the agent's reason kept in the visible note (the transcript is not a dumping ground). */
    private const REASON_CAP = 400;

    public function record(AssistantConversation $conversation, string $reason): ?AssistantMessage
    {
        try {
            $reason = trim($reason);
            if (mb_strlen($reason) > self::REASON_CAP) {
                $reason = rtrim(mb_substr($reason, 0, self::REASON_CAP)).'…';
            }

            $note = $reason !== ''
                ? self::NOTE_PREFIX.': '.$reason
                : self::NOTE_PREFIX.'.';

            return $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $note,
            ]);
        } catch (\Throwable $e) {
            // Fail-soft: the agent has already re-assessed by the time we're called; a recording
            // failure must never fail the queued job. Swallow + log (mirrors LessonCapture).
            Log::warning('[Steering][LeaveItOutcomeRecorder] record failed (non-fatal)', [
                'conversation_id' => $conversation->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
