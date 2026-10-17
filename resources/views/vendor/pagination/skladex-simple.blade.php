@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Страницы списка">
        <p class="pagination-summary">Страница <span class="number">{{ $paginator->currentPage() }}</span></p>
        <ul class="pagination-pages">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true"><span aria-hidden="true">‹</span><span>Назад</span></span>
                @else
                    <a class="pagination-link pagination-direction" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Предыдущая страница"><span aria-hidden="true">‹</span><span>Назад</span></a>
                @endif
            </li>
            <li>
                @if ($paginator->hasMorePages())
                    <a class="pagination-link pagination-direction" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Следующая страница"><span>Далее</span><span aria-hidden="true">›</span></a>
                @else
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true"><span>Далее</span><span aria-hidden="true">›</span></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
