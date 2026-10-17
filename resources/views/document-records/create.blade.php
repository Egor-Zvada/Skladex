@extends('layouts.app')
@section('title', 'Новый документ')
@push('styles')<link rel="stylesheet" href="{{ asset('static/document-records.css') }}?v={{ filemtime(public_path('static/document-records.css')) }}">@endpush
@push('scripts')<script src="{{ asset('static/document-records.js') }}?v={{ filemtime(public_path('static/document-records.js')) }}" defer></script>@endpush
@section('content')
<div class="page-header"><div><h1>Новый документ</h1><p class="muted">Выберите до 100 предметов. Система сохранит их реквизиты на момент создания.</p></div><a class="button button-secondary" href="{{ route('document-records.index') }}">Все документы</a></div>
<form method="get" class="panel actions" data-document-search>@foreach($selected as $uuid)<input type="hidden" name="asset_ids[]" value="{{ $uuid }}">@endforeach<label class="field">Найти оборудование<input type="search" name="q" value="{{ $query }}" maxlength="150" placeholder="Название, код или инвентарный номер"><small class="muted">Поиск сохраняет выбор предметов. Текст документа заполните после поиска.</small></label><button class="button button-secondary">Найти</button></form>
@if($truncated)<p class="notice">Показаны первые 500 предметов. Уточните поиск перед заполнением документа.</p>@endif
<form method="post" action="{{ route('document-records.store') }}" class="stack" data-document-create>
    @csrf<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
    <section class="panel stack"><div class="section-heading"><h2>Оборудование</h2><span class="muted" data-document-selection aria-live="polite">Выбрано: {{ count($selected) }} из 100</span></div>
        <div class="document-asset-selector">@forelse($assets as $asset)<label class="document-asset-option"><input type="checkbox" name="asset_ids[]" value="{{ $asset->uuid }}" @checked(in_array(strtolower($asset->uuid), $selected, true))><input type="hidden" name="expected_assets[{{ $asset->uuid }}]" value="{{ $asset->version }}"><span><strong>{{ $asset->name }}</strong><span class="document-asset-meta"><span class="mono">{{ $asset->code }}</span> · Инв. № {{ $asset->inventory_no ?: '—' }} · {{ $asset->location?->display_name ?? 'Кабинет не указан' }}@if($asset->status === 'written_off') · Списано@endif</span></span></label>@empty<p class="empty-state">Оборудование по запросу не найдено.</p>@endforelse</div>
    </section>
    <section class="panel stack"><div class="form-grid"><label class="field">Вид документа<select name="template_key" data-document-template>@foreach($templates as $template)<option value="{{ $template['key'] }}" data-title="{{ $template['name'] }}" @selected(old('template_key', 'transfer') === $template['key'])>{{ $template['name'] }}</option>@endforeach</select></label><label class="field">Дата<input type="date" name="document_date" value="{{ is_string(old('document_date')) ? old('document_date') : now()->timezone(config('skladex.timezone'))->format('Y-m-d') }}" required></label></div>
    @include('document-records.fields', ['record' => null])
    <p class="muted">Черновой шаблон. Официальные формы вашей организации можно будет подключить позже. Создание акта не перемещает и не списывает имущество.</p><div class="actions"><button class="button button-primary">Создать черновик</button><a class="button button-secondary" href="{{ route('document-records.index') }}">Отмена</a></div></section>
</form>
@endsection
