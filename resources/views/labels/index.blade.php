@extends('layouts.app')
@section('title','Печать этикеток')
@push('styles')<link rel="stylesheet" href="{{ asset('static/label-editor.css') }}?v={{ filemtime(public_path('static/label-editor.css')) }}">@endpush
@push('scripts')<script src="{{ asset('static/label-editor.js') }}?v={{ filemtime(public_path('static/label-editor.js')) }}" defer></script>@endpush
@section('content')
@php
    $startValue = old('start_cell', 1);
    $nameValue = old('name', $profile?->name);
    $editorConfig = ['defaults'=>app(\App\Services\LabelService::class)->defaults(),'profiles'=>$profiles->map(fn($p)=>['id'=>$p->id,'name'=>$p->name,'version'=>$p->version,'geometry'=>$p->only(\App\Services\LabelService::GEOMETRY_FIELDS)])->values()];
@endphp
<div class="page-header"><div><p class="eyebrow">QR · Листы и этикетки</p><h1>Печать этикеток</h1><p class="muted">Настройте лист, выберите имущество — результат сразу появится справа.</p></div></div>
<form method="post" action="{{ route('labels.pdf') }}" class="label-editor" data-label-editor data-preview-url="{{ route('labels.preview') }}">
    @csrf
    <input type="hidden" name="target_type" value="{{ $targetType }}">
    <input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
    <input type="hidden" name="profile_id" value="{{ $profile?->id }}">
    <input type="hidden" name="expected_version" value="{{ $profile?->version }}">
    <section class="panel stack le-settings">
        <div class="panel-header"><h2>Лист и принтер</h2><span class="badge" data-profile-version>{{ $profile?'v'.$profile->version:'Новый' }}</span></div>
        <label class="field">Сохранённый профиль<select data-label-profile><option value="">Новый · пример 64 × 33 мм</option>@foreach($profiles as $saved)<option value="{{ $saved->id }}" @selected((string)($profile?->id)===(string)$saved->id)>{{ $saved->name }}</option>@endforeach</select></label>
        @include('labels.geometry')
        <label class="field">Начальная ячейка<input name="start_cell" type="number" min="1" max="500" step="1" value="{{ is_scalar($startValue)?$startValue:1 }}" required><span class="field-help">Нажмите ячейку на первом листе. Пропуск действует только на нём.</span></label>
        @if(\App\Support\Access::allows(auth()->user(),'write'))
            <label class="field">Название профиля<input name="name" value="{{ is_scalar($nameValue)?$nameValue:'' }}" maxlength="100" placeholder="Например: принтер ИТ-службы"></label>
            <button class="button button-secondary" type="submit" formaction="{{ route('labels.store') }}" data-geometry-action>Сохранить профиль</button>
        @endif
    </section>
    <section class="panel le-preview" aria-labelledby="le-preview-title">
        <div class="panel-header"><div><h2 id="le-preview-title">Предпросмотр листа</h2><p class="muted" data-sheet-summary>Размеры в миллиметрах</p></div><span class="badge" data-label-count>Выбрано: 0</span></div>
        <div class="le-toolbar">
            <label class="field">Двигать<select data-drag-mode><option value="margin">Сетку · поля листа</option><option value="offset">Поправку принтера</option></select></label>
            <label class="field">Масштаб экрана<select data-preview-zoom><option value="fit">По ширине</option><option value="0.75">75%</option><option value="1">100%</option><option value="1.25">125%</option><option value="1.5">150%</option></select></label>
            <div class="le-pager"><button type="button" class="button button-secondary button-sm" data-page-prev aria-label="Предыдущий лист">←</button><span data-page-label>Лист 1 / 1</span><button type="button" class="button button-secondary button-sm" data-page-next aria-label="Следующий лист">→</button></div>
        </div>
        <p id="le-drag-help" class="field-help">Перетаскивайте любую этикетку — двигается вся сетка. С фокусом на сетке: стрелки 0,1 мм, Shift + стрелки 1 мм. Размеры и зазоры сохраняются.</p>
        <div class="le-viewport" data-label-viewport><div class="le-sheet" data-label-sheet><div class="le-grid" data-label-grid tabindex="0" role="group" aria-label="Сетка этикеток" aria-describedby="le-drag-help"></div></div></div>
        <p class="le-status" data-preview-status role="status" aria-live="polite">Подготавливаем лист…</p>
        <p class="le-warning" data-qr-warning hidden>QR меньше 20 мм: проверьте читаемость камерой до печати всей пачки.</p>
        <div class="le-print-note"><strong>Печать PDF: 100% / «Фактический размер».</strong> Выберите тот же размер бумаги в драйвере, отключите подгонку. Экранный масштаб влияет только на предпросмотр.</div>
        <div class="actions le-print-actions"><button type="submit" class="button button-primary" data-label-pdf>Скачать PDF</button><button class="button button-secondary" type="submit" formaction="{{ route('labels.test') }}" formnovalidate data-geometry-action>Тестовый лист</button><button type="button" class="button button-ghost" data-offset-reset>Обнулить поправку</button></div>
        <p class="field-help">На тестовом листе контрольные отрезки равны 100 мм. Для стороны короче 120 мм отрезок по этой оси не печатается.</p>
        <noscript><p class="alert">Для живого предпросмотра включите JavaScript. Размеры и PDF всё равно проверяются сервером.</p></noscript>
    </section>
    <section class="panel stack le-targets">
        <div class="panel-header"><div><h2>{{ $targetType==='asset'?'Имущество':'Кабинеты' }} для печати</h2><p class="muted">До 500 объектов и 50 листов. Порядок — как в списке.</p></div><a class="button button-secondary button-sm" href="{{ route('labels.index',['target_type'=>$targetType==='asset'?'location':'asset']) }}">{{ $targetType==='asset'?'Выбрать кабинеты':'Выбрать имущество' }}</a></div>
        <div class="le-search"><label class="field">Фильтр показанного списка<input type="search" data-target-filter placeholder="Название или код"></label><a class="button button-secondary button-sm" href="{{ route('labels.index',['target_type'=>$targetType]) }}">Сбросить поиск</a></div>
        @if($query!=='')<p class="field-help">Серверный поиск: «{{ $query }}».</p>@endif
        @if($truncated)<p class="alert" role="status">Показаны первые 500 объектов. Для остальных воспользуйтесь поиском ниже; при загрузке другой выборки текущие отметки сбросятся.</p>@endif
        @if($targets->isNotEmpty())
        <div class="le-target-table"><table class="table"><thead><tr><th scope="col"><label class="selection-control"><input type="checkbox" data-label-select-all><span class="sr-only">Выбрать все видимые объекты</span></label></th><th scope="col">Название</th><th scope="col">Код</th></tr></thead><tbody>
        @foreach($targets as $target)<tr data-target-row><td><label class="selection-control"><input type="checkbox" name="target_ids[]" value="{{ $target->uuid }}" data-target-name="{{ $target->name }}" data-target-code="{{ $target->code }}" @checked(in_array($target->uuid,(array)old('target_ids',[]),true))><span class="sr-only">Выбрать {{ $target->name }}</span></label></td><td>{{ $target->name }}</td><td class="mono">{{ $target->code }}</td></tr>@endforeach
        </tbody></table></div>
        @else<div class="empty-state">@include('partials.icon',['name'=>'labels'])<h3>Пока нечего печатать</h3><p>{{ $query!==''?'По запросу ничего не найдено. Измените поиск.':'Добавьте имущество или кабинеты в реестр.' }}</p></div>@endif
    </section>
</form>
<form method="get" action="{{ route('labels.index') }}" class="panel filters le-server-search"><input type="hidden" name="target_type" value="{{ $targetType }}"><label class="field">Поиск по всему реестру<input type="search" name="q" value="{{ $query }}" placeholder="Название или код" maxlength="150"></label><button class="button button-secondary" type="submit">Загрузить выборку</button></form>
<script type="application/json" id="label-editor-config">@json($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@endsection
