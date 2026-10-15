<div class="form-grid">
<label class="field">Фактическая версия<select name="software_release_id"><option value="">Неизвестна / не указана</option>@foreach($software->releases as $release)<option value="{{ $release->id }}" @selected((string) ($installation?->software_release_id) === (string) $release->id)>{{ $release->version_name }}</option>@endforeach</select></label>
<label class="field">Оборудование<select name="asset_id"><option value="">Без физического оборудования</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected($installation?->asset_id === $asset->id)>{{ $asset->code }} · {{ $asset->name }}</option>@endforeach</select></label>
<label class="field">Среда / ВМ / контейнер<input name="environment" maxlength="160" value="{{ $installation?->environment }}" placeholder="Например, VM: intranet-prod"></label>
<label class="field">Имя сервиса<input name="service_name" maxlength="255" value="{{ $installation?->service_name }}"></label>
<label class="field">Дата установки<input type="date" name="installed_on" value="{{ $installation?->installed_on?->format('Y-m-d') }}"></label>
<label class="field">Примечание<textarea name="notes" maxlength="10000" rows="2">{{ $installation?->notes }}</textarea></label>
</div>
<label><input type="checkbox" name="verified_now" value="1"> Я проверил фактическую версию и размещение сейчас</label>
<p class="muted">Укажите оборудование или среду. Сохранение карточки не запускает установку или обновление.</p>
