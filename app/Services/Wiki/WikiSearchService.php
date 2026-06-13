<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactStatus;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiSearchService
{
    /** @return array{pages: \Illuminate\Support\Collection, facts: \Illuminate\Support\Collection} */
    public function search(string $query, ?int $clientId = null, int $limit = 25): array
    {
        $pages = WikiPage::active()
            ->where(function ($q) use ($clientId) {
                // Staff search spans scopes by design (PRODUCT.md: cross-client
                // generalists). The spec's hard per-client isolation binds the
                // Phase-4 AI tool layer, not this staff UI.
                $q->where('scope', 'global');
                if ($clientId !== null) {
                    $q->orWhere(fn ($qq) => $qq->where('scope', 'client')->where('client_id', $clientId));
                } else {
                    $q->orWhere('scope', 'client');
                }
            })
            ->where(fn ($q) => $this->textMatch($q, ['title', 'body_md'], $query))
            ->with('client')
            ->limit($limit)
            ->get();

        $facts = WikiFact::query()
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->whereHas('page', fn ($q) => $q->where('is_archived', false))
            ->when($clientId !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->where('client_id', $clientId)->orWhereNull('client_id')
            ))
            ->where(fn ($q) => $this->textMatch($q, ['statement'], $query))
            ->with('page')
            ->limit($limit)
            ->get();

        return ['pages' => $pages, 'facts' => $facts];
    }

    /**
     * AI-safe search (spec §6 rule 2). null clientId → GLOBAL ONLY (never all clients);
     * set clientId → that client + global. Retired facts / archived pages excluded.
     * AI consumers MUST call via WikiRetrieval, which applies the §6 serialization;
     * this returns models, not the serialized form.
     *
     * @return array{pages: \Illuminate\Support\Collection, facts: \Illuminate\Support\Collection}
     */
    public function aiSearch(string $query, ?int $clientId, int $limit = 10): array
    {
        $pages = WikiPage::active()
            ->where(function ($q) use ($clientId) {
                $q->where('scope', 'global');
                if ($clientId !== null) {
                    $q->orWhere(fn ($qq) => $qq->where('scope', 'client')->where('client_id', $clientId));
                }
            })
            ->where(fn ($q) => $this->textMatch($q, ['title', 'body_md'], $query))
            ->limit($limit)->get();

        $facts = WikiFact::query()
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->whereHas('page', fn ($q) => $q->where('is_archived', false))
            ->where(function ($q) use ($clientId) {
                $q->whereNull('client_id');
                if ($clientId !== null) {
                    $q->orWhere('client_id', $clientId);
                }
            })
            ->where(fn ($q) => $this->textMatch($q, ['statement'], $query))
            ->with('disputedWith')
            ->limit($limit)->get();

        return ['pages' => $pages, 'facts' => $facts];
    }

    /** FULLTEXT on mysql/mariadb, LIKE elsewhere (SQLite dev/tests). Spec §9. */
    private function textMatch($query, array $columns, string $term)
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->whereFullText($columns, $term);
        }

        // SQLite fallback mirrors MariaDB FULLTEXT natural-language mode: split on
        // non-word boundaries (FULLTEXT's tokenizer treats punctuation/whitespace as word
        // separators) and match a row containing ANY token (OR), not the literal phrase.
        // Single-word queries are unchanged. Keeps the AI/staff search behavior consistent
        // across engines so the §6 retrieval tests pin one contract.
        $words = preg_split('/[^\p{L}\p{N}]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
        $first = true;
        foreach ($words as $word) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
            foreach ($columns as $column) {
                $first ? $query->where($column, 'like', $like) : $query->orWhere($column, 'like', $like);
                $first = false;
            }
        }

        return $query;
    }
}
