<?php

namespace App\Http\Controllers\Web;

use App\Enums\WikiAuthorType;
use App\Enums\WikiFactStatus;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Helpers\LineDiff;
use App\Http\Controllers\Controller;
use App\Http\Requests\WikiPageStoreRequest;
use App\Http\Requests\WikiPageUpdateRequest;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiCascadeService;
use App\Services\Wiki\WikiMarkdown;
use App\Services\Wiki\WikiPageService;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiSkeletonService;
use App\Support\WikiConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Str;

class WikiController extends Controller implements HasMiddleware
{
    /**
     * Spec §9: wiki_enabled is the master switch — the module 404s when off.
     * Laravel 12 controllers use HasMiddleware with a static middleware() method.
     */
    public static function middleware(): array
    {
        return [
            fn ($request, $next) => WikiConfig::isEnabled() ? $next($request) : abort(404),
        ];
    }

    public function index()
    {
        $pages = WikiPage::active()->globalScope()->orderBy('kind')->orderBy('title')->get()->groupBy(fn ($p) => $p->kind->value);
        $health = $this->healthCounts(null);

        return view('wiki.index', ['pages' => $pages, 'client' => null, 'health' => $health]);
    }

    /**
     * psa-s5bf: consolidated single-scroll client-environment view (spec Option B).
     *
     * A tech working a queue under time pressure lands here and reads the whole client
     * environment on one scrollable page — overview, network, infrastructure, m365,
     * security, backup, applications, known-issues, history, notes — with a sticky
     * in-page anchor nav, instead of clicking through ten separate pages. Honours the
     * spec's "the whole picture in one place" principle; individual pages remain
     * reachable (edit / history / fact provenance) via each section's "Open" link.
     */
    public function clientIndex(Client $client, WikiSkeletonService $skeleton, WikiMarkdown $renderer)
    {
        $skeleton->ensureForClient($client); // lazy skeleton on first visit (spec §4.6)

        $pages = WikiPage::active()->forClient($client->id)->get();

        // The environment scroll inlines the short, read-often environment pages
        // (overview / environment / note kinds). Runbooks and deviations render via
        // their own cascade view (§4.5), so they stay as sidebar links, not sections.
        [$envPages, $otherPages] = $pages->partition(
            fn (WikiPage $p) => in_array($p->kind, [WikiPageKind::Overview, WikiPageKind::Environment, WikiPageKind::Note], true)
        );

        // Skeleton blueprint order first (overview → notes); any AI-created extras
        // fall after, ordered by title, so the scroll stays predictable.
        $order = array_flip(array_keys(WikiSkeletonService::blueprint()));
        $envPages = $envPages
            ->sort(fn (WikiPage $a, WikiPage $b) => [$order[$a->slug] ?? PHP_INT_MAX, $a->title] <=> [$order[$b->slug] ?? PHP_INT_MAX, $b->title])
            ->values();

        $summaries = $this->clientFactSummaries($client->id);

        $sections = $envPages->map(fn (WikiPage $p) => [
            'page' => $p,
            'anchor' => $this->anchorFor($p->slug),
            'html' => $renderer->render($p),
            'summary' => $summaries[$p->id] ?? null,
        ])->all();

        return view('wiki.environment', [
            'client' => $client,
            'sections' => $sections,
            'otherPages' => $otherPages->sortBy('title')->values(),
            'health' => $this->healthCounts($client->id),
        ]);
    }

    /**
     * psa-s5bf: per-page ambient provenance summary for the consolidated view —
     * "3 unverified · 1 disputed · 2 stale" (spec §8.1.1), silent when a page is clean.
     * Bulk-computed (two grouped queries) to avoid an N+1 across the section list.
     *
     * @return array<int, string> page_id => summary line
     */
    private function clientFactSummaries(int $clientId): array
    {
        $counts = WikiFact::where('client_id', $clientId)
            ->whereIn('status', [WikiFactStatus::Unverified->value, WikiFactStatus::Disputed->value])
            ->selectRaw('page_id, status, COUNT(*) as c')
            ->groupBy('page_id', 'status')
            ->get();

        // Staleness is a computed predicate — its own grouped pass (cf. sectionSummaries()).
        $stale = WikiFact::stale()
            ->where('client_id', $clientId)
            ->selectRaw('page_id, COUNT(*) as c')
            ->groupBy('page_id')
            ->pluck('c', 'page_id');

        $perPage = [];
        foreach ($counts as $row) {
            $perPage[$row->page_id][$row->status->value] = (int) $row->c;
        }

        $summaries = [];
        foreach (collect($perPage)->keys()->merge($stale->keys())->unique() as $pageId) {
            $parts = [];
            if ($u = $perPage[$pageId][WikiFactStatus::Unverified->value] ?? 0) {
                $parts[] = "{$u} unverified";
            }
            if ($d = $perPage[$pageId][WikiFactStatus::Disputed->value] ?? 0) {
                $parts[] = "{$d} disputed";
            }
            if ($s = (int) ($stale[$pageId] ?? 0)) {
                $parts[] = "{$s} stale";
            }
            if ($parts) {
                $summaries[$pageId] = implode(' · ', $parts);
            }
        }

        return $summaries;
    }

