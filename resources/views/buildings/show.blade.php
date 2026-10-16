@extends('layouts.app')
@section('title', $building->name)
@section('content')
<div class="page-header"><div><p class="eyebrow"><a href="{{ route('buildings.index') }}">Здания</a></p><h1>{{ $building->name }}</h1>@if($building->address)<p class="muted">{{ $building->address }}</p>@endif</div><a class="button button-secondary" href="{{ route('locations.index', ['building_id' => $building->id]) }}">Кабинеты здания</a></div>
<section class="panel table-wrap"><table class="table"><thead><tr><th>Кабинет</th><th>Код</th><th>Оборудование</th></tr></thead><tbody>
@forelse($locations as $location)<tr><td><a href="{{ route('locations.show', $location) }}">{{ $location->name }}</a></td><td class="mono">{{ $location->code }}</td><td>{{ $location->assets_count }}</td></tr>@empty<tr><td colspan="3"><div class="empty-state">К этому зданию ещё не привязаны кабинеты.</div></td></tr>@endforelse
</tbody></table></section>
{{ $locations->links() }}
@if(\App\Support\Access::allows(auth()->user(), 'write'))
<details class="panel"><summary>Изменить здание</summary><form method="post" action="{{ route('buildings.update', $building) }}" class="stack">
    @csrf @method('PATCH')<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><input type="hidden" name="expected_version" value="{{ $building->version }}">
    <label class="field">Название<input name="name" value="{{ old('name', $building->name) }}" required maxlength="255"></label>
    <label class="field">Адрес<input name="address" value="{{ old('address', $building->address) }}" maxlength="255"></label>
    <label class="field">Внутренняя заметка<textarea name="notes" maxlength="5000">{{ old('notes', $building->notes) }}</textarea></label>
    <button class="button button-primary">Сохранить</button>
</form></details>
<details class="panel"><summary>Архивировать здание</summary><form method="post" action="{{ route('buildings.archive', $building) }}" class="stack">
    @csrf<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><input type="hidden" name="expected_version" value="{{ $building->version }}">
    <p class="muted">Сначала перенесите кабинеты в другое здание или снимите их привязку. История останется в системе.</p>
    <button class="button button-secondary">Архивировать пустое здание</button>
</form></details>
@endif
@endsection
