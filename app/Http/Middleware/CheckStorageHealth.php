<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class CheckStorageHealth
{
    /**
     * Check that critical storage directories are writable by the web server.
     *
     * is_writable() is a single syscall — essentially free on every request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $warnings = [];

        $dirs = [
            'framework/cache'    => storage_path('framework/cache'),
            'framework/sessions' => storage_path('framework/sessions'),
            'logs'               => storage_path('logs'),
        ];

        foreach ($dirs as $label => $path) {
            if (is_dir($path) && ! is_writable($path)) {
                $warnings[] = "storage/{$label}";
            }
        }

        View::share('storageWarnings', $warnings);

        return $next($request);
    }
}