    /** psa-s5bf: stable, collision-safe DOM id for a page's section anchor in the consolidated view. */
    private function anchorFor(string $slug): string
    {
        return 'wiki-'.Str::slug($slug);
    }

    public function show(string $slug, WikiMarkdown $renderer)
    {
        $page = WikiPage::active()->globalScope()->where('slug', $slug)->firstOrFail();

        return $this->renderShow($page, null, $renderer);
    }

    public function clientShow(Client $client, string $slug, WikiMarkdown $renderer, WikiCascadeService $cascade, Request $request)
    {
        $page = WikiPage::active()->forClient($client->id)->where('slug', $slug)->first();

        // Cascade fallback (spec §4.5): client slug missing → global page, merged view.
        if (! $page) {
            $global = WikiPage::active()->globalScope()->where('slug', $slug)->firstOrFail();
            $merged = $cascade->mergedView($global, $client->id);

            return view('wiki.show', array_merge($this->pageNavVars($global, $client), [
                'page' => $global, 'client' => $client,
                'html' => $renderer->render($global, $merged['body_md']),
                'sectionSummaries' => [],
                'backlinks' => $global->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
                'facts' => collect(),
            ]));
        }

        // A deviation page viewed in client context renders merged with its parent (default on).
        // Orphan guard: parent_page_id is nullOnDelete, so a missing parent normally
        // implies a null FK — the ->parent check is belt-and-braces for broken data.
        if ($page->parent_page_id && $request->boolean('merged', true) && $page->parent) {
            $merged = $cascade->mergedView($page->parent, $client->id);

            return view('wiki.show', array_merge($this->pageNavVars($page, $client), [
                'page' => $page, 'client' => $client,
                'html' => $renderer->render($page, $merged['body_md']),
                'sectionSummaries' => [],
                'backlinks' => $page->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
                'facts' => collect(),
            ]));
        }

        return $this->renderShow($page, $client, $renderer);
    }

    private function renderShow(WikiPage $page, ?Client $client, WikiMarkdown $renderer)
    {
        return view('wiki.show', array_merge($this->pageNavVars($page, $client), [
            'page' => $page,
            'client' => $client,
            'html' => $renderer->render($page),
            'sectionSummaries' => $this->sectionSummaries($page),
            'backlinks' => $page->backlinks()->with('fromPage')->get(),
            'deviationAnchors' => [],
            'facts' => $page->facts()
                ->whereNot('status', \App\Enums\WikiFactStatus::Retired->value)
                ->orderBy('section_anchor')->orderBy('subject_key')
                ->get(),
        ]));
    }

    /**
     * psa-7ph7: variables for the _page_nav partial (siblings, index link, search).
     * Shared by renderShow() and the cascade fallback paths in clientShow().
     *
     * @return array{siblings: array<int, array{title:string,url:string,active:bool}>, indexUrl: string, searchAction: string, searchClientId: int|null}
     */
    private function pageNavVars(WikiPage $page, ?Client $client): array
    {
        return [
            'siblings' => $this->pageSiblings($page, $client),
            'indexUrl' => $client ? route('clients.wiki.index', $client) : route('wiki.index'),
            'searchAction' => route('wiki.search'),
            'searchClientId' => $client?->id,
        ];
    }

    /**
     * psa-7ph7: sibling pages in the same scope, ordered by kind/title, current page flagged active.
     *
     * @return array<int, array{title: string, url: string, active: bool}>
     */
    private function pageSiblings(WikiPage $page, ?Client $client): array
    {
        $query = $client
            ? WikiPage::active()->forClient($client->id)
            : WikiPage::active()->globalScope();

        return $query
            ->orderBy('kind')
            ->orderBy('title')
            ->get()
            ->map(fn ($p) => [
                'title' => $p->title,
                'url' => $client
                    ? route('clients.wiki.show', [$client, $p->slug])
                    : route('wiki.show', $p->slug),
                'active' => $p->id === $page->id,
            ])
            ->values()
            ->all();
    }

