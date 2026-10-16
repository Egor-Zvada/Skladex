@extends('layouts.app')
@section('title', 'Кабинеты')
@section('content')
<div class="page-header"><div><h1>Кабинеты</h1><p class="muted">Помещения для размещения оборудования · {{ $items->total() }}</p></div><a class="button button-secondary" href="{{ route('buildings.index') }}">Здания</a></div>
<form method="get" class="panel filter-bar actions">
    <label for="room-building-filter">Здание</label>
    <select id="room-building-filter" name="building_id">
        <option value="">Все здания</option>
        <option value="unassigned" @selected($buildingId === 'unassigned')>Без здания</option>
        @foreach($buildings as $building)<option value="{{ $building->id }}" @selected((string) $buildingId === (string) $building->id)>{{ $building->name }}</option>@endforeach
    </select>
    <button class="button button-secondary">Применить</button>
</form>
<div class="panel table-wrap"><table class="table"><thead><tr><th>Кабинет</th><th>Здание</th><th>Код</th><th>Оборудование</th><th>Гостевой просмотр</th></tr></thead><tbody>
@forelse($items as $location)
    <tr><td><a href="{{ route('locations.show', $location) }}">{{ $location->name }}</a></td><td>@if($location->building)<a href="{{ route('buildings.show', $location->building) }}">{{ $location->building->name }}</a>@else<span class="muted">Не назначено</span>@endif</td><td class="mono">{{ $location->code }}</td><td>{{ $location->assets_count }}</td><td>{{ $location->is_public ? 'Доступен' : 'Выключен' }}</td></tr>
@empty<tr><td colspan="5"><div class="empty-state">Кабинетов по выбранному фильтру нет.</div></td></tr>@endforelse
</tbody></table></div>
{{ $items->links() }}
@if(\App\Support\Access::allows(auth()->user(), 'write'))
<section class="panel stack"><h2>Новый кабинет</h2><form method="post" action="{{ route('locations.store') }}" class="stack">
    @csrf<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
    <div class="form-grid">
        <label class="field">Название<input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Кабинет 214"></label>
        <label class="field">Здание<select name="building_id"><option value="">Не назначено</option>@foreach($buildings as $building)<option value="{{ $building->id }}" @selected((string) old('building_id', $buildingId === 'unassigned' ? '' : $buildingId) === (string) $building->id)>{{ $building->name }}</option>@endforeach</select></label>
        <label class="field">Внутренняя заметка<input name="notes" value="{{ old('notes') }}" maxlength="5000"></label>
    </div>
    <p class="muted">Здание можно назначить позже. Названия кабинетов в разных зданиях могут совпадать.</p>
    <input type="hidden" name="is_public" value="1"><button class="button button-primary">Создать кабинет</button>
</form></section>
@endif
@endsection
