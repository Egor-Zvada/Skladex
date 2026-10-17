@extends('layouts.app')
@section('title', $record->title)
@push('styles')<link rel="stylesheet" href="{{ asset('static/document-records.css') }}?v={{ filemtime(public_path('static/document-records.css')) }}">@endpush
@section('content')
<div class="page-header"><div><p class="eyebrow">{{ $record->number }} · {{ $record->status === 'final' ? 'Редакция зафиксирована' : 'Черновик' }}</p><h1>{{ $record->title }}</h1><p class="muted">{{ $record->document_date->format('d.m.Y') }} · Шаблон {{ $record->template_version }} · Редакция {{ $record->version }}</p></div><div class="actions"><a class="button button-secondary" href="{{ route('document-records.index') }}">Все документы</a><a class="button button-primary" href="{{ route('document-records.pdf', $record) }}">Скачать PDF</a></div></div>
<p class="notice">{{ $record->template_snapshot['notice'] }}</p>
<article class="panel document-paper" aria-label="Предпросмотр документа">
    @if($record->organization)<p class="document-centered">{{ $record->organization }}</p>@endif
    <p class="document-centered muted">{{ $record->status === 'draft' ? 'ЧЕРНОВИК · ' : '' }}ЧЕРНОВОЙ ШАБЛОН</p>
    <h2 class="document-centered">{{ $record->title }}</h2><p class="document-centered">{{ $record->number }} от {{ $record->document_date->format('d.m.Y') }}</p>
    @if($record->author_name)<p>Составитель / передающая сторона: {{ $record->author_name }}</p>@endif
    @if($record->recipient_name)<p>Получатель / принимающая сторона: {{ $record->recipient_name }}</p>@endif
    <p>{{ $record->template_snapshot['introduction'] }}</p>
    <div class="document-body">{{ $record->body }}</div>
    @include('document-records.equipment-table')
    <p class="document-signature">{{ $record->template_snapshot['signature'] }}</p>
    <p class="muted">Реквизиты оборудования сохранены при создании документа; последующее изменение карточек не изменяет этот перечень.</p>
</article>
@if($record->status === 'draft' && \App\Support\Access::allows(auth()->user(), 'write'))
<section class="panel stack"><h2>Редактировать текст</h2><form method="post" action="{{ route('document-records.update', $record) }}" class="stack">@csrf @method('PATCH')<input type="hidden" name="expected_version" value="{{ $record->version }}"><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><label class="field">Дата<input type="date" name="document_date" value="{{ is_string(old('document_date')) ? old('document_date') : $record->document_date->format('Y-m-d') }}" required></label>@include('document-records.fields')<p class="muted">Состав оборудования сохранён. Для другого перечня создайте новый документ.</p><button class="button button-primary">Сохранить текст</button></form></section>
<section class="panel stack"><h2>Зафиксировать редакцию</h2><p class="muted">После фиксации текст нельзя редактировать. Это не подпись и не проведение хозяйственной операции.</p><form method="post" action="{{ route('document-records.finalize', $record) }}" data-confirm="Зафиксировать редакцию документа? После этого текст нельзя будет изменить.">@csrf<input type="hidden" name="expected_version" value="{{ $record->version }}"><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><button class="button button-secondary">Зафиксировать редакцию</button></form></section>
@endif
@endsection
