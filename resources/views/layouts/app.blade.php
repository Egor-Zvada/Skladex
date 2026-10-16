<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FAFAFA">
    <title>{{ trim($__env->yieldContent('title', 'Рабочее пространство')) }} · Skladex</title>
    <link rel="icon" type="image/png" href="{{ asset('static/link.png') }}">
    <link rel="preload" href="{{ asset('static/fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('static/skladex.css') }}?v={{ filemtime(public_path('static/skladex.css')) }}">
    @stack('styles')
    <script id="skladex-script" src="{{ asset('static/skladex.js') }}?v={{ filemtime(public_path('static/skladex.js')) }}" data-qr-library="{{ asset('static/qr-scanner.min.js') }}" defer></script>
    @stack('scripts')
</head>
<body class="app-body">
    <a class="skip-link" href="#main-content">Перейти к содержимому</a>
    @php
        $currentUser = auth()->user();
        $roleNames = ['admin' => 'Администратор', 'operator' => 'Специалист', 'viewer' => 'Наблюдатель'];
        $currentRole = $roleNames[$currentUser?->role ?? ''] ?? 'Сотрудник';
        $homeUrl = \Illuminate\Support\Facades\Route::has('dashboard') ? route('dashboard') : url('/');
        $navigation = [
            ['dashboard', 'Обзор', 'dashboard', 'dashboard'],
            ['assets.index', 'Имущество', 'assets', 'assets.*'],
            ['locations.index', 'Кабинеты', 'locations', 'locations.*'],
            ['inventory.index', 'Инвентаризация', 'inventory', 'inventory.*'],
            ['maintenance.index', 'Обслуживание', 'maintenance', 'maintenance.*'],
            ['software.index', 'ПО и сервисы', 'software', 'software.*'],
            ['licenses.index', 'Лицензии', 'licenses', 'licenses.*'],
            ['labels.index', 'Печать', 'labels', 'labels.*'],
        ];
    @endphp
    <div class="app-shell">
        <aside class="app-sidebar" id="app-sidebar" aria-label="Рабочее пространство" tabindex="-1">
            <div class="sidebar-brand-row">
                <a class="brand" href="{{ $homeUrl }}" aria-label="Skladex — обзор">
                    <img class="brand-symbol" src="{{ asset('static/link.png') }}" width="34" height="34" alt="">
                    <span class="brand-wordmark">skladex</span>
                </a>
                <button type="button" class="icon-button sidebar-close" data-nav-close aria-label="Закрыть меню">@include('partials.icon', ['name' => 'close'])</button>
            </div>
            <p class="sidebar-caption">Рабочее пространство</p>
            <nav class="sidebar-nav" aria-label="Основная навигация">
                @foreach ($navigation as [$routeName, $label, $iconName, $activePattern])
                    @if (\Illuminate\Support\Facades\Route::has($routeName))
                        <a class="nav-link {{ request()->routeIs($activePattern) ? 'is-active' : '' }}" href="{{ route($routeName) }}" @if(request()->routeIs($activePattern)) aria-current="page" @endif>
                            @include('partials.icon', ['name' => $iconName])<span>{{ $label }}</span>
                        </a>
                    @else
                        <span class="nav-link is-unavailable" aria-disabled="true" title="Раздел пока недоступен">@include('partials.icon', ['name' => $iconName])<span>{{ $label }}</span></span>
                    @endif
                @endforeach
            </nav>
            <div class="sidebar-bottom">
                @if ($currentUser && \Illuminate\Support\Facades\Route::has('settings.index'))
                    <a class="nav-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}" href="{{ route('settings.index') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif>@include('partials.icon', ['name' => 'settings'])<span>{{ $currentUser->role === 'admin' ? 'Настройки' : 'Профиль' }}</span></a>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('public.home'))
                    <a class="nav-link" href="{{ route('public.home') }}">@include('partials.icon', ['name' => 'qr'])<span>Сканировать QR</span></a>
                @endif
                <div class="profile-row">
                    <span class="profile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($currentUser?->name ?? 'С'), 0, 1)) }}</span>
                    <div class="profile-copy"><span class="profile-name">{{ $currentUser?->name ?? 'Сотрудник' }}</span><span class="profile-role">{{ $currentRole }}</span></div>
                    @include('partials.theme-toggle')
                </div>
                @if (\Illuminate\Support\Facades\Route::has('logout'))
                    <form action="{{ route('logout') }}" method="post" class="logout-form">@csrf<button type="submit" class="logout-button">@include('partials.icon', ['name' => 'logout'])<span>Выйти</span></button></form>
                @endif
            </div>
        </aside>
        <div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
        <div class="app-workspace" id="app-workspace">
            <header class="workspace-toolbar">
                <button type="button" class="icon-button mobile-menu-toggle" data-nav-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Открыть меню">@include('partials.icon', ['name' => 'menu'])</button>
                <form class="global-search" method="get" action="{{ \Illuminate\Support\Facades\Route::has('assets.index') ? route('assets.index') : url('/') }}" role="search">
                    <label class="sr-only" for="global-search-input">Поиск имущества по названию, коду или номеру</label>
                    @include('partials.icon', ['name' => 'search'])
                    <input type="search" id="global-search-input" name="q" value="{{ request()->routeIs('assets.index') && is_string(request('q')) ? request('q') : '' }}" placeholder="Поиск имущества, кодов и номеров" autocomplete="off" maxlength="200" @disabled(!\Illuminate\Support\Facades\Route::has('assets.index'))>
                    <button type="submit" class="search-submit" aria-label="Найти имущество" @disabled(!\Illuminate\Support\Facades\Route::has('assets.index'))><kbd aria-hidden="true">Ctrl K</kbd>@include('partials.icon', ['name' => 'arrow-right', 'class' => 'search-mobile-icon'])</button>
                </form>
            </header>
            <main class="workspace-content" id="main-content" tabindex="-1">
                @include('partials.feedback')
                @hasSection('header')<div class="page-heading">@yield('header')</div>@endif
                @yield('content')
            </main>
            <footer class="workspace-footer"><span class="mono">skladex</span><span>ИТ и аудиооборудование</span></footer>
        </div>
    </div>
</body>
</html>
