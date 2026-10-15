@extends('layouts.app')
@section('title','Печать этикеток')
@section('content')
<div class="page-header"><div><p class="eyebrow">QR · A4</p><h1>Печать этикеток</h1><p class="muted">Размеры в миллиметрах, проверка сетки и начало с нужной ячейки.</p></div></div>
<div class="alert" role="note">Печатайте PDF на A4 в масштабе <strong>100% / «Фактический размер»</strong>. Сначала выведите тест на обычную бумагу: контрольные отрезки должны быть ровно 100 мм. Отключите подгонку под страницу.</div>
<form method="get" action="{{ route('labels.index') }}" class="panel filters label-filter-form">
    <label class="field">Профиль листа и принтера<select name="profile_id"><option value="">Пример 64 × 33 мм · 3 × 8</option>@foreach($profiles as $saved)<option value="{{ $saved->id }}" @selected((string)($profile?->id)===(string)$saved->id)>{{ $saved->name }}</option>@endforeach</select></label>
    <label class="field">Что печатать<select name="target_type"><option value="asset" @selected($targetType==='asset')>Оборудование</option><option value="location" @selected($targetType==='location')>Кабинеты</option></select></label>
    <label class="field">Поиск<input type="search" name="q" value="{{ $query }}" placeholder="Название или код" maxlength="150"></label>
    <button class="button button-secondary" type="submit">Показать</button>
</form>
<form method="post" action="{{ route('labels.preview') }}" class="stack" data-label-batch>
    @csrf
    <input type="hidden" name="target_type" value="{{ $targetType }}">
    <input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
    @if($profile)<input type="hidden" name="profile_id" value="{{ $profile->id }}"><input type="hidden" name="expected_version" value="{{ $profile->version }}">@endif
    <section class="panel stack">
        <div class="panel-header"><div><h2>Лист и калибровка</h2><p class="muted">A4 · 210 × 297 мм · не более 500 ячеек</p></div>@if($profile)<span class="badge">Версия {{ $profile->version }}</span>@endif</div>
        @unless($profile)<p class="field-help">Это пример профиля. Сверьте размеры этикетки, поля и зазоры с вашим листом перед печатью.</p>@endunless
        @include('labels.geometry')
        <p class="field-help">Для удобного сканирования ориентируйтесь на QR размером от 20 мм вместе с белым полем. На небольших этикетках обязательно проверьте читаемость камерой.</p>
        @php($startValue=old('start_cell',1)) @php($nameValue=old('name',$profile?->name))
        <div class="form-grid"><label class="field">Начальная ячейка<input name="start_cell" type="number" min="1" max="500" step="1" value="{{ is_scalar($startValue)?$startValue:1 }}" required><span class="field-help">Нумерация слева направо, затем следующая строка. Пропуск действует только на первом листе.</span></label>
            @if(\App\Support\Access::allows(auth()->user(),'write'))<label class="field">Название профиля<input name="name" type="text" value="{{ is_scalar($nameValue)?$nameValue:'' }}" maxlength="100" placeholder="Например: принтер в ИТ-службе"></label>@endif
        </div>
        <div class="actions"><button class="button button-secondary" type="submit" formaction="{{ route('labels.test') }}" formnovalidate>Тестовый лист с линией 100 мм</button>
            @if(\App\Support\Access::allows(auth()->user(),'write'))<button class="button button-secondary" type="submit" formaction="{{ route('labels.store') }}">{{ $profile?'Сохранить изменения профиля':'Сохранить новый профиль' }}</button>@endif
        </div>
    </section>
    <section class="panel stack">
        <div class="panel-header"><div><h2>{{ $targetType==='asset'?'Оборудование':'Кабинеты' }} для печати</h2><p class="muted">До 500 объектов и 50 страниц за один раз. Порядок этикеток соответствует порядку списка.</p></div><span class="badge" data-label-count aria-live="polite">Выбрано: 0</span></div>
        @if($truncated)<p class="alert" role="status">Найдено больше 500 объектов. Показаны первые 500 по названию; уточните поиск, чтобы выбрать остальные.</p>@endif
        @if($targets->isNotEmpty())
        <table class="table"><thead><tr><th scope="col"><label class="selection-control"><input type="checkbox" data-label-select-all><span class="sr-only">Выбрать все показанные объекты</span></label></th><th scope="col">Название</th><th scope="col">Код</th></tr></thead><tbody>
        @foreach($targets as $target)<tr><td><label class="selection-control"><input type="checkbox" name="target_ids[]" value="{{ $target->uuid }}" @checked(in_array($target->uuid,(array)old('target_ids',[]),true))><span class="sr-only">Выбрать {{ $target->name }}</span></label></td><td>{{ $target->name }}</td><td class="mono">{{ $target->code }}</td></tr>@endforeach
        </tbody></table>
        @else<div class="empty-state">@include('partials.icon',['name'=>'labels'])<h3>Пока нечего печатать</h3><p>{{ $query!==''?'По этому запросу ничего не найдено. Измените поиск.':'Сначала добавьте оборудование или кабинеты в реестр.' }}</p></div>@endif
        <div class="actions"><button class="button button-primary" type="submit" data-label-requires-selection>Предпросмотр</button><button class="button button-secondary" type="submit" formaction="{{ route('labels.pdf') }}" data-label-requires-selection>Скачать PDF</button></div>
    </section>
</form>
@endsection
