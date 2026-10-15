<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FAFAFA">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ trim($__env->yieldContent('title', 'Оборудование рядом')) }} · Skladex</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/link.png') }}">
    <link rel="preload" href="{{ asset('assets/fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/skladex.css') }}?v={{ filemtime(public_path('assets/skladex.css')) }}">
    @stack('styles')
    <script id="skladex-script" src="{{ asset('assets/skladex.js') }}?v={{ filemtime(public_path('assets/skladex.js')) }}" data-qr-library="{{ asset('assets/qr-scanner.min.js') }}" defer></script>
    @stack('scripts')
</head>
<body class="guest-body">
    <a class="skip-link" href="#main-content">Перейти к содержимому</a>
    <div class="guest-shell">
        <header class="guest-header">
            <a class="brand" href="{{ \Illuminate\Support\Facades\Route::has('public.home') ? route('public.home') : url('/') }}" aria-label="Skladex — главная">
                <img class="brand-symbol" src="{{ asset('assets/link.png') }}" width="34" height="34" alt=""><span class="brand-wordmark">skladex</span>
            </a>
            <div class="actions guest-header-actions">
                @include('partials.theme-toggle')
                @auth
                    @if (\Illuminate\Support\Facades\Route::has('dashboard'))<a class="button button-secondary button-small" href="{{ route('dashboard') }}">Рабочее пространство</a>@endif
                @else
                    @if (!request()->routeIs('login', 'login.store') && \Illuminate\Support\Facades\Route::has('login'))<a class="button button-secondary button-small" href="{{ route('login') }}">Войти</a>@endif
                @endauth
            </div>
        </header>
        <main class="guest-content" id="main-content" tabindex="-1">
            @include('partials.feedback')
            @hasSection('header')<div class="page-heading">@yield('header')</div>@endif
            @yield('content')
        </main>
        <footer class="guest-footer"><span>Учёт и обслуживание оборудования</span><span class="mono">skladex</span></footer>
    </div>
</body>
</html>
