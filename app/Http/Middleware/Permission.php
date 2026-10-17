<?php

namespace App\Http\Middleware;

use App\Services\DirectoryAuthService;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class Permission
{
    public function handle(Request $request, Closure $next, string $ability = 'read')
    {
        if ($request->user()) {
            app(DirectoryAuthService::class)->ensureCurrent($request->user());
        }
        Access::check($request->user(), $ability);
        if ($request->is('api/*')) {
            $token = $request->user()->currentAccessToken();
            abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken && $token->newQuery()->whereKey($token->id)->exists() && $request->user()->tokenCan($ability), 403, 'Токен не разрешает это действие.');
        }

        return $next($request);
    }
}
