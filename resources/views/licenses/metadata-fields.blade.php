<div class="form-grid">
<label class="field">Название / пометка<input name="name" maxlength="255" value="{{ old('name', $license?->name) }}" placeholder="Необязательно"></label>
<label class="field">Внешний номер экземпляра<input name="external_reference" maxlength="255" value="{{ old('external_reference', $license?->external_reference) }}" placeholder="Необязательно, без секретного ключа"></label>
<label class="field">Метрика<select name="metric">@foreach(['device'=>'Устройство','instance'=>'Экземпляр','user'=>'Пользователь (ручная проверка)','unknown'=>'Условия неизвестны'] as $value=>$label)<option value="{{ $value }}" @selected(old('metric', $license?->metric ?? 'device') === $value)>{{ $label }}</option>@endforeach</select></label>
<label class="field">Количество мест<input name="capacity" type="number" min="0" max="1000000000" value="{{ old('capacity', $license?->capacity) }}" placeholder="Неизвестно"></label>
<label class="field">Действует до<input name="expires_on" type="date" value="{{ old('expires_on', $license?->expires_on?->format('Y-m-d')) }}"></label>
<label class="field">Примечание<textarea name="notes" maxlength="10000" rows="2">{{ old('notes', $license?->notes) }}</textarea></label>
</div>
