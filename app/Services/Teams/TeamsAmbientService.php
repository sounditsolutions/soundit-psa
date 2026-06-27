<?php

namespace App\Services\Teams;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Cache;

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
            return false;
        }

        // The conservative gate has the final say (and chat text can't force it). Run it
        // BEFORE claiming the window so a "no" never consumes the cooldown.
        if (! $this->gate->shouldSpeak($this->recentTurns($convId), $text)) {
            return false;
        }

        // Atomically CLAIM the window. Cache::add sets-if-absent and returns false if a
        // concurrent webhook for this same conversation already claimed it — so two
        // simultaneous chimes can never both go out (the has() check above is only a
        // fast path; this add() is the real, race-safe guard).
        if (! Cache::add($cooldownKey, true, TeamsBotConfig::ambientCooldownSeconds())) {
            return false;
        }

        return true;
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
