<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectUsersTo(AppServiceProvider::HOME);

        $middleware->web([
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->statefulApi();
        // Include the checkpoint trace middleware for API routes. The middleware
        // itself checks the `yaffa.balance_checkpoint_trace_enabled` config and
        // is a no-op when disabled.
        $middleware->api(
            \App\Http\Middleware\CheckpointTraceMiddleware::class,
            \App\Http\Middleware\SetLocale::class
        );

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '/telescope/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
