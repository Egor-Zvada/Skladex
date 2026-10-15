<div class="form-grid">
    <label class="field">Оборудование
        <select name="asset_uuid"><option value="">Не выбрано</option>@foreach($assets as $asset)<option value="{{ $asset->uuid }}" @selected(old('asset_uuid', request('asset_uuid')) === $asset->uuid)>{{ $asset->code }} · {{ $asset->name }}</option>@endforeach</select>
    </label>
    <label class="field">Или установка ПО / сервис
        <select name="software_installation_id"><option value="">Не выбрано</option>@foreach($installations as $installation)<option value="{{ $installation->id }}" @selected((string)old('software_installation_id') === (string)$installation->id)>{{ $installation->product?->name }} · {{ $installation->service_name ?? $installation->environment ?? $installation->id }}</option>@endforeach</select>
    </label>
</div>
<p class="muted">Выберите ровно один основной объект. Связь с оборудованием не подтверждает его исправность автоматически.</p>
