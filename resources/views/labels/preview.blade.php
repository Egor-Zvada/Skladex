@extends('layouts.app')
@section('title','Предпросмотр этикеток')
@section('content')
<div class="page-header"><div><p class="eyebrow">A4 · 210 × 297 мм</p><h1>Предпросмотр этикеток</h1><p class="muted">{{ count($targets) }} этикеток · {{ $layout['pages_count'] }} листов · первая ячейка {{ $startCell }}</p></div></div>
@if(min(array_column($boxes,'qr_size_mm'))<20)<div class="alert" role="status">В этом формате QR меньше 20 мм вместе с белым полем. Он может плохо читаться. Сначала распечатайте одну этикетку на обычной бумаге и проверьте камерой; при необходимости увеличьте размер.</div>@endif
<form method="post" action="{{ route('labels.pdf') }}" class="actions label-preview-actions">@csrf
    @foreach($geometry as $field=>$value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
    <input type="hidden" name="target_type" value="{{ $targets[0]['type'] }}"><input type="hidden" name="start_cell" value="{{ $startCell }}">
    @foreach($targets as $target)<input type="hidden" name="target_ids[]" value="{{ $target['uuid'] }}">@endforeach
    <button class="button button-primary" type="submit">Скачать PDF</button><button class="button button-secondary" type="submit" formaction="{{ route('labels.test') }}">Тестовый лист 100 мм</button><a class="button button-secondary" href="{{ route('labels.index',['target_type'=>$targets[0]['type']]) }}">К настройкам печати</a>
</form>
<p class="field-help section-spacing">Положение и размер ячеек рассчитаны тем же сервисом, что и PDF. Серые границы показаны только для проверки и не печатаются на рабочих этикетках. Печатайте PDF на A4: 100% / «Фактический размер», без подгонки.</p>
<div class="label-preview-pages stack">
@foreach($layout['pages'] as $pageIndex=>$cells)
<section class="stack-small"><p class="muted">Лист {{ $pageIndex+1 }} · {{ count($cells) }} этикеток</p><div class="label-preview"><div class="label-sheet label-screen-sheet" style="width:210mm;height:297mm" aria-label="Лист {{ $pageIndex+1 }}">
@foreach($cells as $cell)
    @php($box=$boxes[$cell['index']]) @php($target=$targets[$cell['index']])
    <div class="label-screen-cell" style="left:{{ $cell['x_mm'] }}mm;top:{{ $cell['y_mm'] }}mm;width:{{ $cell['width_mm'] }}mm;height:{{ $cell['height_mm'] }}mm">
        <img src="{{ $images[$cell['index']] }}" alt="QR {{ $target['code'] }}" style="left:{{ $box['qr_x_mm'] }}mm;top:{{ $box['qr_y_mm'] }}mm;width:{{ $box['qr_size_mm'] }}mm;height:{{ $box['qr_size_mm'] }}mm">
        @if($box['show_text'])<div class="label-screen-text" style="left:{{ $box['text_x_mm'] }}mm;top:{{ $box['text_y_mm'] }}mm;width:{{ $box['text_width_mm'] }}mm"><span class="label-screen-code">{{ $target['code'] }}</span><span class="label-screen-name {{ $box['horizontal']?'':'is-single-line' }}">{{ $target['name'] }}</span></div>@endif
    </div>
@endforeach
</div></div></section>
@endforeach
</div>
@endsection
