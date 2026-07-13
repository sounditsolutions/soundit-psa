<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The single, VALIDATED resolver for the configured "System User (AI Actor)"
 * (setting: triage_system_user_id). Every reader of that setting that turns it into
 * a user id — TechnicianConfig::aiActorUserId() and TriageConfig::systemUserId() —
 * goes through here, so the guard cannot drift between them (psa-3s7a).
 *
 * THE BUG THIS EXISTS TO PREVENT: the setting was previously cast straight to an int
 * with no existence check, and that value is written into FOREIGN KEY columns —
 * technician_action_logs.actor_id (every gated AI action) and ticket_notes.author_id
 * (every AI-authored note/status change). A setting pointing at a user that no longer
 * exists (deleted staff member, an id carried in from another environment) therefore
 * made those INSERTs violate the FK and 500. Because the audit write is on
 * TechnicianActionGate::dispatch(), which EVERY gated action flows through, a single
 * stale setting took down the whole approval surface: nothing could be approved.
 *
 * THE CONTRACT: this returns either a user id that REALLY EXISTS, or null. It never
 * returns an id that is not in `users`. Callers write the result into nullable columns,
 * so a misconfiguration degrades the audit ATTRIBUTION (actor_id → null, while
 * actor_label stays 'ai-technician' and the human approver stays in approver_user_id)
 * and never takes down the surface.
 *
 * WHY NULL AND NOT "THE FIRST USER" ON A STALE ID: these are append-only forensic audit
 * rows on an AI action surface. Silently re-attributing an AI action to some arbitrary
 * OTHER human is worse than attributing it to nobody — the actor's identity is not lost
 * (actor_label), so null costs us nothing and a wrong name costs us the trail's integrity.
 */
class AiActorResolver
{
    public const SETTING_KEY = 'triage_system_user_id';

    /**
     * The configured AI actor, validated to exist. Null when the operator's configured
     * user is gone (loudly logged — it is a misconfiguration someone has to fix).
     *
     * The UNSET case keeps its long-standing first-user fallback: "never configured" is a
     * fresh install, not a misconfiguration, and there is no operator intent to contradict.
     * That id is read straight OUT of the users table, so it always exists and can never be
     * the stale-id FK violation above — it is not the bug, so it is left alone.
     */
    public static function resolve(): ?int
    {
        $configured = Setting::getValue(self::SETTING_KEY);

        // Unset / cleared (the Settings UI writes '' to clear) → first user.
        if (! $configured) {
            return User::orderBy('id')->value('id');
        }

        $actorId = (int) $configured;

        if ($actorId > 0 && User::whereKey($actorId)->exists()) {
            return $actorId;
        }

        Log::warning(
            '[AiActor] Configured AI actor user does not exist — degrading attribution to NULL. '
            .'Set a valid "System User (AI Actor)" in Settings → Integrations.',
            ['setting' => self::SETTING_KEY, 'configured_user_id' => $actorId]
        );

        return null;
    }
}
