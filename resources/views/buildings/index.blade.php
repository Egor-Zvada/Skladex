@extends('layouts.app')
@section('title', 'Здания')
@section('content')
<div class="page-header"><div><h1>Здания</h1><p class="muted">Корпуса и площадки, в которых находятся кабинеты</p></div><a class="button button-secondary" href="{{ route('locations.index') }}">Все кабинеты</a></div>
<div class="panel table-wrap"><table class="table"><thead><tr><th>Здание</th><th>Адрес</th><th>Кабинеты</th></tr></thead><tbody>
@forelse($items as $building)<tr><td><a href="{{ route('buildings.show', $building) }}">{{ $building->name }}</a></td><td>{{ $building->address ?: '—' }}</td><td>{{ $building->locations_count }}</td></tr>@empty<tr><td colspan="3"><div class="empty-state">Добавьте здания, затем назначьте их существующим кабинетам.</div></td></tr>@endforelse
</tbody></table></div>
{{ $items->links() }}
@if(\App\Support\Access::allows(auth()->user(), 'write'))
<section class="panel stack"><h2>Новое здание</h2><form method="post" action="{{ route('buildings.store') }}" class="stack">
    @csrf<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
    <div class="form-grid"><label class="field">Название<input name="name" value="{{ old('name') }}" maxlength="255" required placeholder="Название корпуса или площадки"></label><label class="field">Адрес<input name="address" value="{{ old('address') }}" maxlength="255"></label></div>
    <label class="field">Внутренняя заметка<textarea name="notes" maxlength="5000">{{ old('notes') }}</textarea></label>
    <p class="muted">Кабинеты без здания останутся на месте. Их можно распределить по зданиям вручную.</p>
    <button class="button button-primary">Создать здание</button>
</form></section>
@endif
@endsection
