<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class Permission
{
    public function handle(Request $request, Closure $next, string $ability = 'read')
    {
        Access::check($request->user(), $ability);
        if ($request->is('api/*')) {
            $token = $request->user()->currentAccessToken();
            abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken && $request->user()->tokenCan($ability), 403, 'Токен не разрешает это действие.');
        }

        return $next($request);
    }
}
