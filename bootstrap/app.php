<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('portal')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '127.0.0.1');
        $middleware->web(append: [
            \App\Http\Middleware\CheckStorageHealth::class,
        ]);
        $middleware->alias([
            'portal.enabled' => \App\Http\Middleware\PortalEnabled::class,
            'portal.auth' => \App\Http\Middleware\PortalAuthenticate::class,
            'portal.scope' => \App\Http\Middleware\PortalClientScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
