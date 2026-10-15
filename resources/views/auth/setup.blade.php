@extends('layouts.guest')
@section('title','Первый запуск')
@section('content')
<section class="auth-card panel stack"><div><h1>Добро пожаловать в Skladex</h1><p class="muted">Создайте первого администратора. База оборудования пока пуста.</p></div>
<form method="post" action="{{ route('setup.store') }}" class="stack">@csrf
<label class="field">Ключ первичной настройки<input type="password" name="setup_token" data-setup-token required minlength="64" maxlength="64" autocomplete="off" autocapitalize="off" spellcheck="false"></label>
<label class="field">Имя<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>
<label class="field">Электронная почта<input type="email" name="email" required autocomplete="username" value="{{ old('email') }}"></label>
<label class="field">Пароль, от 12 символов<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<label class="field">Повторите пароль<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
<button class="button button-primary" type="submit">Создать рабочее пространство</button></form></section>
@endsection
