@foreach (['success' => 'check', 'status' => 'check', 'warning' => 'warning', 'error' => 'warning', 'info' => 'info'] as $flashKey => $flashIcon)
    @if (session()->has($flashKey) && is_string(session($flashKey)))
        <div class="alert alert-{{ $flashKey }}" data-feedback role="{{ in_array($flashKey, ['error', 'warning']) ? 'alert' : 'status' }}">
            @include('partials.icon', ['name' => $flashIcon])
            <div class="alert-message">{{ session($flashKey) }}</div>
            <button type="button" class="icon-button alert-dismiss" data-dismiss-feedback aria-label="Скрыть сообщение">@include('partials.icon', ['name' => 'close'])</button>
        </div>
    @endif
@endforeach
@if ($errors->any())
    <section class="alert alert-error validation-summary" id="validation-summary" role="alert" tabindex="-1" aria-labelledby="validation-summary-title">
        @include('partials.icon', ['name' => 'warning'])
        <div class="alert-message">
            <h2 id="validation-summary-title">Проверьте введённые данные</h2>
            <ul>
                @foreach ($errors->getMessages() as $fieldName => $messages)
                    <li id="validation-error-{{ $loop->index }}" data-validation-field="{{ $fieldName }}">{{ implode(' ', $messages) }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
