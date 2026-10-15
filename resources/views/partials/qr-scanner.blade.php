@php
    $scannerId = $id ?? 'qr-scanner';
    $scannerTarget = $target ?? 'input[name="code"]';
@endphp
<button type="button" class="button button-primary {{ $buttonClass ?? '' }}" data-scan-open="{{ $scannerId }}">@include('partials.icon', ['name' => 'qr'])<span>{{ $buttonLabel ?? 'Сканировать QR' }}</span></button>
<dialog class="scanner-dialog" id="{{ $scannerId }}" data-scan-dialog data-scan-target="{{ $scannerTarget }}" data-scan-submit="{{ !empty($autoSubmit) ? 'true' : 'false' }}" aria-labelledby="{{ $scannerId }}-title" aria-describedby="{{ $scannerId }}-help">
    <div class="scanner-header"><div><p class="eyebrow">Сканирование</p><h2 id="{{ $scannerId }}-title">Наведите камеру на QR</h2></div><button type="button" class="icon-button" data-scan-close aria-label="Закрыть камеру">@include('partials.icon', ['name' => 'close'])</button></div>
    <div class="scanner-viewport"><video data-scan-video playsinline muted aria-label="Изображение с камеры"></video><div class="scanner-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div class="scanner-placeholder" data-scan-placeholder>@include('partials.icon', ['name' => 'camera'])</div></div>
    <p class="scanner-status" data-scan-status role="status" aria-live="polite">Подключаем камеру…</p>
    <p class="muted scanner-help" id="{{ $scannerId }}-help">Камера включается только для сканирования. Можно закрыть её и ввести код вручную.</p>
    <div class="scanner-actions"><button type="button" class="button button-secondary" data-scan-torch aria-pressed="false" hidden>@include('partials.icon', ['name' => 'flash'])<span>Подсветка</span></button><button type="button" class="button button-secondary" data-scan-retry hidden>Повторить</button><button type="button" class="button button-secondary" data-scan-manual>Ввести код</button></div>
</dialog>
