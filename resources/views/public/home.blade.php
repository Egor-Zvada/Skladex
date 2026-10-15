@extends('layouts.guest')
@section('title','Сканирование QR')
@section('content')
<section class="public-start stack"><div class="public-heading"><p class="eyebrow">Оборудование рядом</p><h1>Что находится<br>в кабинете?</h1><p class="muted">Сканируйте QR или введите код оборудования.</p></div>
<div class="scanner-placeholder-large" aria-hidden="true">@include('partials.icon',['name'=>'qr'])</div>
<form method="get" action="{{ route('public.resolve') }}" class="stack">@include('partials.qr-scanner',['target'=>'#lookup-code','autoSubmit'=>true])
<div class="divider-label">или</div><div class="actions"><label class="sr-only" for="lookup-code">Код или ссылка из QR</label><input id="lookup-code" name="code" value="{{ old('code') }}" placeholder="Код кабинета или вещи" maxlength="2048" required><button class="button button-secondary">Открыть</button></div></form><p class="muted">Просмотр доступен без входа</p></section>
@endsection