    /**
     * §8.1.1: ambient per-section counts ("3 unverified · 1 disputed · 2 stale"), zero-state silent.
     *
     * Staleness is a computed predicate (not a status), so stale facts are fetched via
     * WikiFact::stale() per page and merged into the per-anchor parts separately.
     *
     * @return array<string, string>
     */
    private function sectionSummaries(WikiPage $page): array
    {
        $rows = $page->facts()
            ->whereIn('status', [WikiFactStatus::Unverified->value, WikiFactStatus::Disputed->value])
            ->get()
            ->groupBy('section_anchor');

        // Staleness is computed — cannot be folded into the whereIn above.
        $staleByAnchor = WikiFact::stale()
            ->where('page_id', $page->id)
            ->get()
            ->groupBy('section_anchor');

        // Union of all anchors that have any counts worth showing.
        $allAnchors = $rows->keys()->merge($staleByAnchor->keys())->unique();

        $summaries = [];
        foreach ($allAnchors as $anchor) {
            $parts = [];
            $facts = $rows->get($anchor, collect());
            $unverified = $facts->where('status', WikiFactStatus::Unverified)->count();
            $disputed = $facts->where('status', WikiFactStatus::Disputed)->count();
            $stale = $staleByAnchor->get($anchor, collect())->count();

            if ($unverified) {
                $parts[] = "{$unverified} unverified";
            }
            if ($disputed) {
                $parts[] = "{$disputed} disputed";
            }
            if ($stale) {
                $parts[] = "{$stale} stale";
            }

            if ($parts) {
                $summaries[$anchor] = implode(' · ', $parts);
            }
        }

        return $summaries;
    }

    /** @return array{unverified: int, disputed: int, stale: int} */
    private function healthCounts(?int $clientId): array
    {
        $scope = fn ($q) => $clientId
            ? $q->where('client_id', $clientId)
            : $q->whereNull('client_id');

        return [
            'unverified' => WikiFact::where('status', WikiFactStatus::Unverified->value)->tap($scope)->count(),
            'disputed' => WikiFact::where('status', WikiFactStatus::Disputed->value)->tap($scope)->count(),
            'stale' => WikiFact::stale()->tap($scope)->count(),
        ];
    }

    public function create(Request $request)
    {
        $client = $request->filled('client_id') ? Client::findOrFail($request->integer('client_id')) : null;

        // Deviations need a parent picker (future work) and overview pages are skeleton-owned; the service still validates as the real guard.
        return view('wiki.create', ['client' => $client, 'kinds' => array_values(array_filter(\App\Enums\WikiPageKind::cases(), fn ($k) => ! in_array($k, [\App\Enums\WikiPageKind::Deviation, \App\Enums\WikiPageKind::Overview], true)))]);
    }

    public function store(WikiPageStoreRequest $request, WikiPageService $pages)
    {
        $data = $request->validated();
        $clientId = $data['client_id'] ?? null;

        try {
            $page = $pages->create([
                'scope' => $clientId ? WikiScope::Client : WikiScope::Global,
                'client_id' => $clientId,
                'slug' => $data['slug'],
                'title' => $data['title'],
                'kind' => $data['kind'],
                'parent_page_id' => $data['parent_page_id'] ?? null,
                'body_md' => $data['body_md'] ?? '',
            ], WikiAuthorType::Human, auth()->id());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect($this->pageUrl($page))->with('success', 'Page created.');
    }

    public function edit(WikiPage $page)
    {
        return view('wiki.edit', ['page' => $page]);
    }

    public function update(WikiPage $page, WikiPageUpdateRequest $request, WikiPageService $pages)
    {
        $data = $request->validated();

        // Optimistic concurrency, same pattern as ClientService::updateSiteNotes.
        if ($page->updated_at->toIso8601String() !== $data['expected_updated_at']) {
            return back()->withInput()->with('error', 'This page changed while you were editing. Review and retry.');
        }

        if (isset($data['title'])) {
            $page->update(['title' => $data['title']]);
        }
        $pages->updateBody($page, $data['body_md'], WikiAuthorType::Human, auth()->id(),
            $data['change_summary'] ?: 'Edited');

        return redirect($this->pageUrl($page))->with('success', 'Page updated.');
    }

    public function history(WikiPage $page)
    {
        $revisions = $page->revisions()->with('author')->take(50)->get();

        // Diff each revision against its predecessor (revisions are ordered newest-first).
        $diffs = [];
        foreach ($revisions as $index => $revision) {
            $previous = $revisions[$index + 1] ?? null;
            $diffs[$revision->id] = LineDiff::diff($previous?->body_md ?? '', $revision->body_md);
        }

        return view('wiki.history', ['page' => $page, 'revisions' => $revisions, 'diffs' => $diffs]);
    }

    private function pageUrl(WikiPage $page): string
    {
        return $page->client_id
            ? route('clients.wiki.show', [$page->client_id, $page->slug])
            : route('wiki.show', $page->slug);
    }

    public function archive(WikiPage $page, WikiPageService $pages)
    {
        $pages->archive($page, WikiAuthorType::Human, auth()->id());

        return redirect($page->client_id
            ? route('clients.wiki.index', $page->client_id)
            : route('wiki.index')
        )->with('success', 'Page archived.');
    }

    public function search(Request $request, WikiSearchService $searcher)
    {
        $query = trim((string) $request->query('q', ''));
        $clientId = $request->filled('client_id') ? $request->integer('client_id') : null;
        $client = $clientId ? Client::find($clientId) : null;

        $results = $query === ''
            ? ['pages' => collect(), 'facts' => collect()]
            : $searcher->search($query, $clientId);

        return view('wiki.search', ['query' => $query, 'client' => $client, 'results' => $results]);
    }
}
