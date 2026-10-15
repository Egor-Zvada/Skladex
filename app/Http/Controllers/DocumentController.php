<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Document;
use App\Models\SoftwareProduct;
use App\Models\WorkOrder;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function store(Request $r)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['kind' => 'required|in:asset,software,work', 'target' => 'required|uuid', 'file' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg,webp,txt,csv,docx,xlsx']);
        $model = match ($d['kind']) {
            'asset' => Asset::whereUuid($d['target'])->whereNull('archived_at')->firstOrFail(),'software' => SoftwareProduct::whereUuid($d['target'])->whereNull('archived_at')->firstOrFail(),'work' => WorkOrder::whereUuid($d['target'])->firstOrFail()
        };
        $fk = match ($d['kind']) {
            'asset' => 'asset_id','software' => 'software_product_id','work' => 'work_order_id'
        };
        $file = $r->file('file');
        $uuid = (string) Str::uuid();
        $path = $file->storeAs('documents', $uuid.'.'.$file->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($r, $file, $path, $uuid, $model, $fk) {
                $doc = Document::create(['uuid' => $uuid, $fk => $model->id, 'uploaded_by' => $r->user()->id, 'path' => $path, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
                Audit::record('document.uploaded', $doc, ['bytes' => $doc->size]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Документ сохранён.');
    }

    public function download(Request $r, Document $document)
    {
        Access::check($r->user(), 'read');
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        Audit::record('document.downloaded', $document);

        return Storage::disk('local')->download($document->path, $document->original_name, ['Cache-Control' => 'private,no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
