<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\WikiFactCorrectRequest;
use App\Models\WikiFact;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiFactService;
use App\Support\WikiConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class WikiFactController extends Controller implements HasMiddleware
{
    /**
     * Spec §9: wiki_enabled is the master switch — the module 404s when off.
     * Mirror the exact pattern used by WikiController.
     */
    public static function middleware(): array
    {
        return [
            fn ($request, $next) => WikiConfig::isEnabled() ? $next($request) : abort(404),
        ];
    }

    /**
     * Promote an unverified fact to confirmed.
     */
    public function confirm(WikiFact $fact, WikiFactService $facts)
    {
        $facts->confirm($fact, auth()->user());

        return back()->with('success', 'Fact confirmed.');
    }

    /**
     * Retire a fact (explicit human removal). Recomposes the section so the
     * page body no longer reflects the retired fact.
     */
    public function retire(WikiFact $fact, WikiFactService $facts, WikiComposerService $composer)
    {
        $facts->retire($fact);

        $composer->composeSection($fact->page->fresh(), $fact->section_anchor);

        return back()->with('success', 'Fact retired.');
    }

    /**
     * Correct a fact: creates a new pinned human-sourced confirmed fact, retires
     * the old one, and recomposes the section.
     *
     * Security M3: scan the incoming statement for credentials before storage.
     * Reject on hit — return back with validation error, never call the service.
     */
    public function correct(WikiFact $fact, WikiFactCorrectRequest $request, WikiFactService $facts, WikiRedactor $redactor, WikiComposerService $composer)
    {
        $statement = $request->validated()['statement'];

        // M3: credential scan on human-supplied statement before any write
        $violations = $redactor->scan($statement);
        if (! empty($violations)) {
            return back()
                ->withInput()
                ->withErrors(['statement' => 'The statement contains a potential credential or secret and cannot be saved.']);
        }

        $new = $facts->correct($fact, $statement, auth()->user());

        $composer->composeSection($new->page->fresh(), $new->section_anchor);

        return back()->with('success', 'Fact corrected.');
    }

    /**
     * Resolve a dispute. The {fact} binding must be the CHALLENGER row.
     * $request->input('action') is either 'accept' or 'dismiss'.
     * Recomposes the section after resolution so the page body reflects the outcome.
     */
    public function resolve(WikiFact $fact, Request $request, WikiFactService $facts, WikiComposerService $composer)
    {
        $request->validate([
            'action' => ['required', 'in:accept,dismiss'],
        ]);

        $facts->resolveDispute($fact, $request->input('action'), auth()->user());

        $composer->composeSection($fact->page->fresh(), $fact->section_anchor);

        return back()->with('success', 'Dispute resolved.');
    }
}
