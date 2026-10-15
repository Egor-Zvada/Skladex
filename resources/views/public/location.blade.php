@extends('layouts.guest')
@section('title',$item['name'])
@section('content')
<section class="public-card stack"><p class="eyebrow">Гостевой просмотр</p><div><h1>{{ $item['name'] }}</h1><p class="muted">{{ $assets->total() }} объектов · <span class="mono">{{ $item['code'] }}</span></p></div>
<div class="public-list">@forelse($assets as $asset)<a class="public-list-row" href="{{ route('public.asset',$asset) }}"><div>@include('partials.icon',['name'=>'monitor'])</div><div><strong>{{ $asset->name }}</strong><p class="muted"><span class="mono">{{ $asset->code }}</span> · {{ $asset->category?->name }}</p></div><span aria-hidden="true">›</span></a>@empty<div class="empty-state">В этом кабинете пока нет учтённого оборудования.</div>@endforelse</div>{{ $assets->links() }}
<a class="button button-primary" href="{{ route('public.home') }}">Сканировать QR</a><p class="muted">Данные только для просмотра</p></section>
@endsection
