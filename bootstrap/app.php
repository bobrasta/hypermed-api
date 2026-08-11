<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// putenv()/getenv() are process-wide, not per-request — under a threaded SAPI
// (e.g. XAMPP's Apache mpm_winnt, many threads sharing one process) concurrent
// requests' env bootstrapping can race and transiently read back an empty
// DB_CONNECTION/DB_DATABASE/etc, silently falling through to hardcoded config
// defaults. $_ENV/$_SERVER are correctly isolated per request even there, so
// restrict Dotenv to those instead. Must run before anything else boots.
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'cache.headers.api' => \App\Http\Middleware\CacheControlHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $_e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $_e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $_e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Method not allowed.'], 405);
            }
        });
    })->create();
