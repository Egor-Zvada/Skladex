<input type="hidden" name="paper_width_mm" value="210"><input type="hidden" name="paper_height_mm" value="297">
<div class="form-grid label-geometry-fields">
    @foreach ([
        'label_width_mm'=>['Ширина этикетки, мм',0.01,210,'0.01'], 'label_height_mm'=>['Высота этикетки, мм',0.01,297,'0.01'],
        'columns'=>['Столбцов',1,500,1], 'rows'=>['Строк',1,500,1],
        'gap_x_mm'=>['Зазор по горизонтали, мм',0,210,'0.01'], 'gap_y_mm'=>['Зазор по вертикали, мм',0,297,'0.01'],
        'margin_left_mm'=>['Поле слева, мм',0,210,'0.01'], 'margin_top_mm'=>['Поле сверху, мм',0,297,'0.01'],
        'offset_x_mm'=>['Смещение принтера X, мм',-210,210,'0.01'], 'offset_y_mm'=>['Смещение принтера Y, мм',-297,297,'0.01'],
    ] as $field=>[$label,$min,$max,$step])
        @php($fieldValue=old($field,$geometry[$field]))
        <label class="field" for="label-{{ $field }}">{{ $label }}<input type="number" id="label-{{ $field }}" name="{{ $field }}" value="{{ is_scalar($fieldValue)?$fieldValue:'' }}" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" inputmode="{{ $step===1?'numeric':'decimal' }}" required></label>
    @endforeach
</div>
<p class="field-help">X: плюс вправо, минус влево. Y: плюс вниз, минус вверх. Поле задаёт положение сетки на листе, смещение компенсирует подачу конкретного принтера. Все ячейки должны помещаться в A4.</p>
