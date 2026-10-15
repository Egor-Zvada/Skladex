@extends('layouts.app')
@section('title', 'Ключ лицензии')
@section('content')
<div class="page-header"><div><h1>Ключ {{ $license->code }}</h1><p class="muted">Открытие записано в журнал действий.</p></div><a class="button button-secondary" href="{{ route('licenses.show', $license) }}">Закрыть ключ</a></div>
<section class="panel"><label class="field">Ключ лицензии<textarea readonly rows="4" autocomplete="off" spellcheck="false">{{ $licenseKey }}</textarea></label><p class="muted">Закройте страницу после использования. Не передавайте ключ в комментариях, ссылках или общих документах.</p></section>
@endsection
