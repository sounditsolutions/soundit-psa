<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class PortalClientScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $person = Auth::guard('portal')->user();

        if (! $person || ! $person->client_id) {
            abort(403, 'Your account is not associated with a company.');
        }

        $request->attributes->set('portal_client_id', $person->client_id);
        $request->attributes->set('portal_person', $person);

        View::share('portalPerson', $person);
        View::share('portalClientId', $person->client_id);

        return $next($request);
    }
}
