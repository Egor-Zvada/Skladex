@extends('layouts.app')
@section('title', 'Active Directory')
@push('scripts')
<script src="{{ asset('static/directory-settings.js') }}?v={{ filemtime(public_path('static/directory-settings.js')) }}" defer></script>
@endpush
@section('content')
@php
    $canEdit = (bool) ($canEdit ?? false);
    $textValue = static function (string $key, string $fallback = '') {
        $value = old($key, $fallback);
        return is_string($value) ? $value : $fallback;
    };
    $hosts = $directory['hosts'] ?? [];
    $hosts = is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    $mode = $textValue('mode', $directory['mode'] ?? 'ldaps');
    $enabled = old('enabled', $directory['enabled'] ?? false);
    $enabled = in_array($enabled, [true, 1, '1'], true);
    $port = old('port', $directory['port'] ?? 636);
    $port = is_int($port) || is_string($port) ? $port : 636;
    $expectedVersion = old('expected_version', $directory['version'] ?? 0);
    $expectedVersion = is_int($expectedVersion) || is_string($expectedVersion) ? $expectedVersion : 0;
    $groups = old('groups', $directory['groups'] ?? []);
    $groups = is_array($groups) ? array_slice(array_values($groups), 0, 20) : [];
    $groups = array_values(array_filter($groups, 'is_array'));
    if (! $groups) $groups = [['dn' => '', 'role' => 'viewer']];
    $roleLabels = ['viewer' => 'Наблюдатель', 'operator' => 'Специалист', 'admin' => 'Администратор'];
    $checked = $checked ?? session('directory_check');
    $checked = is_array($checked) ? $checked : null;
    $checkRows = is_array($checked['controllers'] ?? null) ? $checked['controllers'] : [];
@endphp
<div class="page-header">
    <div><h1>Active Directory</h1><p class="muted">Подключение к домену и доступ по группам.</p></div>
    <a class="button button-secondary" href="{{ route('settings.index') }}">Все настройки</a>
</div>
@if($checked)
<section class="alert {{ ($checked['ok'] ?? false) ? '' : 'alert-error' }}" role="status">
    <h2>{{ ($checked['ok'] ?? false) ? 'Проверка пройдена' : 'Проверка не пройдена' }}</h2>
    @if(is_string($checked['message'] ?? null))
        <p>{{ $checked['message'] }}</p>
    @endif
    @if($checkRows)
        <ul>
        @foreach($checkRows as $check)
            @if(is_array($check))
                <li>
                @if(is_string($check['host'] ?? null))
                    <span class="mono">{{ $check['host'] }}</span> ·
                @endif
                {{ ($check['ok'] ?? false) ? 'Доступен' : 'Ошибка' }}
                </li>
            @endif
        @endforeach
        </ul>
    @endif
