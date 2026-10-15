<?php

use App\Http\Middleware\Idempotent;
use App\Http\Middleware\Permission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->trimStrings(except: ['alias']);
        $middleware->alias(['permission' => Permission::class, 'idempotent' => Idempotent::class]);
        $middleware->trustHosts(at: fn () => array_map(fn ($host) => '^'.preg_quote($host, '/').'$', config('skladex.allowed_hosts', [])), subdomains: false);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/app');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'setup_token', 'license_key', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
