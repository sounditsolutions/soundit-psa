<?php

namespace App\Services\Wiki\Retrieval;

use App\Enums\WikiFactStatus;
use App\Enums\WikiPageKind;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Services\Wiki\WikiCascadeService;
use App\Services\Wiki\WikiSearchService;

/**
 * Spec §6 retrieval boundary. ALL AI consumers read wiki content through this service
 * so the two hard rules hold in one place: (1) structured serving — facts as delimited
 * records with JSON-encoded free-text values; (2) cross-client isolation — null scope
 * returns GLOBAL ONLY. Page bodies are scanned before serving (they include
 * human-authored prose that never passed the mining scan).
 */
class WikiRetrieval
{
    public function __construct(
        private readonly WikiSearchService $search,
        private readonly WikiCascadeService $cascade,
        private readonly WikiRedactor $redactor,
    ) {}

    /** @return array<int, array{slug:string,title:string,kind:string,scope:string,updated:?string}> */
    public function listPages(?int $clientId): array
    {
        return WikiPage::active()
            ->where(function ($q) use ($clientId) {
                $q->where('scope', 'global');
                if ($clientId !== null) {
                    $q->orWhere(fn ($qq) => $qq->where('scope', 'client')->where('client_id', $clientId));
                }
            })
            ->orderBy('kind')->orderBy('title')->get()
            ->map(fn (WikiPage $p) => [
                'slug' => $p->slug, 'title' => $p->title, 'kind' => $p->kind->value,
                'scope' => $p->scope->value, 'updated' => $p->updated_at?->toDateString(),
            ])->all();
    }

    public function searchSerialized(string $query, ?int $clientId, int $limit = 10): string
    {
        $results = $this->search->aiSearch($query, $clientId, $limit);
        $facts = $this->serializeFacts($results['facts']);
        $pages = $results['pages']->map(fn (WikiPage $p) => 'WIKI_PAGE | slug: '.$this->scalar($p->slug)
            .' | title: '.$this->encode($p->title).' | kind: '.$p->kind->value
            .' | updated: '.($p->updated_at?->toDateString() ?? 'n/a'))->all();

        $blocks = array_filter([
            $facts !== '' ? "-- facts --\n".$facts : '',
            $pages !== [] ? "-- pages --\n".implode("\n", $pages) : '',
        ]);

        return $blocks === [] ? 'No matching wiki content.' : implode("\n", $blocks);
    }

    /** Returns the merged cascade view (§4.5); body scanned before return. */
    public function getPageView(string $slug, ?int $clientId): ?array
    {
        if ($clientId !== null) {
            $clientPage = WikiPage::active()->forClient($clientId)->where('slug', $slug)->first();
            // A deviation is only a delta — never served standalone; resolve via its parent.
            if ($clientPage && $clientPage->kind !== WikiPageKind::Deviation) {
                return $this->safeEnvelope($clientPage, $clientPage->body_md);
            }
            if ($clientPage && $clientPage->kind === WikiPageKind::Deviation && $clientPage->parent) {
                return $this->safeEnvelope($clientPage->parent, $this->cascade->mergedView($clientPage->parent, $clientId)['body_md']);
            }
        }

        $global = WikiPage::active()->where('scope', 'global')->where('slug', $slug)->first();
        if (! $global) {
            return null;
        }
        $body = $clientId !== null ? $this->cascade->mergedView($global, $clientId)['body_md'] : $global->body_md;

        return $this->safeEnvelope($global, $body);
    }

    /** Spec §6 rule 1 — one record per fact; one record per DISPUTE PAIR, two-sided. */
    public function serializeFacts(iterable $facts): string
    {
        $lines = [];
        $emitted = []; // pair keys already serialized
        foreach ($facts as $fact) {
            if ($fact->status === WikiFactStatus::Disputed) {
                $counter = $this->disputeCounter($fact);
                $key = $counter ? min($fact->id, $counter->id).'-'.max($fact->id, $counter->id) : 'f'.$fact->id;
                if (isset($emitted[$key])) {
                    continue;
                }
                $emitted[$key] = true;
                $line = 'WIKI_FACT | subject: '.$this->scalar($fact->subject_key)
                    .' | status: disputed | source: '.$fact->source_type->value
                    .' | claim: '.$this->encode($fact->statement);
                if ($counter) {
                    $line .= ' | disputed_by: '.$this->encode($counter->statement);
                }
                $lines[] = $line;

                continue;
            }
            $lines[] = 'WIKI_FACT | subject: '.$this->scalar($fact->subject_key)
                .' | status: '.$fact->status->value.' | source: '.$fact->source_type->value
                .' | claim: '.$this->encode($fact->statement);
        }

        return implode("\n", $lines);
    }

    /** The non-retired counter-fact of a dispute, resolving the link in either direction. */
    private function disputeCounter(WikiFact $fact): ?WikiFact
    {
        $counter = $fact->disputedWith; // the row this fact points at
        if ($counter && $counter->status !== WikiFactStatus::Retired) {
            return $counter;
        }
        // Disputes are linked on one side only (§4.2); look for the inverse. Disputes are rare.
        $inverse = WikiFact::where('disputed_with_fact_id', $fact->id)
            ->whereNot('status', WikiFactStatus::Retired->value)->first();

        return $inverse;
    }

    private function safeEnvelope(WikiPage $page, string $body): array
    {
        // Page bodies contain human-authored / site_notes-imported prose that never
        // passed the mining scan(). Don't serve raw text into an AI prompt (§6/§13).
        if ($this->redactor->scan($body) !== []) {
            $body = '[Wiki page body withheld: failed content-safety scan]';
        }

        return ['slug' => $page->slug, 'title' => $page->title, 'kind' => $page->kind->value, 'body_md' => $body];
    }

    /**
     * JSON-encode a free-text value: embedded quotes/newlines become inert. Additionally
     * neutralize the two record-grammar tokens the serializer owns — the field separator
     * `|` and the serializer's own field markers (`subject:`/`status:`/`source:`/`claim:`/
     * `disputed_by:` followed by space) — so a value can never reconstruct a fake field or a
     * fake `WIKI_FACT | subject: …` record. JSON quoting alone keeps a value structurally
     * inert to a JSON parser, but a downstream prompt reads the line as flat text, so the
     * delimiter tokens must not survive verbatim inside a value (spec §6 rule 1). Only OUR
     * field-name colons are collapsed — arbitrary prose colons ("ratio is 3: 1", "Note: x")
     * are left intact.
     */
    private function encode(string $s): string
    {
        $s = $this->stripSeparators($s);
        $s = str_replace('|', '/', $s); // field separator: values cannot forge a new field
        // Collapse only the serializer's own `<field>: ` markers, not arbitrary colons.
        $s = preg_replace('/\b(subject|status|source|claim|disputed_by):\s+/iu', '$1:', $s) ?? $s;

        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A bare scalar field (subject_key, slug): delimiter- and separator-safe, unquoted. */
    private function scalar(string $s): string
    {
        return str_replace(['|', '"'], ['/', "'"], $this->stripSeparators($s));
    }

    /** Remove control chars and Unicode line/paragraph separators that forge record breaks. */
    private function stripSeparators(string $s): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{0085}]+/u', ' ', $s) ?? $s);
    }
}
