<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'email_verified' => \App\Http\Middleware\EnsureEmailVerified::class,
        ]);

        // Honeypot contact : le middleware global TrimStrings vidait
        // site='   ' → '' AVANT le contrôleur (empty('') = false côté
        // contrôleur devient inutilisable ; ligne insérée en base au lieu
        // du refus silencieux legacy). 'site' doit arriver brut.
        $middleware->trimStrings(except: ['site']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tests-supervisors (BUG-1, 3× signalé) : toute requête API non authentifiée
        // doit répondre 401 JSON — même sans Accept: application/json. Sans cette
        // règle, Sanctum tente un redirect vers route('login') qui n'existe pas
        // en API stateless → 500 + stack trace.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
