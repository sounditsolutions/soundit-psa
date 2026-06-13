<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiFactService
{
    /**
     * Upsert a deterministic sync-sourced fact (spec §5.1 trigger 1).
     *
     * Locking: lockForUpdate on (client_id, subject_key) serializes concurrent
     * writers when a non-retired row already exists. For a brand-new subject_key
     * (no existing row), InnoDB gap locks do NOT prevent two concurrent
     * transactions from each reading empty and each inserting — yielding
     * duplicate confirmed rows. Current risk: negligible (the scheduler runs one
     * SyncFactWriter per client at a time). If Phase-3 mining parallelizes
     * writers per client, add a named advisory lock —
     * GET_LOCK('wiki_fact:{clientId}:{subjectKey}', 5) — around this
     * transaction, or add a maintenance-sweep dedup. Do NOT add a unique index
     * on (client_id, subject_key): disputes intentionally require two rows.
     */
    public function upsertSyncFact(
        WikiPage $page,
        string $anchor,
        string $subjectKey,
        string $statement,
        WikiFactVolatility $volatility,
        array $sourceRefs,
    ): WikiFact {
        $subjectKey = self::normalizeSubjectKey($subjectKey);

        return DB::transaction(function () use ($page, $anchor, $subjectKey, $statement, $volatility, $sourceRefs) {
            $existing = WikiFact::query()
                ->where('client_id', $page->client_id)
                ->where('subject_key', $subjectKey)
                ->whereNot('status', WikiFactStatus::Retired->value)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($existing?->pinned) {
                return $existing; // never auto-supersede a pinned fact (spec §5.2)
            }

            if ($existing && trim($existing->statement) === trim($statement)) {
                $existing->update(['last_affirmed_at' => now()]);

                return $existing;
            }

            $new = WikiFact::create([
                'scope' => $page->client_id ? WikiScope::Client : WikiScope::Global,
                'client_id' => $page->client_id,
                'page_id' => $page->id,
                'section_anchor' => $anchor,
                'subject_key' => $subjectKey,
                'statement' => $statement,
                'status' => WikiFactStatus::Confirmed,
                'volatility' => $volatility,
                'source_type' => WikiFactSource::Sync,
                'source_refs' => $sourceRefs,
                'last_affirmed_at' => now(),
            ]);

            if ($existing) {
                $existing->update([
                    'status' => WikiFactStatus::Retired,
                    'superseded_by_fact_id' => $new->id,
                ]);
            }

            return $new;
        });
    }

    /**
     * Upsert an AI-mined fact (spec §5.2 merge stage, trigger 2).
     *
     * Born unverified. Semantics:
     * - Reaffirm on same statement (bump last_affirmed_at, return existing row).
     * - Contradiction against an already-disputed subject_key → return null (human must resolve first).
     * - Contradiction against a pinned fact whose evidence refs are a subset of its
     *   dismissed_evidence → return null (spec §4.4 dismissal subset rule).
     * - Contradiction against any other non-retired fact → create challenger row,
     *   set status=disputed on both, wire symmetric disputed_with_fact_id.
     * - New subject_key → insert unverified.
     *
     * @return WikiFact|null Null when the subject is already disputed or the challenge is suppressed by dismissed evidence.
     */
    public function upsertMinedFact(
        WikiPage $page,
        string $anchor,
        string $subjectKey,
        string $statement,
        WikiFactVolatility $volatility,
        array $sourceRefs,
        float $confidence,
    ): ?WikiFact {
        $subjectKey = self::normalizeSubjectKey($subjectKey);

        return DB::transaction(function () use ($page, $anchor, $subjectKey, $statement, $volatility, $sourceRefs, $confidence) {
            $existing = WikiFact::query()
                ->where('client_id', $page->client_id)
                ->where('subject_key', $subjectKey)
                ->whereNot('status', WikiFactStatus::Retired->value)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            // No existing active fact — insert as unverified.
            if (! $existing) {
                return WikiFact::create([
                    'scope' => $page->client_id ? WikiScope::Client : WikiScope::Global,
                    'client_id' => $page->client_id,
                    'page_id' => $page->id,
                    'section_anchor' => $anchor,
                    'subject_key' => $subjectKey,
                    'statement' => $statement,
                    'status' => WikiFactStatus::Unverified,
                    'volatility' => $volatility,
                    'source_type' => WikiFactSource::Ticket,
                    'source_refs' => $sourceRefs,
                    'confidence' => $confidence,
                    'last_affirmed_at' => now(),
                ]);
            }

            // Same statement — reaffirm.
            if (trim($existing->statement) === trim($statement)) {
                $existing->update(['last_affirmed_at' => now()]);

                return $existing;
            }

            // Already disputed — human must resolve before AI re-challenges.
            if ($existing->status === WikiFactStatus::Disputed) {
                return null;
            }

            // Pinned fact with evidence entirely within dismissed_evidence — suppressed.
            if ($existing->pinned && self::isSubsetOfDismissed($sourceRefs, (array) ($existing->dismissed_evidence ?? []))) {
                return null;
            }

            // Contradiction — create challenger, pair both as disputed.
            $challenger = WikiFact::create([
                'scope' => $page->client_id ? WikiScope::Client : WikiScope::Global,
                'client_id' => $page->client_id,
                'page_id' => $page->id,
                'section_anchor' => $anchor,
                'subject_key' => $subjectKey,
                'statement' => $statement,
                'status' => WikiFactStatus::Disputed,
                'volatility' => $volatility,
                'source_type' => WikiFactSource::Ticket,
                'source_refs' => $sourceRefs,
                'confidence' => $confidence,
                'last_affirmed_at' => now(),
                'disputed_with_fact_id' => $existing->id,
            ]);

            $existing->update([
                'status' => WikiFactStatus::Disputed,
                'disputed_with_fact_id' => $challenger->id,
            ]);

            return $challenger;
        });
    }

    /**
     * Confirm an unverified fact — promotes it to confirmed and records the confirming user.
     */
    public function confirm(WikiFact $fact, User $user): void
    {
        $fact->update([
            'status' => WikiFactStatus::Confirmed,
            'confirmed_by' => $user->id,
        ]);
    }

    /**
     * Retire a fact (human explicit removal). Does not use superseded_by_fact_id —
     * that is reserved for machine supersession; human retirement is a deliberate act.
     */
    public function retire(WikiFact $fact): void
    {
        $fact->update(['status' => WikiFactStatus::Retired]);
    }

    /**
     * Correct a fact: creates a new pinned human-sourced confirmed fact and retires the old one.
     * Spec §4.4: humans own their edits; pinned facts are never auto-superseded by AI.
     */
    public function correct(WikiFact $old, string $newStatement, User $user): WikiFact
    {
        return DB::transaction(function () use ($old, $newStatement, $user) {
            $new = WikiFact::create([
                'scope' => $old->scope,
                'client_id' => $old->client_id,
                'page_id' => $old->page_id,
                'section_anchor' => $old->section_anchor,
                'subject_key' => $old->subject_key,
                'statement' => $newStatement,
                'status' => WikiFactStatus::Confirmed,
                'pinned' => true,
                'volatility' => $old->volatility,
                'source_type' => WikiFactSource::Human,
                'source_refs' => [['type' => 'human', 'id' => $user->id]],
                'confidence' => null,
                'last_affirmed_at' => now(),
                'confirmed_by' => $user->id,
            ]);

            $old->update([
                'status' => WikiFactStatus::Retired,
                'superseded_by_fact_id' => $new->id,
            ]);

            return $new;
        });
    }

    /**
     * Resolve a dispute from the challenger's perspective.
     *
     * $action = 'accept': challenger claim wins — challenger → confirmed, incumbent → retired.
     * $action = 'dismiss': challenger claim dismissed — challenger → retired, incumbent → confirmed + pinned,
     *                      challenger source_refs appended to incumbent's dismissed_evidence.
     *
     * The $fact argument is always the CHALLENGER (the newer contradicting row).
     */
    public function resolveDispute(WikiFact $challenger, string $action, User $user): void
    {
        if (! in_array($action, ['accept', 'dismiss'], true)) {
            throw new \RuntimeException("Unknown dispute resolution '{$action}'.");
        }

        if ($challenger->disputed_with_fact_id === null) {
            throw new \RuntimeException("Fact {$challenger->id} is not part of a dispute.");
        }

        DB::transaction(function () use ($challenger, $action, $user) {
            $incumbent = WikiFact::lockForUpdate()->find($challenger->disputed_with_fact_id);

            if ($action === 'accept') {
                // Challenger wins.
                $challenger->update([
                    'status' => WikiFactStatus::Confirmed,
                    'confirmed_by' => $user->id,
                    'disputed_with_fact_id' => null,
                ]);
                if ($incumbent) {
                    $incumbent->update([
                        'status' => WikiFactStatus::Retired,
                        'disputed_with_fact_id' => null,
                        'superseded_by_fact_id' => $challenger->id,
                    ]);
                }
            } else { // 'dismiss'
                // Challenger dismissed; incumbent survives, pinned.
                $existingDismissed = (array) ($incumbent?->dismissed_evidence ?? []);
                $newDismissed = array_merge($existingDismissed, (array) ($challenger->source_refs ?? []));

                $challenger->update([
                    'status' => WikiFactStatus::Retired,
                    'disputed_with_fact_id' => null,
                ]);
                if ($incumbent) {
                    $incumbent->update([
                        'status' => WikiFactStatus::Confirmed,
                        'pinned' => true,
                        'disputed_with_fact_id' => null,
                        'dismissed_evidence' => $newDismissed,
                    ]);
                }
            }
        });
    }

    /**
     * Returns true iff every ref in $evidence appears in $dismissed (by type+id match).
     * Empty $evidence never vacuously counts as a subset (a no-evidence challenge must
     * not be suppressed), and empty $dismissed never matches — both return false.
     */
    public static function isSubsetOfDismissed(array $evidence, array $dismissed): bool
    {
        if ($evidence === [] || $dismissed === []) {
            return false;
        }

        foreach ($evidence as $ref) {
            $found = false;
            foreach ($dismissed as $d) {
                if (
                    isset($ref['type'], $ref['id'], $d['type'], $d['id'])
                    && $ref['type'] === $d['type']
                    && (string) $ref['id'] === (string) $d['id']
                ) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** Spec §5.2: deterministic normalization so wording drift can't defeat dedup. */
    public static function normalizeSubjectKey(string $key): string
    {
        return strtolower(trim(preg_replace('/\s+/', '-', $key)));
    }
}
