@extends('layouts.app')
@section('title', 'История')
@section('content')
<div class="page-header"><div><h1>История</h1><p class="muted">Действия в Skladex и сохранённый журнал прежней системы. Записи доступны только для чтения.</p></div></div>
<form method="get" action="{{ route('history.index') }}" class="panel form-grid">
@if($asset)<input type="hidden" name="asset" value="{{ $asset->uuid }}">@endif
<label class="field">Источник<select name="source"><option value="">Все события</option><option value="legacy" @selected($source === 'legacy')>Прежняя система</option><option value="current" @selected($source === 'current')>Новая Skladex</option></select></label>
<label class="field">Событие, тип объекта, UUID или текущий ID<input type="search" name="q" maxlength="150" value="{{ $q }}" placeholder="Например, legacy.equipment.update"></label>
<div class="actions"><button class="button button-secondary">Показать</button><a href="{{ route('history.index') }}" class="button button-secondary">Сбросить</a></div></form>
@if($asset)<section class="panel"><p>История предмета: @if(!$asset->archived_at)<a href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>@else{{ $asset->name }} <span class="badge">В архиве</span>@endif · <code>{{ $asset->code }}</code></p></section>@endif
<section class="panel"><p class="muted">Найдено событий: {{ $entries->total() }}. Поиск не просматривает содержимое снимков. У старого журнала авторы не сохранялись; время импорта не подменяет исторический факт.</p>
@forelse($rows as $row)
<article class="history-row stack"><div class="actions"><span class="badge">{{ $row['legacy'] ? 'Прежняя система' : 'Новая Skladex' }}</span><time>{{ $row['created_at']?->timezone(config('skladex.timezone'))->format('d.m.Y H:i:s') ?? 'Дата неизвестна' }}</time><span class="muted">#{{ $row['id'] }}</span></div><h2>{{ $row['title'] }}</h2><p><code>{{ $row['event'] }}</code></p>
<p>@if($row['asset'])@if(!$row['asset']->archived_at)<a href="{{ route('assets.show', $row['asset']) }}">{{ $row['asset']->name }}</a>@else{{ $row['asset']->name }} <span class="badge">В архиве</span>@endif · <code>{{ $row['asset']->code }}</code>@elseif($row['missing_asset'])<strong>Предмет отсутствует в текущем реестре</strong>@else{{ $row['subject_type'] }}@if($row['subject_id']) · ID {{ $row['subject_id'] }}@endif @endif</p>
<p class="muted">Автор: {{ $row['legacy'] ? 'в исходном журнале не указан' : ($row['actor'] ?? 'не указан') }}@if($row['legacy_entity_id'] !== null) · Исходный ID объекта: {{ $row['legacy_entity_id'] }}@endif @if($row['legacy_audit_id'] !== null) · Исходное событие: {{ $row['legacy_audit_id'] }}@endif @if($row['source_system']) · Источник: {{ $row['source_system'] }}@endif</p>
<details><summary>{{ $row['legacy'] ? 'Сохранённый снимок исходных данных' : 'Сведения о событии' }}</summary><pre class="preserve-lines" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $row['details'] }}</pre></details></article>@if(!$loop->last)<hr>@endif
@empty<p class="empty-state">Событий по выбранным условиям пока нет.</p>@endforelse
{{ $entries->links() }}</section>
@endsection
