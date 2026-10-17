@extends('layouts.app')
@section('title', 'Документы')
@section('content')
<div class="page-header"><div><h1>Документы</h1><p class="muted">Акты, перечни и служебные записки по выбранному оборудованию.</p></div>@if(\App\Support\Access::allows(auth()->user(), 'write'))<a class="button button-primary" href="{{ route('document-records.create') }}">Создать документ</a>@endif</div>
<p class="notice">Используются черновые шаблоны. После получения утверждённых форм их можно заменить; уже созданные документы сохранят свою редакцию.</p>
<form method="get" class="panel form-grid">
    <label class="field">Название<input type="search" name="q" maxlength="150" value="{{ request('q') }}"></label>
    <label class="field">Вид документа<select name="template_key"><option value="">Все виды</option>@foreach($templates as $template)<option value="{{ $template['key'] }}" @selected(request('template_key') === $template['key'])>{{ $template['name'] }}</option>@endforeach</select></label>
    <label class="field">Редакция<select name="status"><option value="">Все</option><option value="draft" @selected(request('status') === 'draft')>Черновик</option><option value="final" @selected(request('status') === 'final')>Зафиксирована</option></select></label><div class="actions"><button class="button button-secondary">Найти</button><a href="{{ route('document-records.index') }}">Сбросить</a></div>
</form>
<div class="panel table-wrap"><table class="table"><thead><tr><th>Документ</th><th>Дата</th><th>Вид</th><th>Оборудование</th><th>Редакция</th></tr></thead><tbody>
@forelse($items as $item)<tr><td><a href="{{ route('document-records.show', $item) }}">{{ $item->title }}</a><div class="muted mono">{{ $item->number }}</div></td><td>{{ $item->document_date->format('d.m.Y') }}</td><td>{{ $item->template_snapshot['name'] }}</td><td>{{ $item->items_count }}</td><td>{{ $item->status === 'final' ? 'Зафиксирована' : 'Черновик' }}</td></tr>@empty<tr><td colspan="5"><div class="empty-state">Документов пока нет. Выберите оборудование и опишите основание или результат осмотра.</div></td></tr>@endforelse
</tbody></table></div>
{{ $items->links() }}
@endsection
