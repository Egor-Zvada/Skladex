<div class="le-paper-controls">
    <label class="field">Формат листа<select data-paper-preset><option value="A4">A4 · 210 × 297 мм</option><option value="A3">A3 · 297 × 420 мм</option><option value="A5">A5 · 148 × 210 мм</option><option value="custom">Свой размер</option></select></label>
    <label class="field">Ориентация<select data-paper-orientation><option value="portrait">Книжная</option><option value="landscape">Альбомная</option></select></label>
</div>
<div class="le-fields">
    @foreach ([
        'paper_width_mm'=>['Ширина листа, мм',50,420,'0.01'], 'paper_height_mm'=>['Высота листа, мм',50,420,'0.01'],
        'label_width_mm'=>['Ширина этикетки, мм',0.01,420,'0.01'], 'label_height_mm'=>['Высота этикетки, мм',0.01,420,'0.01'],
        'columns'=>['Столбцов',1,500,1], 'rows'=>['Строк',1,500,1],
        'gap_x_mm'=>['Зазор X, мм',0,420,'0.01'], 'gap_y_mm'=>['Зазор Y, мм',0,420,'0.01'],
        'margin_left_mm'=>['Поле слева, мм',0,420,'0.01'], 'margin_top_mm'=>['Поле сверху, мм',0,420,'0.01'],
        'offset_x_mm'=>['Смещение принтера X, мм',-1000,1000,'0.01'], 'offset_y_mm'=>['Смещение принтера Y, мм',-1000,1000,'0.01'],
    ] as $field=>[$label,$min,$max,$step])
        @php
            $fieldValue = old($field, $geometry[$field]);
        @endphp
        <label class="field" for="label-{{ $field }}">{{ $label }}<input type="number" id="label-{{ $field }}" name="{{ $field }}" value="{{ is_scalar($fieldValue)?$fieldValue:'' }}" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" inputmode="{{ $step===1?'numeric':'decimal' }}" required></label>
    @endforeach
</div>
<p class="field-help">X — вправо, Y — вниз; минус двигает в обратную сторону. Поля задают сетку вырубок, смещения — поправку подачи принтера. Все ячейки должны помещаться на листе.</p>
