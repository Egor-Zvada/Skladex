@extends('layouts.guest')
@section('title',$item['name'])
@section('content')
<section class="public-card stack"><p class="eyebrow">Гостевой просмотр</p><div><h1>{{ $item['name'] }}</h1><p class="mono muted">{{ $item['code'] }}</p></div>
<div class="panel stack"><div><span class="muted">Категория</span><p>{{ $item['category'] }}</p></div><div><span class="muted">Кабинет</span><p>@if($item['location'])<a href="{{ route('public.location',$item['location']['id']) }}">{{ $item['location']['name'] }}</a>@else — @endif</p></div><p class="badge">{{ \App\Support\Labels::status($item['status']) }}</p></div>
<img class="public-qr" src="{{ route('public.asset.qr',$item['id']) }}" width="180" height="180" alt="QR оборудования">
<a class="button button-primary" href="{{ route('public.home') }}">Сканировать другой QR</a><p class="muted">Данные только для просмотра</p></section>
@endsection
