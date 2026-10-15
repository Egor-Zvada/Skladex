@php
$documentField=['asset'=>'asset_id','software'=>'software_product_id','work'=>'work_order_id'][$documentKind];
$documents=\App\Models\Document::where($documentField,$documentSubject->id)->latest()->get();
@endphp
<section class="panel stack"><h2>Документы</h2>@forelse($documents as $doc)<div class="list-row"><a href="{{ route('documents.download',$doc) }}">{{ $doc->original_name }}</a><span class="muted">{{ number_format($doc->size/1024,0) }} КБ</span></div>@empty<p class="muted">Вложений пока нет</p>@endforelse
@if(\App\Support\Access::allows(auth()->user(),'write'))<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="actions">@csrf<input type="hidden" name="kind" value="{{ $documentKind }}"><input type="hidden" name="target" value="{{ $documentSubject->uuid }}"><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><label class="field">Документ или фотография, до 10 МБ<input type="file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.docx,.xlsx"></label><button class="button button-secondary">Прикрепить</button></form>@endif</section>
