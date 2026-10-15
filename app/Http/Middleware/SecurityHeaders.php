<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', $request->is('setup', 'login') ? 'no-referrer' : 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if ($request->user() || $request->is('login', 'setup', 'api/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
