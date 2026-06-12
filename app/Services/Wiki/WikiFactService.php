<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiFactService
{
    /**
     * Upsert a deterministic sync-sourced fact (spec §5.1 trigger 1).
     * Row-locked on (client_id, subject_key) per spec §4.1 merge concurrency.
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

    /** Spec §5.2: deterministic normalization so wording drift can't defeat dedup. */
    public static function normalizeSubjectKey(string $key): string
    {
        return strtolower(trim(preg_replace('/\s+/', '-', $key)));
    }
}
