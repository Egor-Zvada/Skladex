@if ($paginator->hasPages())
    @php
        $window = \Illuminate\Pagination\UrlWindow::make($paginator->onEachSide(1));
        $pageGroups = array_filter([
            $window['first'],
            is_array($window['slider']) ? '…' : null,
            $window['slider'],
            is_array($window['last']) ? '…' : null,
            $window['last'],
        ]);
    @endphp
    <nav class="pagination" aria-label="Страницы списка">
        <p class="pagination-summary">Показано <span class="number">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span> из <span class="number">{{ $paginator->total() }}</span></p>
        <ul class="pagination-pages">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true"><span aria-hidden="true">‹</span><span>Назад</span></span>
                @else
                    <a class="pagination-link pagination-direction" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Предыдущая страница"><span aria-hidden="true">‹</span><span>Назад</span></a>
                @endif
            </li>
            <li class="pagination-position"><span class="number" aria-label="Страница {{ $paginator->currentPage() }} из {{ $paginator->lastPage() }}">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span></li>
            @foreach ($pageGroups as $group)
                @if (is_string($group))
                    <li class="pagination-gap" aria-hidden="true"><span>…</span></li>
                @else
                    @foreach ($group as $page => $url)
                        <li class="pagination-number">
                            @if ($page === $paginator->currentPage())
                                <span class="pagination-link is-current" aria-current="page" aria-label="Страница {{ $page }}, текущая">{{ $page }}</span>
                            @else
                                <a class="pagination-link" href="{{ $url }}" aria-label="Страница {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach
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
