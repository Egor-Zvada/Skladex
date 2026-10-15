@extends('layouts.guest')
@section('title','Вход')
@section('content')
<section class="auth-card panel stack"><div><h1>Войти в Skladex</h1><p class="muted">Рабочее пространство ИТ-службы</p></div>
<form method="post" action="{{ route('login.store') }}" class="stack">@csrf
<label class="field">Электронная почта<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus></label>
<label class="field">Пароль<input type="password" name="password" required autocomplete="current-password"></label>
<button class="button button-primary" type="submit">Войти</button></form>
<a href="{{ route('public.home') }}" class="muted">Сканировать QR без входа</a></section>
@endsection