</section>
@endif
@if(!$canEdit)
<p class="alert">Вы можете посмотреть настройки и проверить соединение. Менять подключение может администратор с локальной учётной записью Skladex.</p>
@endif
<form method="post" action="{{ route('settings.directory.update') }}" class="stack" data-directory-settings>
    @csrf
    <input type="hidden" name="expected_version" value="{{ $expectedVersion }}">
    <section class="panel stack">
        <div class="panel-header"><div><h2>Вход через домен</h2><p class="muted">Локальная учётная запись администратора продолжает работать независимо от домена.</p></div><span class="badge">{{ ($directory['enabled'] ?? false) ? 'Включён' : 'Выключен' }}</span></div>
        <div>
            <input type="hidden" name="enabled" value="{{ $canEdit ? '0' : ($enabled ? '1' : '0') }}">
            <label class="checkbox-field"><input type="checkbox" name="enabled" value="1" @checked($enabled) @disabled(!$canEdit)> Разрешить вход через Active Directory</label>
        </div>
        <div class="form-grid">
            <label class="field">Контроллеры домена<textarea name="hosts" rows="3" maxlength="1000" autocomplete="off" spellcheck="false" @readonly(!$canEdit)>{{ $textValue('hosts', implode("\n", $hosts)) }}</textarea><span class="muted">DNS-имена, каждое с новой строки. Не более трёх. Первый — основной, остальные — резервные.</span></label>
            <div class="stack-small">
                <label class="field">Защищённое соединение<select name="mode" @disabled(!$canEdit)><option value="ldaps" @selected($mode === 'ldaps')>LDAPS</option><option value="starttls" @selected($mode === 'starttls')>LDAP с StartTLS</option></select></label>
                @if(!$canEdit)
                <input type="hidden" name="mode" value="{{ $mode }}">
                @endif
                <label class="field">Порт<input type="number" name="port" value="{{ $port }}" min="1" max="65535" @readonly(!$canEdit)><span class="muted">Обычно 636 для LDAPS и 389 для StartTLS.</span></label>
            </div>
            <label class="field">Область поиска — Base DN<input name="base_dn" value="{{ $textValue('base_dn', $directory['base_dn'] ?? '') }}" maxlength="2048" autocomplete="off" spellcheck="false" placeholder="DC=example,DC=local" @readonly(!$canEdit)></label>
            <label class="field">Короткое имя домена — NetBIOS<input name="netbios" value="{{ $textValue('netbios', $directory['netbios'] ?? '') }}" maxlength="15" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="EXAMPLE" @readonly(!$canEdit)><span class="muted">Для входа в формате ДОМЕН\логин. Также доступны логин и доменная почта.</span></label>
        </div>
    </section>
    <section class="panel stack">
        <div><h2>Служебная учётная запись</h2><p class="muted">Используется только для чтения пользователей и групп каталога.</p></div>
        <div class="form-grid">
            <label class="field">Логин или DN<input name="bind_dn" value="{{ $textValue('bind_dn', $directory['bind_dn'] ?? '') }}" maxlength="2048" autocomplete="off" spellcheck="false" @readonly(!$canEdit)></label>
            @if($canEdit)
            <label class="field">Пароль служебной учётной записи<input name="bind_password" type="password" maxlength="1024" autocomplete="new-password"><span class="muted">{{ ($directory['has_password'] ?? false) ? 'Пароль сохранён. Оставьте поле пустым, чтобы сохранить его.' : 'Пароль ещё не задан.' }}</span></label>
            @else
            <div><span class="muted">Пароль</span><p>{{ ($directory['has_password'] ?? false) ? 'Сохранён · значение скрыто' : 'Не задан' }}</p></div>
            @endif
        </div>
    </section>
    <section class="panel stack">
        <div><h2>Группы и роли</h2><p class="muted">Вход разрешён только участникам указанных групп, включая вложенные группы. Если подходят несколько ролей, применяется старшая.</p></div>
        <div class="stack" data-directory-groups>
            @foreach($groups as $index => $group)
            @php
                $groupDn = is_string($group['dn'] ?? null) ? $group['dn'] : '';
                $groupRole = is_string($group['role'] ?? null) ? $group['role'] : 'viewer';
            @endphp
            <fieldset class="stack-small" data-directory-group>
                <legend data-directory-group-title>Группа {{ $loop->iteration }}</legend>
                <div class="form-grid">
                    <label class="field">DN группы<input name="groups[{{ $index }}][dn]" value="{{ $groupDn }}" maxlength="2048" autocomplete="off" spellcheck="false" placeholder="CN=Skladex-Users,OU=Groups,DC=example,DC=local" @readonly(!$canEdit)></label>
                    <label class="field">Роль в Skladex<select name="groups[{{ $index }}][role]" @disabled(!$canEdit)>
                        @foreach($roleLabels as $value => $label)
                            <option value="{{ $value }}" @selected($groupRole === $value)>{{ $label }}</option>
                        @endforeach
                    </select></label>
                    @if(!$canEdit)
                    <input type="hidden" name="groups[{{ $index }}][role]" value="{{ $groupRole }}">
                    @endif
                </div>
                @if($canEdit)
                <div><button class="button button-ghost button-small" type="button" data-directory-remove-group>Удалить группу</button></div>
                @endif
            </fieldset>
            @endforeach
        </div>
        @if($canEdit)
        <div class="actions"><button class="button button-secondary" type="button" data-directory-add-group>Добавить группу</button><span class="muted" aria-live="polite" data-directory-group-count></span></div>
        <template data-directory-group-template>
            <fieldset class="stack-small" data-directory-group>
                <legend data-directory-group-title>Новая группа</legend>
                <div class="form-grid">
                    <label class="field">DN группы<input data-directory-name="dn" maxlength="2048" autocomplete="off" spellcheck="false" placeholder="CN=Skladex-Users,OU=Groups,DC=example,DC=local"></label>
                    <label class="field">Роль в Skladex<select data-directory-name="role">
                        @foreach($roleLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select></label>
                </div>
                <div><button class="button button-ghost button-small" type="button" data-directory-remove-group>Удалить группу</button></div>
            </fieldset>
        </template>
        @endif
    </section>
    <details class="panel" @if($errors->has('ca_pem')) open @endif>
        <summary>Сертификат центра сертификации</summary>
        <div class="stack section-spacing"><p class="muted">Skladex проверяет подлинность контроллеров домена. Вставьте сертификат вашего центра сертификации и при необходимости промежуточные сертификаты в формате PEM.</p><label class="field">Сертификаты CA<textarea name="ca_pem" class="mono" rows="8" maxlength="65536" autocomplete="off" spellcheck="false" wrap="off" @readonly(!$canEdit)>{{ $textValue('ca_pem', $directory['ca_pem'] ?? '') }}</textarea><span class="muted">Только публичные сертификаты, без закрытых ключей.</span></label></div>
    </details>
    <section class="panel stack">
        <div><h2>Проверить и сохранить</h2><p class="muted">Проверка устанавливает защищённое соединение и проверяет служебный вход, область поиска и группы. Она не сохраняет изменения и не проверяет пароль конкретного сотрудника.</p></div>
        @if($canEdit)
        <label class="field">Ваш текущий пароль локального администратора<input name="current_password" type="password" maxlength="1024" autocomplete="current-password" data-directory-current-password><span class="muted">Нужен для сохранения настроек и для проверки изменённого подключения с сохранённым служебным паролем.</span></label>
        @endif
        <div class="actions">
            <button class="button button-secondary" type="submit" formaction="{{ route('settings.directory.check') }}" data-directory-check>Проверить соединение</button>
            @if($canEdit)
            <button class="button button-primary" type="submit" data-directory-save>Сохранить настройки</button>
            @endif
        </div>
        <p class="muted" role="status" aria-live="polite" data-directory-submit-status></p>
    </section>
</form>
@endsection
