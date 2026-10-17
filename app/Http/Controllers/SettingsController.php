<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsController extends Controller
{
    public const TOKEN_ABILITIES = ['read' => 'Чтение API', 'write' => 'Изменение оборудования и данных', 'accept-work' => 'Принятие результатов обслуживания', 'reveal-license' => 'Раскрытие лицензионного ключа', 'purge-assets' => 'Необратимое удаление списанного имущества'];

    public function index(Request $request)
    {
        Access::check($request->user(), 'read');
        $admin = Access::allows($request->user(), 'manage-users');

        return view('settings.index', [
            'profile' => $request->user(), 'admin' => $admin,
            'users' => $admin ? User::orderBy('name')->get() : collect(),
            'tokens' => $admin ? $request->user()->tokens()->orderByDesc('id')->get(['id', 'tokenable_id', 'tokenable_type', 'name', 'abilities', 'created_at', 'last_used_at', 'expires_at']) : collect(),
            'aliases' => $admin ? QrAlias::orderByDesc('id')->paginate(30) : null,
            'assets' => $admin ? Asset::whereNull('archived_at')->where('status', '!=', 'written_off')->orderBy('name')->get(['id', 'uuid', 'code', 'name']) : collect(),
            'locations' => $admin ? Location::with('building')->whereNull('archived_at')->orderBy('name')->get(['id', 'uuid', 'code', 'name', 'building_id']) : collect(),
            // Pull removes the secret immediately; browser refresh cannot reveal it again.
            'createdToken' => $admin ? $request->session()->pull('created_api_token') : null,
            'createdTokenName' => $admin ? $request->session()->pull('created_api_token_name') : null,
        ]);
    }

    public function user(Request $request)
    {
        Access::check($request->user(), 'manage-users');
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'username' => ['nullable', 'string', 'min:3', 'max:64', 'regex:/\A[a-z0-9._-]{3,64}\z/', Rule::unique('users', 'username')],
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|max:128|confirmed',
            'role' => ['required', Rule::in(['admin', 'operator', 'viewer'])], 'is_active' => 'required|boolean',
        ]);
        DB::transaction(function () use ($request, $data) {
            $this->lockedActor($request->user(), 'manage-users');
            $this->assertUsernameAvailable($data['username'] ?? null);
            $user = User::create(['name' => $data['name'], 'username' => $data['username'] ?? null, 'email' => $data['email'], 'password' => $data['password'], 'role' => $data['role'], 'is_active' => (bool) $data['is_active']]);
            Audit::record('settings.user.created', $user, ['role' => $user->role, 'is_active' => $user->is_active]);
        }, 3);

        return redirect()->route('settings.index')->with('success', 'Пользователь создан.');
    }

    public function updateUser(Request $request, User $user)
    {
        Access::check($request->user(), 'manage-users');
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'expected_state' => 'required|string|size:64',
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'username' => ['nullable', 'string', 'min:3', 'max:64', 'regex:/\A[a-z0-9._-]{3,64}\z/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'operator', 'viewer'])], 'is_active' => 'required|boolean',
        ]);
        $logoutCurrent = DB::transaction(function () use ($request, $user, $data) {
            $actor = $this->lockedActor($request->user(), 'manage-users');
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(self::userRevision($target), $data['expected_state']), 409, 'Пользователь изменился. Обновите страницу и проверьте актуальные права.');
            if ($target->auth_provider === 'ldap') {
                foreach (['name', 'username', 'email', 'role'] as $field) {
                    abort_if(array_key_exists($field, $data) && $data[$field] !== $target->$field, 422, 'Имя, логин, почта и роль доменной учётной записи управляются через Active Directory.');
                }
            }
            $active = (bool) $data['is_active'];
            abort_if($target->id === $actor->id && (! $active || $data['role'] !== $target->role), 409, 'Собственную роль и активность меняет другой администратор.');
            if ($target->role === 'admin' && $target->is_active && (! $active || $data['role'] !== 'admin')) {
                $admins = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->lockForUpdate()->get(['id']);
                abort_if($admins->count() < 2, 409, 'Нельзя отключить или понизить последнего активного администратора.');
                if ($target->auth_provider === 'local') {
                    abort_if(User::where('auth_provider', 'local')->where('role', 'admin')->where('is_active', true)->lockForUpdate()->get(['id'])->count() < 2, 409, 'Нельзя отключить последнего локального администратора: он нужен при недоступности домена.');
                }
            }
            $before = ['role' => $target->role, 'is_active' => $target->is_active];
            $rightsChanged = $target->role !== $data['role'] || $target->is_active !== $active;
            // Older clients may omit username; an explicit empty field clears it.
            $username = array_key_exists('username', $data) ? $data['username'] : $target->username;
            $this->assertUsernameAvailable($username, $target->id);
            $identityChanged = $target->username !== $username || $target->email !== $data['email'];
            $revoke = $rightsChanged || $identityChanged;
            $target->fill(['name' => $data['name'], 'username' => $username, 'email' => $data['email'], 'role' => $data['role'], 'is_active' => $active]);
            if ($revoke) {
                $target->remember_token = Str::random(60);
            }
            $target->save();
            if ($revoke) {
                $this->revokeCredentials($target);
            }
            Audit::record('settings.user.updated', $target, ['before' => $before, 'after' => ['role' => $target->role, 'is_active' => $target->is_active], 'identity_changed' => $identityChanged, 'credentials_revoked' => $revoke]);

            return $revoke && $target->id === $actor->id;
        }, 3);

        if ($logoutCurrent) {
            // Otherwise StartSession could recreate the current DB session after revocation.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('success', 'Данные входа изменены. Войдите снова с новым логином или почтой.');
        }

        return redirect()->route('settings.index')->with('success', 'Пользователь обновлён. При изменении логина, почты или прав прежние сеансы и API-токены отозваны.');
    }

    public function password(Request $request)
    {
        Access::check($request->user(), 'read');
        abort_if($request->user()->auth_provider === 'ldap', 422, 'Пароль доменной учётной записи меняется в Active Directory.');
        $data = $request->validate(['current_password' => 'required|string|max:1024|current_password:web', 'password' => 'required|string|min:12|max:128|confirmed|different:current_password']);
        $updated = DB::transaction(function () use ($request, $data) {
            $actor = $this->lockedActor($request->user(), 'read');
            // Repeat against the locked row: another password change may have won after validation.
            if (! Hash::check($data['current_password'], $actor->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Текущий пароль уже изменился.']);
            }
            $actor->password = $data['password'];
            $actor->remember_token = Str::random(60);
            $actor->save();
            $this->revokeCredentials($actor, $request->session()->getId());
            Audit::record('settings.password.changed', $actor);

            return $actor;
        }, 3);
        Auth::guard('web')->setUser($updated);
        $request->session()->forget(['created_api_token', 'created_api_token_name']);
        $request->session()->regenerate();

        return redirect()->route('settings.index')->with('success', 'Пароль изменён. Другие сеансы и API-токены отозваны.');
    }

    public function token(Request $request)
    {
        Access::check($request->user(), 'manage-api');
        abort_unless(config('session.encrypt'), 503, 'Для однократной выдачи токена требуется SESSION_ENCRYPT=true.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'abilities' => 'required|array|min:1|max:5', 'abilities.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::TOKEN_ABILITIES))],
            'expires_in_days' => 'required|integer|min:1|max:365',
        ]);
        if (! in_array('read', $data['abilities'], true)) {
            throw ValidationException::withMessages(['abilities' => 'Для доступа к API необходимо включить read; остальные возможности добавляются отдельно.']);
        }
        $plain = DB::transaction(function () use ($request, $data) {
            $actor = $this->lockedActor($request->user(), 'manage-api');
            $issued = $actor->createToken($data['name'], $data['abilities'], now()->addDays($data['expires_in_days']));
            Audit::record('settings.api-token.created', $issued->accessToken, ['name' => $data['name'], 'abilities' => $data['abilities'], 'expires_at' => $issued->accessToken->expires_at?->toIso8601String()]);

            return $issued->plainTextToken;
        }, 3);

        return redirect()->route('settings.index')->with('created_api_token', $plain)->with('created_api_token_name', $data['name'])->with('success', 'Токен создан. Скопируйте его сейчас: повторное раскрытие отсутствует.');
    }

    public function revoke(Request $request, PersonalAccessToken $token)
    {
        Access::check($request->user(), 'manage-api');
        DB::transaction(function () use ($request, $token) {
            $actor = $this->lockedActor($request->user(), 'manage-api');
            $target = PersonalAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            abort_unless($target->tokenable_type === $actor->getMorphClass() && (int) $target->tokenable_id === (int) $actor->id, 404);
            Audit::record('settings.api-token.revoked', $target, ['name' => $target->name]);
            $target->delete();
        }, 3);

        return redirect()->route('settings.index')->with('success', 'API-токен отозван.');
    }

    public function alias(Request $request)
    {
        Access::check($request->user(), 'configure');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['asset-code', 'asset-id', 'location-id'])],
            'alias' => ['required', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'target_type' => ['required', Rule::in(['asset', 'location'])], 'target_uuid' => 'required|uuid',
        ]);
        $value = $data['alias'];
        if ($data['kind'] === 'asset-id' && ! preg_match('/^[1-9][0-9]{0,19}$/D', $value)) {
            throw ValidationException::withMessages(['alias' => 'Старый ID предмета задаётся положительным целым числом без префикса.']);
        }
        if ($data['kind'] === 'asset-code' && preg_match('/^EQ-[0-9]+$/iD', $value)) {
            $value = strtoupper($value);
        }
        $requiredType = $data['kind'] === 'location-id' ? 'location' : 'asset';
        if ($data['target_type'] !== $requiredType) {
            throw ValidationException::withMessages(['target_type' => 'Тип цели не соответствует выбранному пространству старых идентификаторов.']);
        }
        DB::transaction(function () use ($request, $data, $value) {
            $this->lockedActor($request->user(), 'configure');
            Codes::lockPhysicalGraph();
            abort_if(DB::table('retired_asset_codes')->where('code', $value)->exists(), 409, 'Код удалённого предмета зарезервирован и не переназначается.');
            $target = ($data['target_type'] === 'asset' ? Asset::query() : Location::query())->where('uuid', $data['target_uuid'])->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            abort_if($target instanceof Asset && $target->status === 'written_off', 422, 'Нельзя назначить новую метку списанному предмету.');
            foreach ([Asset::class => 'asset', Location::class => 'location'] as $model => $kind) {
                $current = $model::where('code', $value)->first();
                abort_if($current && ($kind !== $data['target_type'] || $current->id !== $target->id), 409, 'Такой код уже принадлежит другому объекту.');
            }
            // Keep the generated namespace available for future human codes; no alias may pre-empt it.
            abort_if(preg_match('/^SKX-/i', $value) && $target->code !== $value, 409, 'Пространство SKX зарезервировано для постоянных меток новых объектов.');
            $qualified = $data['kind'].':'.$value;
            $existing = QrAlias::where('alias', $qualified)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->target_type === $data['target_type'] && (int) $existing->target_id === (int) $target->id, 409, 'Этот алиас уже связан с другой целью и не переназначается.');

                return;
            }
            $alias = QrAlias::create(['alias' => $qualified, 'target_type' => $data['target_type'], 'target_id' => $target->id]);
            Audit::record('settings.qr-alias.created', $alias, ['kind' => $data['kind'], 'target_type' => $data['target_type'], 'target_uuid' => $target->uuid]);
        }, 3);

        return redirect()->route('settings.index')->with('success', 'Проверенная связь старого идентификатора сохранена. Данные источника не импортировались.');
    }

    /** Optimistic revision without a users.version column or exposure of the password hash. */
    public static function userRevision(User $user): string
    {
        return hash_hmac('sha256', json_encode([$user->id, $user->name, $user->username, $user->email, $user->role, (bool) $user->is_active, $user->getRawOriginal('password'), $user->getRawOriginal('updated_at')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function assertUsernameAvailable(?string $username, ?int $except = null): void
    {
        if ($username === null) {
            return;
        }
        $query = User::where('username', $username);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        // Recheck after the shared settings mutex, not only before waiting for it.
        if ($query->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages(['username' => 'Этот логин уже занят.']);
        }
    }

    private function lockedActor(User $actor, string $ability): User
    {
        DB::table('code_counters')->insertOrIgnore(['prefix' => 'AUTH-SETTINGS', 'next_value' => 1]);
        DB::table('code_counters')->where('prefix', 'AUTH-SETTINGS')->lockForUpdate()->first();
        $fresh = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        Access::check($fresh, $ability);

        return $fresh;
    }

    private function revokeCredentials(User $user, ?string $exceptSession = null): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id);
            if ($exceptSession) {
                $sessions->where('id', '!=', $exceptSession);
            }
            $sessions->delete();
        }
    }
}
