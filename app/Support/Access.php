<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class Access
{
    public static function allows(?User $user, string $ability): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        $role = $user->role;

        return match ($ability) {
            'read' => in_array($role, ['admin', 'operator', 'viewer'], true),
            'write','accept-work' => in_array($role, ['admin', 'operator'], true),
            'manage-users','manage-api','configure','reveal-license' => $role === 'admin',
            default => false,
        };
    }

    public static function check(?User $user, string $ability): void
    {
        abort_unless(self::allows($user, $ability), 403, 'Недостаточно прав для этого действия.');
        if (request()->is('api/*')) {
            abort_unless($user->currentAccessToken() instanceof PersonalAccessToken && $user->tokenCan($ability), 403, 'Токен не разрешает это действие.');
        }
    }
}
