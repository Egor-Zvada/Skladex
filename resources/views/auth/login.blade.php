@extends('layouts.guest')
@section('title','Вход')
@section('content')
<section class="auth-card panel stack"><div><h1>Войти в Skladex</h1><p class="muted">Рабочее пространство ИТ-службы</p></div>
<form method="post" action="{{ route('login.store') }}" class="stack">@csrf
<label class="field">Логин или электронная почта<input type="text" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required maxlength="255" autocomplete="username" autocapitalize="off" spellcheck="false" autofocus></label>
<label class="field">Пароль<input type="password" name="password" required autocomplete="current-password"></label>
@if($directoryEnabled ?? false)<p class="muted">Можно войти доменным логином VVE или доменной почтой, если вам предоставлен доступ.</p>@endif
<button class="button button-primary" type="submit">Войти</button></form>
<a href="{{ route('public.home') }}" class="muted">Сканировать QR без входа</a></section>
@endsection
