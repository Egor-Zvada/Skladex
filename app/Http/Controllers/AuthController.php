<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(Request $r)
    {
        $data = $r->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:1024']);
        $identifier = mb_strtolower(trim($data['email']));
        $field = str_contains($identifier, '@') ? 'email' : 'username';
        if (! Auth::attempt([$field => $identifier, 'password' => $data['password'], 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Не удалось войти. Проверьте логин или почту и пароль.']);
        }
        $r->session()->regenerate();
        Audit::record('auth.login', $r->user());

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Audit::record('auth.logout', $r->user());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('public.home');
    }

    public function setup(Request $r)
    {
        abort_if(User::exists(), 404);

        return view('auth.setup');
    }

    public function initialize(Request $r)
    {
        $setupToken = $r->input('setup_token');
        abort_unless(is_string($setupToken) && config('skladex.setup_token_hash') && hash_equals((string) config('skladex.setup_token_hash'), hash('sha256', $setupToken)), 403, 'Неверный одноразовый ключ установки.');
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:128|confirmed', 'setup_token' => 'required|string|max:128']);
        $user = DB::transaction(function () use ($data) {
            DB::table('code_counters')->insertOrIgnore(['prefix' => 'SETUP', 'next_value' => 1]);
            DB::table('code_counters')->where('prefix', 'SETUP')->lockForUpdate()->first();
            abort_if(User::exists(), 409, 'Первый администратор уже создан.');

            return User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'role' => 'admin', 'is_active' => true]);
        });
        Auth::login($user);
        $r->session()->regenerate();
        Audit::record('setup.completed', $user);

        return redirect()->route('dashboard')->with('success', 'Администратор создан. Система готова к заполнению.');
    }
}
