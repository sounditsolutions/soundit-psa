<?php

namespace App\Services\Teams;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether the teammate should chime in UNPROMPTED on a non-@mention
 * message (E2b). Orchestrates: the double-dormancy flag, the per-conversation
 * cooldown (anti-spam — it must not dominate the chat), the recent transcript, and
 * the conservative Haiku gate. Returns true ONLY when the bot should speak now; the
 * caller then runs the same read-only reply path as the @mention flow.
 */
class TeamsAmbientService
{
    /** How many prior turns of context to give the gate. */
    private const RECENT_TURNS = 10;

    public function __construct(
        private readonly ChimeInGate $gate,
    ) {}

    public function shouldChimeIn(ResolvedSender $sender, string $text): bool
    {
        // Double-dormant: ambient requires its own explicit flag (on top of the Teams
        // enable flag the caller already checked).
        if (! TeamsBotConfig::ambientEnabled()) {
            return false;
        }

        $convId = $sender->conversationId;
        if ($convId === null || $convId === '') {
            return false;
        }

        // Anti-spam cooldown: at most one unprompted chime per conversation per window.
        // Fast path — skip the Haiku call entirely when we're already within the window.
        $cooldownKey = $this->cooldownKey($convId);
        if (Cache::has($cooldownKey)) {
            $this->logEvaluation('cooldown_suppressed', $convId, $text);

            return false;
        }

        // The conservative gate has the final say (and chat text can't force it). Run it
        // BEFORE claiming the window so a "no" never consumes the cooldown.
        if (! $this->gate->shouldSpeak($this->recentTurns($convId), $text)) {
            $this->logEvaluation('silent', $convId, $text);

            return false;
        }

        // Atomically CLAIM the window. Cache::add sets-if-absent and returns false if a
        // concurrent webhook for this same conversation already claimed it — so two
        // simultaneous chimes can never both go out (the has() check above is only a
        // fast path; this add() is the real, race-safe guard).
        if (! Cache::add($cooldownKey, true, TeamsBotConfig::ambientCooldownSeconds())) {
            $this->logEvaluation('cooldown_suppressed', $convId, $text);

            return false;
        }

        $this->logEvaluation('chimed', $convId, $text);

        return true;
    }

    /**
     * One concise INFO line per ambient evaluation (psa-22gq observability). Its mere
     * presence confirms RSC delivery of a non-@mention message; the outcome records the
     * gate's decision so the operator can tune eagerness/banter against live traffic.
     * Tagged '[Teams Ambient]' (distinct from the '[Teams Bot]' @mention path) and
     * carries the live culture dials. The text is truncated to a short snippet — never
     * the full message — so the line is greppable without leaking the conversation.
     *
     * Outcomes: 'chimed' (gate said yes ⇒ caller replies), 'silent' (gate said no),
     * 'cooldown_suppressed' (within the per-conversation window, incl. a race loss).
     * The dormant case (ambient off) returns earlier and is intentionally not logged.
     *
     * NOTE: INFO level — surfaces in prod only when LOG_LEVEL is at info or lower.
     */
    private function logEvaluation(string $outcome, string $convId, string $text): void
    {
        Log::info('[Teams Ambient] '.$outcome, [
            'outcome' => $outcome,
            'eagerness' => TeamsBotConfig::ambientEagerness(),
            'banter' => TeamsBotConfig::ambientBanter(),
            'cooldown_seconds' => TeamsBotConfig::ambientCooldownSeconds(),
            'conversation_id' => $convId,
            'snippet' => $this->snippet($text),
        ]);
    }

    /** Collapse whitespace and truncate to a short, non-leaky snippet for the log. */
    private function snippet(string $text): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($collapsed) > 80 ? mb_substr($collapsed, 0, 80).'…' : $collapsed;
    }

    /**
     * The last few turns of this Teams conversation's transcript (engagement
     * history), oldest → newest, for the gate's "read the room" context.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function recentTurns(string $convId): array
    {
        $conversation = AssistantConversation::where('external_key', 'teams:'.$convId)->first();
        if ($conversation === null) {
            return [];
        }

        return AssistantMessage::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_TURNS)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function cooldownKey(string $convId): string
    {
        return 'teams_ambient_cooldown:'.$convId;
    }
}
