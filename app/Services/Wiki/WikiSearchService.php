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
            ->when($clientId !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->where('client_id', $clientId)->orWhereNull('client_id')
            ))
            ->where(fn ($q) => $this->textMatch($q, ['statement'], $query))
            ->with('page')
            ->limit($limit)
            ->get();

        return ['pages' => $pages, 'facts' => $facts];
    }

    /** FULLTEXT on mysql/mariadb, LIKE elsewhere (SQLite dev/tests). Spec §9. */
    private function textMatch($query, array $columns, string $term)
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->whereFullText($columns, $term);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        foreach ($columns as $index => $column) {
            $index === 0 ? $query->where($column, 'like', $like) : $query->orWhere($column, 'like', $like);
        }

        return $query;
    }
}
