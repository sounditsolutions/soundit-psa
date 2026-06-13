<?php

namespace App\Http\Controllers\Web;

use App\Enums\WikiFactStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiCascadeService;
use App\Services\Wiki\WikiMarkdown;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Http\Request;

class WikiController extends Controller
{
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
        if ($page->parent_page_id && $request->boolean('merged', true)) {
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

    // ── Implemented in Task 16 ──
    public function create(Request $request)
    {
        abort(404);
    }

    public function store()
    {
        abort(404);
    }

    public function edit(WikiPage $page)
    {
        abort(404);
    }

    public function update(WikiPage $page)
    {
        abort(404);
    }

    // ── Implemented in Task 17 ──
    public function history(WikiPage $page)
    {
        abort(404);
    }

    // ── Implemented in Task 18 ──
    public function search(Request $request)
    {
        abort(404);
    }
}
