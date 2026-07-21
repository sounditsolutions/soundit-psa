<?php

namespace App\Http\Middleware;

use App\Support\AssistantConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * psa-uw2o: the functional half of the "Enable AI Assistant" toggle.
 *
 * Every other reader of `assistant_enabled` is a view concern (the bubble, the
 * topbar entry, a ticket button). Without this the toggle hid the door and left
 * it unlocked — the endpoints kept running the tool loop, including the
 * Assistant's two write tools (create_ticket, add_ticket_note).
 *
 * Mirrors PortalEnabled: this codebase already ships a feature-gate middleware
 * for the portal, so the pattern is the repo's own, not a new invention.
 */
class AssistantEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AssistantConfig::isEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
