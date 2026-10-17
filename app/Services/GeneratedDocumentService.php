<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\GeneratedDocumentRecord;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GeneratedDocumentService
{
    public const MAX_ASSETS = 100;

    /** Built-in drafts are data, never executable uploaded Blade/HTML templates. */
    public function templates(): array
    {
        $types = [
            'transfer' => ['Акт приёма-передачи оборудования', 'Перечень оборудования для передачи. Укажите стороны, основание и условия передачи.', 'Передающая сторона: ____________________    Принимающая сторона: ____________________'],
            'defect' => ['Акт дефектовки оборудования', 'Перечень оборудования с заявленными неисправностями. Опишите симптомы, выполненную проверку и выводы.', 'Осмотр провели: ____________________    Дата: ____________________'],
            'inspection' => ['Акт осмотра оборудования', 'Оборудование, представленное к осмотру. Укажите цель осмотра, фактическое состояние и рекомендации.', 'Участники осмотра: ____________________    Дата: ____________________'],
            'writeoff' => ['Акт на списание оборудования', 'Проект перечня оборудования, предлагаемого к списанию. Укажите основание, состояние и заключение комиссии.', 'Члены комиссии: ____________________    Решение согласовал: ____________________'],
            'memo' => ['Служебная записка о неисправности', 'Оборудование, по которому требуется решение. Опишите неисправность и необходимое действие.', 'Составитель: ____________________    Дата: ____________________'],
            'equipment_list' => ['Перечень оборудования', 'Перечень оборудования по состоянию на дату составления документа.', 'Составитель: ____________________    Дата: ____________________'],
        ];
        $result = [];
        foreach ($types as $key => [$name, $introduction, $signature]) {
            $result[$key] = ['key' => $key, 'version' => 1, 'name' => $name, 'introduction' => $introduction, 'signature' => $signature, 'notice' => 'Черновой шаблон Skladex. Форму и реквизиты необходимо согласовать в организации. Фиксация в системе не означает подписание документа.'];
        }

        return $result;
    }

    public function create(User $actor, array $input): GeneratedDocumentRecord
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->rules() + [
            'template_key' => ['required', Rule::in(array_keys($this->templates()))],
            'asset_ids' => 'required|array|min:1|max:100',
            'asset_ids.*' => 'required|string|uuid|distinct:ignore_case',
            'expected_assets' => 'required|array|max:500',
            'expected_assets.*' => 'required|integer|min:1',
        ])->validate();
        $ids = array_map('strtolower', array_values($data['asset_ids']));
        $expected = array_change_key_case($data['expected_assets'], CASE_LOWER);
        foreach ($ids as $id) {
            if (! array_key_exists($id, $expected)) {
                throw ValidationException::withMessages(['expected_assets' => 'Обновите список оборудования перед созданием документа.']);
            }
        }

        return DB::transaction(function () use ($actor, $data, $ids, $expected) {
            Codes::lockPhysicalGraph();
            $assets = Asset::whereIn('uuid', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($asset) => strtolower($asset->uuid));
            abort_unless($assets->count() === count($ids), 409, 'Часть оборудования удалена. Обновите выборку.');
            foreach ($assets as $asset) {
                Version::check($asset, $expected[strtolower($asset->uuid)]);
                abort_if($asset->archived_at && $asset->status !== 'written_off', 409, 'Архивное оборудование нельзя добавить в новый документ.');
            }
            $assets->load('location.building');
            $template = $this->templates()[$data['template_key']];
            $record = GeneratedDocumentRecord::create($this->fields($data) + [
                'template_key' => $template['key'], 'template_version' => $template['version'],
                'template_snapshot' => $template, 'created_by' => $actor->id,
            ]);
            foreach ($ids as $position => $id) {
                $asset = $assets[$id];
                $record->items()->create(['asset_id' => $asset->id, 'position' => $position + 1, 'snapshot' => [
                    'uuid' => $asset->uuid, 'code' => $asset->code, 'name' => $asset->name,
                    'inventory_no' => $asset->inventory_no, 'serial_no' => $asset->serial_no,
                    'manufacturer' => $asset->manufacturer, 'model' => $asset->model,
                    'location_uuid' => $asset->location?->uuid, 'location_code' => $asset->location?->code,
                    'location_name' => $asset->location?->name, 'building_name' => $asset->location?->building?->name,
                    'status' => $asset->status, 'asset_version' => $asset->version,
                ]]);
            }
            Audit::record('generated_document.created', $record, ['template_key' => $template['key'], 'asset_count' => count($ids), 'version' => $record->version]);

            return $record->load('items');
        }, 3);
    }

    public function update(User $actor, GeneratedDocumentRecord $record, array $input): GeneratedDocumentRecord
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->rules() + ['expected_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($record, $data) {
            $current = GeneratedDocumentRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            Version::check($current, $data['expected_version']);
            abort_unless($current->status === 'draft', 409, 'Зафиксированный документ нельзя изменить. Создайте новый документ.');
            $current->fill($this->fields($data));
            $current->version++;
            $current->save();
            Audit::record('generated_document.updated', $current, ['version' => $current->version]);

            return $current->load('items');
        }, 3);
    }

    public function finalize(User $actor, GeneratedDocumentRecord $record, mixed $expected): GeneratedDocumentRecord
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($actor, $record, $expected) {
            $current = GeneratedDocumentRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            Version::check($current, $expected);
            abort_unless($current->status === 'draft', 409, 'Документ уже зафиксирован.');
            $current->status = 'final';
            $current->finalized_by = $actor->id;
            $current->finalized_at = now();
            $current->version++;
            $current->save();
            Audit::record('generated_document.finalized', $current, ['version' => $current->version]);

            return $current->load('items');
        }, 3);
    }

    public function pdf(User $actor, GeneratedDocumentRecord $record): string
    {
        Access::check($actor, 'read');
        $record->load('items');
        $root = storage_path('app/private/document-pdf');
        File::ensureDirectoryExists($root, 0700);
        $workspace = $root.'/job-'.bin2hex(random_bytes(12));
        File::ensureDirectoryExists($workspace.'/fonts', 0700);
        try {
            $fontRoot = realpath(dirname((new \ReflectionClass(Dompdf::class))->getFileName()).'/../lib/fonts');
            if (! $fontRoot) {
                throw new RuntimeException('Local PDF fonts are unavailable.');
            }
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setIsFontSubsettingEnabled(true);
            $options->setAllowedProtocols(['file://']);
            $options->setChroot([$workspace, $fontRoot]);
            $options->setTempDir($workspace);
            $options->setFontCache($workspace.'/fonts');
            $options->setFontDir($fontRoot);
            $options->setDefaultFont('DejaVu Sans');
            $pdf = new Dompdf($options);
            $pdf->setPaper('A4');
            // The fixed Blade view escapes every stored/user value. No user HTML or URLs are parsed.
            $pdf->loadHtml(view('document-records.pdf', ['record' => $record])->render(), 'UTF-8');
            $pdf->render();
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

            return $pdf->output();
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:255', 'document_date' => 'required|date_format:Y-m-d',
            'organization' => 'nullable|string|max:255', 'author_name' => 'nullable|string|max:255',
            'recipient_name' => 'nullable|string|max:255', 'body' => 'required|string|max:12000',
        ];
    }

    private function fields(array $data): array
    {
        return collect($data)->only(['title', 'document_date', 'organization', 'author_name', 'recipient_name', 'body'])->map(fn ($value) => is_string($value) ? trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value) ?? '') : $value)->all();
    }
}
