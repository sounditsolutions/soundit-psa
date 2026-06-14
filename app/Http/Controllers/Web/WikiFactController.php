<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\WikiFactCorrectRequest;
use App\Models\WikiFact;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiFactService;
use App\Support\WikiConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class WikiFactController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // Same master gate as WikiController (spec §9).
        return [new Middleware(fn ($request, $next) => WikiConfig::isEnabled() ? $next($request) : abort(404))];
    }

    public function confirm(WikiFact $fact, WikiFactService $facts)
    {
        // NOTE: the merged Task 5 WikiFactService API is confirm(WikiFact, User)
        // (WikiFactServiceTest calls $service->confirm($fact, $user)), so pass the
        // User instance, not auth()->id().
        $facts->confirm($fact, auth()->user());

        return $this->backToPage($fact, 'Fact confirmed.');
    }

    public function retire(WikiFact $fact, WikiFactService $facts, WikiComposerService $composer)
    {
        $facts->retire($fact, auth()->user());
        $composer->composeSection($fact->page->fresh(), $fact->section_anchor);

        return $this->backToPage($fact, 'Fact retired.');
    }

    public function correct(WikiFact $fact, WikiFactCorrectRequest $request, WikiFactService $facts, WikiComposerService $composer, \App\Services\Wiki\Mining\WikiRedactor $redactor)
    {
        $statement = $request->validated('statement');

        // Security review M3: spec §4.4 requires even human-authored content to be
        // credential-scanned — a tech typing "DC01 admin pw is Hunter2" must not persist a
        // secret as a pinned confirmed fact. Reject on a credential hit (injection scanning
        // is skipped here: the human author is trusted, only secret-at-rest is the concern).
        $hits = collect($redactor->scan($statement))->where('class', 'credential');
        if ($hits->isNotEmpty()) {
            return back()->with('error', 'Remove the credential from the statement before saving — secrets are not stored in the wiki.');
        }

        // Merged Task 5 API: correct(WikiFact, string, User).
        $facts->correct($fact, $statement, auth()->user());
        $composer->composeSection($fact->page->fresh(), $fact->section_anchor);

        return $this->backToPage($fact, 'Fact corrected.');
    }

    public function resolve(WikiFact $fact, Request $request, WikiFactService $facts, WikiComposerService $composer)
    {
        $resolution = (string) $request->input('resolution');

        try {
            // Merged Task 5 API: resolveDispute(WikiFact $challenger, string $resolution, User).
            // The service validates $resolution is accept|dismiss and throws on anything else.
            $facts->resolveDispute($fact, $resolution, auth()->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $composer->composeSection($fact->page->fresh(), $fact->section_anchor);

        return $this->backToPage($fact, $resolution === 'accept' ? 'Challenge accepted.' : 'Challenge dismissed.');
    }

    private function backToPage(WikiFact $fact, string $message)
    {
        $page = $fact->page;

        return redirect($page->client_id
            ? route('clients.wiki.show', [$page->client_id, $page->slug])
            : route('wiki.show', $page->slug)
        )->with('success', $message);
    }
}
