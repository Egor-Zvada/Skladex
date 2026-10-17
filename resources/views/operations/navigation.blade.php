<nav class="actions" aria-label="Разделы обслуживания">
    <a class="button button-secondary" href="{{ route('maintenance.index') }}">Работы</a>
    <a class="button button-secondary" href="{{ route('maintenance.index', ['mine'=>1]) }}">Мои работы</a>
    <a class="button button-secondary" href="{{ route('maintenance.index', ['journal'=>1]) }}">Журнал</a>
    <a class="button button-secondary" href="{{ route('maintenance.requests.index') }}">Заявки</a>
    <a class="button button-secondary" href="{{ route('maintenance.plans.index') }}">Регламенты</a>
    <a class="button button-secondary" href="{{ route('maintenance.presets.index') }}">Рекомендуемые регламенты</a>
</nav>
