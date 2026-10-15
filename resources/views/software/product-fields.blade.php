<div class="form-grid">
<label class="field">Название<input name="name" required maxlength="255" value="{{ old('name', $product?->name) }}"></label>
<label class="field">Тип<select name="kind">@foreach(['third_party'=>'Стороннее ПО','in_house'=>'Собственная разработка','firmware'=>'Прошивка'] as $value=>$label)<option value="{{ $value }}" @selected(old('kind', $product?->kind ?? 'third_party') === $value)>{{ $label }}</option>@endforeach</select></label>
<label class="field">Производитель <span class="muted">необязательно</span><input name="vendor" maxlength="255" value="{{ old('vendor', $product?->vendor) }}"></label>
<label class="field">Ответственный<select name="owner_id"><option value="">Не указан</option>@foreach($owners as $owner)<option value="{{ $owner->id }}" @selected((string) old('owner_id', $product?->owner_id) === (string) $owner->id)>{{ $owner->name }}</option>@endforeach</select></label>
<label class="field">Репозиторий (HTTP/HTTPS)<input name="repository_url" type="url" maxlength="2048" value="{{ old('repository_url', $product?->repository_url) }}" placeholder="https://…"></label>
<label class="field">Описание<textarea name="description" rows="3" maxlength="10000">{{ old('description', $product?->description) }}</textarea></label>
</div>
<p class="muted">Пароли, токены и секреты в описание и URL не помещайте. Укажите ссылку на запись в отдельном хранилище секретов.</p>
