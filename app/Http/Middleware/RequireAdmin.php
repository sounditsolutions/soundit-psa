<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First enforcement slice of the UserRole authorization layer (#762): the
 * enum landed with every existing user migrated to Admin precisely so gates
 * could be added without a behaviour flip — this is the first such gate.
 *
 * Guards credential-write actions: a non-admin staff user must not be able to
 * overwrite integration secrets or repoint outbound base URLs (#724 is what a
 * repointed base URL costs — the stored Bearer key ships to the host it
 * names). 403, not 404: these routes sit behind `auth` on pages the user can
 * already see, so hiding the surface buys nothing and a plain refusal is the
 * readable answer for both the browser form and a JSON caller.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Read the role off the model rather than through a User helper: the
        // UserRole enum (#762) landed with no accessors, and normalising here
        // keeps the gate correct whether `role` is cast to the enum or still
        // reads as its raw string. Anything that is not Admin — a guest, a
        // null role, an unknown value — fails closed.
        $role = $request->user()?->role;

        if ($role instanceof \BackedEnum) {
            $role = $role->value;
        }

        if (! is_string($role) || strtolower($role) !== 'admin') {
            abort(403, 'Administrator access required.');
        }

        return $next($request);
    }
}
