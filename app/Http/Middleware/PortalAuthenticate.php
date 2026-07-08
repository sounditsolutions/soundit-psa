<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PortalAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $person = Auth::guard('portal')->user();

        // canAccessPortal() enforces is_active + portal_enabled + client stage =
        // Active (defense-in-depth: a prospect must never hold a live session).
        if (! $person || ! $person->canAccessPortal() || ! $person->person_type->canHavePortal()) {
            Auth::guard('portal')->logout();

            if ($request->expectsJson()) {
                abort(401, 'Unauthenticated.');
            }

            return redirect()->route('portal.login');
        }

        return $next($request);
    }
}
