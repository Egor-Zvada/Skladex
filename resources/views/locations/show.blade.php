@extends('layouts.app')
@section('title', $location->display_name)
@section('content')
<div class="page-header"><div><p class="eyebrow"><a href="{{ route('locations.index') }}">Кабинеты</a>@if($location->building) / <a href="{{ route('buildings.show', $location->building) }}">{{ $location->building->name }}</a>@endif</p><h1>{{ $location->name }}</h1><p class="mono muted">{{ $location->code }}</p><p class="muted">Здание: {{ $location->building?->name ?? 'не назначено' }}</p></div>@if($location->is_public)<a href="{{ route('public.location', $location) }}" class="button button-secondary">Гостевой просмотр и QR</a>@endif</div>
<section class="panel table-wrap"><table class="table"><thead><tr><th>Оборудование</th><th>Код</th><th>Категория</th></tr></thead><tbody>
@forelse($assets as $asset)<tr><td><a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a></td><td class="mono">{{ $asset->code }}</td><td>{{ $asset->category?->name }}</td></tr>@empty<tr><td colspan="3" class="muted">Оборудование пока не добавлено</td></tr>@endforelse
</tbody></table></section>
{{ $assets->links() }}
@if(\App\Support\Access::allows(auth()->user(), 'write'))
<details class="panel"><summary>Изменить кабинет</summary><form method="post" action="{{ route('locations.update', $location) }}" class="stack">
    @csrf @method('PATCH')
    <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><input type="hidden" name="expected_version" value="{{ $location->version }}">
    <label class="field">Название<input name="name" value="{{ old('name', $location->name) }}" required maxlength="255"></label>
    <label class="field">Здание<select name="building_id"><option value="">Не назначено</option>@foreach($buildings as $building)<option value="{{ $building->id }}" @selected((string) old('building_id', $location->building_id) === (string) $building->id)>{{ $building->name }}</option>@endforeach</select></label>
    <p class="muted">При изменении здания код кабинета и его QR сохраняются.</p>
    <label class="field">Внутренняя заметка<textarea name="notes" maxlength="5000">{{ old('notes', $location->notes) }}</textarea></label>
    <label class="field">Гостевой просмотр<select name="is_public"><option value="1" @selected(old('is_public', (int) $location->is_public) == 1)>Включён</option><option value="0" @selected(old('is_public', (int) $location->is_public) == 0)>Выключен</option></select></label>
    <button class="button button-primary">Сохранить</button>
</form></details>
@endif
@endsection
