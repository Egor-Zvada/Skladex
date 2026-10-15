<button type="button" class="icon-button theme-toggle {{ $class ?? '' }}" data-theme-toggle aria-label="Переключить тему" aria-pressed="false" title="Переключить тему">
    <span class="theme-icon-dark">@include('partials.icon', ['name' => 'moon'])</span>
    <span class="theme-icon-light">@include('partials.icon', ['name' => 'sun'])</span>
    <span class="sr-only" data-theme-label>Переключить тему</span>
</button>
