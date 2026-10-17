@extends('layouts.app')
@section('title', 'Рекомендуемые регламенты')
@push('scripts')<script src="{{ asset('static/maintenance-presets.js') }}?v={{ filemtime(public_path('static/maintenance-presets.js')) }}" defer></script>@endpush
@section('content')
@php
    $oldAssetUuids = old('asset_uuids', []);
    $oldAssetUuids = is_array($oldAssetUuids) ? array_values(array_filter($oldAssetUuids, 'is_string')) : [];
    $firstDueOn = is_string(old('first_due_on')) ? old('first_due_on') : '';
    $intervalCount = old('interval_count', $preset['interval_count']);
    $intervalCount = is_int($intervalCount) || is_string($intervalCount) ? $intervalCount : $preset['interval_count'];
@endphp
<div class="page-header"><div><h1>Рекомендуемые регламенты</h1><p class="muted">Начальные проверочные списки для вашего технического оборудования.</p></div><a class="button button-secondary" href="{{ route('maintenance.plans.index') }}">Все регламенты</a></div>
@include('operations.navigation')
<form method="get" class="panel form-grid">
    <label class="field">Вид оборудования<select name="preset_key">@foreach($catalog as $key => $item)<option value="{{ $key }}" @selected($selected === $key)>{{ $item['name'] }}</option>@endforeach</select></label>
    <label class="field">Название или код<input type="search" name="q" maxlength="200" value="{{ $q }}"></label>
    <div class="actions"><button class="button button-secondary">Показать оборудование</button></div>
</form>
<section class="panel stack section-spacing">
    <div><h2>{{ $preset['name'] }}</h2><p>{{ $preset['scope'] }}</p><p class="muted">Начальный интервал: {{ $preset['interval_count'] }} мес. Это рекомендация Skladex: уточните периодичность по руководству модели и условиям эксплуатации. Проверка также может требоваться перед выдачей или после события.</p></div>
    <ul>@foreach($preset['checklist_schema'] as $step)<li>{{ $step['label'] }}</li>@endforeach</ul>
    <p class="muted">Шаблон не запускает диагностику, обновление или отключение устройства. Фактическая работа и её результат оформляются отдельно. Чистку, вскрытие, нагрузочные испытания и работы на высоте планируют отдельной работой по руководству модели.</p>
    @if($preset['sources'])<details><summary>Справочные руководства производителей</summary><ul>@foreach($preset['sources'] as $label => $url)<li><a class="text-link" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $label }}</a></li>@endforeach</ul><p class="muted">Примеры общих подходов. Эти страницы не заменяют руководство именно вашего устройства.</p></details>@endif
</section>
<section class="panel section-spacing"><h2>Подходящее оборудование · {{ $assets->total() }}</h2>
    <p class="muted">Для предмета с активным регламентом новый не создаётся. Существующие настройки и выполненные работы сохраняются.</p>
    @if($assets->isEmpty())<p class="empty-state">Оборудование этой группы по выбранным условиям не найдено.</p>@else
    <form method="post" action="{{ route('maintenance.presets.apply') }}" class="stack" data-preset-selection>
        @csrf @include('operations.command-key')
        <input type="hidden" name="preset_key" value="{{ $selected }}">
        @if(\App\Support\Access::allows(auth()->user(), 'write'))<div class="actions"><label class="checkbox-field"><input type="checkbox" data-preset-select-all>Выбрать доступные на странице</label><span class="muted" aria-live="polite" data-preset-count>Выбрано: 0</span></div>@endif
        <div class="table-wrap"><table class="table"><thead><tr><th>Выбрать</th><th>Оборудование</th><th>Кабинет</th><th>Регламент</th></tr></thead><tbody>
        @foreach($assets as $asset)
        @php($hasPlan = in_array($asset->id, $existing, true))
        <tr><td>@if(!$hasPlan && \App\Support\Access::allows(auth()->user(), 'write'))<label class="selection-control"><input type="checkbox" name="asset_uuids[]" value="{{ $asset->uuid }}" @checked(in_array($asset->uuid, $oldAssetUuids, true))><span class="sr-only">Выбрать {{ $asset->name }} · {{ $asset->code }}</span></label><input type="hidden" name="asset_versions[{{ $asset->uuid }}]" value="{{ $asset->version }}">@else<span class="muted">—</span>@endif</td><td><a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a><div class="mono muted">{{ $asset->code }}</div></td><td>{{ $asset->location?->display_name ?? '—' }}</td><td>{{ $hasPlan ? 'Уже настроен' : 'Можно добавить' }}</td></tr>
        @endforeach
        </tbody></table></div>
        @if(\App\Support\Access::allows(auth()->user(), 'write'))
        <div class="form-grid"><label class="field">Первый срок<input type="date" name="first_due_on" value="{{ $firstDueOn }}"><span class="muted">Можно оставить пустым и назначить в карточке регламента. До назначения срока напоминаний и работ по нему не будет.</span></label><label class="field">Повторять каждые<input type="number" name="interval_count" value="{{ $intervalCount }}" min="1" max="120" required></label><label class="field">Единица интервала<select name="interval_unit">@foreach(['day'=>'Дни', 'week'=>'Недели', 'month'=>'Месяцы', 'year'=>'Годы'] as $value => $label)<option value="{{ $value }}" @selected(old('interval_unit', $preset['interval_unit']) === $value)>{{ $label }}</option>@endforeach</select></label></div>
        <p class="muted">Новый срок считается после принятого выполнения работы. Добавление регламента не означает, что осмотр уже проведён, и не меняет оценку состояния оборудования.</p>
        <div><button class="button button-primary" data-preset-submit>Добавить регламенты выбранному оборудованию</button></div>
        @endif
    </form>{{ $assets->links() }}@endif
</section>
@endsection
