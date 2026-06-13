<?php

namespace App\Http\Controllers\Web;

use App\Enums\WikiAuthorType;
use App\Enums\WikiFactStatus;
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

    public function clientIndex(Client $client, WikiSkeletonService $skeleton)
    {
        $skeleton->ensureForClient($client); // lazy skeleton on first visit (spec §4.6)
        $pages = WikiPage::active()->forClient($client->id)->orderBy('kind')->orderBy('title')->get()->groupBy(fn ($p) => $p->kind->value);
        $health = $this->healthCounts($client->id);

        return view('wiki.index', ['pages' => $pages, 'client' => $client, 'health' => $health]);
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

            return view('wiki.show', [
                'page' => $global, 'client' => $client,
                'html' => $renderer->render($global, $merged['body_md']),
                'sectionSummaries' => [],
                'backlinks' => $global->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
            ]);
        }

        // A deviation page viewed in client context renders merged with its parent (default on).
        // Orphan guard: parent_page_id is nullOnDelete, so a missing parent normally
        // implies a null FK — the ->parent check is belt-and-braces for broken data.
        if ($page->parent_page_id && $request->boolean('merged', true) && $page->parent) {
            $merged = $cascade->mergedView($page->parent, $client->id);

            return view('wiki.show', [
                'page' => $page, 'client' => $client,
                'html' => $renderer->render($page, $merged['body_md']),
                'sectionSummaries' => [],
                'backlinks' => $page->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
            ]);
        }

        return $this->renderShow($page, $client, $renderer);
    }

    private function renderShow(WikiPage $page, ?Client $client, WikiMarkdown $renderer)
    {
        return view('wiki.show', [
            'page' => $page,
            'client' => $client,
            'html' => $renderer->render($page),
            'sectionSummaries' => $this->sectionSummaries($page),
            'backlinks' => $page->backlinks()->with('fromPage')->get(),
            'deviationAnchors' => [],
        ]);
    }

    /**
     * §8.1.1: ambient per-section counts ("3 unverified · 1 disputed"), zero-state silent.
     *
     * @return array<string, string>
     */
    private function sectionSummaries(WikiPage $page): array
    {
        $rows = $page->facts()
            ->whereIn('status', [WikiFactStatus::Unverified->value, WikiFactStatus::Disputed->value])
            ->get()
            ->groupBy('section_anchor');

        $summaries = [];
        foreach ($rows as $anchor => $facts) {
            $parts = [];
            $unverified = $facts->where('status', WikiFactStatus::Unverified)->count();
            $disputed = $facts->where('status', WikiFactStatus::Disputed)->count();
            if ($unverified) {
                $parts[] = "{$unverified} unverified";
            }
            if ($disputed) {
                $parts[] = "{$disputed} disputed";
            }
            $summaries[$anchor] = implode(' · ', $parts);
        }

        return $summaries;
    }

    /** @return array{unverified: int, disputed: int} */
    private function healthCounts(?int $clientId): array
    {
        $query = WikiFact::query()->when(
            $clientId,
            fn ($q) => $q->where('client_id', $clientId),
            fn ($q) => $q->whereNull('client_id'),
        );

        return [
            'unverified' => (clone $query)->where('status', WikiFactStatus::Unverified->value)->count(),
            'disputed' => (clone $query)->where('status', WikiFactStatus::Disputed->value)->count(),
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
