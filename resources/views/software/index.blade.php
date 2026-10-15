@extends('layouts.app')
@section('title', 'Программы и сервисы')
@section('content')
<div class="page-header"><div><h1>Программы и сервисы</h1><p class="muted">Каталог ПО, версий и фактических установок.</p></div><a class="button button-secondary" href="{{ route('licenses.index') }}">Лицензии</a></div>
<form method="get" class="panel actions"><label class="field">Поиск<input name="q" value="{{ $q }}" placeholder="Название или производитель"></label><button class="button button-secondary">Найти</button></form>
<section class="panel">
@if($products->isEmpty())<p class="empty-state">Программ пока нет. Добавьте первую карточку ниже.</p>@else
<div class="table-wrap"><table class="table"><thead><tr><th>Программа</th><th>Тип</th><th>Ответственный</th><th>Установки</th><th>Лицензии</th></tr></thead><tbody>
@foreach($products as $product)<tr><td><a href="{{ route('software.show', $product) }}">{{ $product->name }}</a><div class="muted">{{ $product->vendor }}</div></td><td>{{ ['third_party'=>'Стороннее ПО','in_house'=>'Собственная разработка','firmware'=>'Прошивка'][$product->kind] }}</td><td>{{ $product->owner?->name ?? 'Не указан' }}</td><td>{{ $product->installations_count }}</td><td>{{ $product->licenses_count }}</td></tr>@endforeach
</tbody></table></div>{{ $products->links() }}@endif
</section>
@if(\App\Support\Access::allows(auth()->user(), 'write'))
<section class="panel"><h2>Добавить программу</h2><form method="post" action="{{ route('software.store') }}" class="stack">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@include('software.product-fields', ['product' => null])<div><button class="button button-primary">Создать карточку</button></div></form></section>
@endif
@endsection
