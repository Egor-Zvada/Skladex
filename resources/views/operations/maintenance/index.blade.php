@extends('layouts.app')
@section('title','Обслуживание')
@section('content')
<div class="page-header"><div><h1>Обслуживание</h1><p class="muted">Работы по оборудованию и программным сервисам. Выполнение и принятие результата — отдельные действия.</p></div></div>
@include('operations.navigation')
@if($notifications->isNotEmpty())
<details class="panel"><summary>Последние уведомления · {{ $notifications->count() }}</summary><ul>@foreach($notifications as $notification)<li>@if(Route::has($notification->route_name))<a href="{{ route($notification->route_name,$notification->subject_uuid) }}">{{ $notification->title }}</a>@else{{ $notification->title }}@endif</li>@endforeach</ul></details>
@endif
<form method="get" class="panel form-grid"><label class="field">Поиск по работам<input name="q" value="{{ request('q') }}" maxlength="200"></label><label class="field">Состояние<select name="status"><option value="">Все</option>@foreach(\App\Services\OperationsService::WORK_STATUSES as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label><div class="actions"><button class="button button-secondary">Применить</button><a href="{{ route('maintenance.index') }}">Сбросить</a></div></form>
<div class="panel">
@forelse($works as $work)
@if($loop->first)<div class="table-scroll"><table class="table"><thead><tr><th>Работа</th><th>Объект</th><th>Состояние</th><th>Срок</th><th>Исполнитель</th></tr></thead><tbody>@endif
<tr><td><a href="{{ route('maintenance.work.show',$work) }}">{{ $work->title }}</a><div class="muted">{{ \App\Services\OperationsService::PRIORITIES[$work->priority] ?? $work->priority }}</div></td><td>{{ $work->subject_name }}</td><td><span class="badge">{{ \App\Services\OperationsService::WORK_STATUSES[$work->status] ?? $work->status }}</span></td><td>{{ $work->due_on?->format('d.m.Y') ?? 'Не установлен' }}</td><td>{{ $work->assignee?->name ?? 'Не назначен' }}</td></tr>
@if($loop->last)</tbody></table></div>@endif
@empty<div class="empty-state"><h2>Работ пока нет</h2><p>Создайте работу, зарегистрируйте неисправность или настройте регламент. Система запускается без демонстрационных записей.</p></div>@endforelse
{{ $works->links() }}
</div>
@if(\App\Support\Access::allows(auth()->user(),'write'))
<details class="panel" @if($errors->any()) open @endif><summary>Создать работу / добавить выполненную</summary>
<form method="post" action="{{ route('maintenance.work.store') }}" class="stack">@csrf @include('operations.command-key')
@include('operations.subject')
<label class="field">Название<input name="title" value="{{ old('title') }}" required maxlength="200"></label>
<div class="form-grid"><label class="field">Вид<select name="kind">@foreach(\App\Services\OperationsService::KINDS as $value=>$label)<option value="{{ $value }}" @selected(old('kind','repair')===$value)>{{ $label }}</option>@endforeach</select></label><label class="field">Приоритет<select name="priority">@foreach(\App\Services\OperationsService::PRIORITIES as $value=>$label)<option value="{{ $value }}" @selected(old('priority','normal')===$value)>{{ $label }}</option>@endforeach</select></label><label class="field">Срок<input type="date" name="due_on" value="{{ old('due_on') }}"></label><label class="field">Исполнитель<select name="assignee_id"><option value="">Не назначен</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string)old('assignee_id')===(string)$user->id)>{{ $user->name }}</option>@endforeach</select></label></div>
<label class="field">Описание<textarea name="description" maxlength="10000">{{ old('description') }}</textarea></label>
<label class="field">Пункты осмотра — по одному на строку<textarea name="checklist_text" maxlength="20000" placeholder="Проверка работоспособности&#10;Осмотр разъёмов">{{ old('checklist_text') }}</textarea></label>
<label><input type="checkbox" name="requires_other_acceptor" value="1" @checked(old('requires_other_acceptor'))> Результат должен принять другой сотрудник</label>
<details><summary>Уже выполнено — короткая регистрация факта</summary><label><input type="checkbox" name="record_completed" value="1" @checked(old('record_completed'))> Сразу зарегистрировать выполненную работу</label><label class="field">Дата и время фактического выполнения (Сахалин)<input type="datetime-local" name="completed_at" value="{{ old('completed_at') }}"></label><label class="field">Результат<textarea name="result" maxlength="20000">{{ old('result') }}</textarea></label><p class="muted">Для работы с обязательным чек-листом сначала сохраните её, затем внесите результаты каждого пункта. Результат принимается отдельно.</p></details>
<button class="button button-primary">Сохранить работу</button>
</form></details>
@endif
@endsection
