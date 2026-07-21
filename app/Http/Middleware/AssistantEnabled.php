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
 * Modelled on PortalEnabled, with one deliberate difference. PortalEnabled
 * guards HTML page routes, where a bare 404 renders something a human can
 * read. These six routes are a JSON API consumed by already-rendered UI, and
 * abort(404) emits {"message":""} — no `error` key, which is the only key the
 * assistant's JS reads. So the refusal arrived as a generic "Request failed",
 * or on the load paths as nothing at all (psa-uw2o.4).
 *
 * AssistantController already answers RuntimeException with ['error' => ...],
 * and the service guard already throws the right sentence. A JSON caller gets
 * that same shape here; a browser still gets the bare 404 so the surface is not
 * advertised.
 */
class AssistantEnabled
{
    public const DISABLED_MESSAGE = 'The AI assistant is disabled.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! AssistantConfig::isEnabled()) {
            if ($request->expectsJson()) {
                abort(response()->json(['error' => self::DISABLED_MESSAGE], 403));
            }

            abort(404);
        }

        return $next($request);
    }
}
