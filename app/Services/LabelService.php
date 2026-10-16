<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\LabelProfile;
use App\Models\Location;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Version;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LabelService
{
    public const MAX_LABELS = 500;

    public const MAX_PAGES = 50;

    public const GEOMETRY_FIELDS = [
        'paper_width_mm', 'paper_height_mm', 'label_width_mm', 'label_height_mm',
        'columns', 'rows', 'gap_x_mm', 'gap_y_mm', 'margin_left_mm', 'margin_top_mm',
        'offset_x_mm', 'offset_y_mm',
    ];

    public function defaults(): array
    {
        return [
            'paper_width_mm' => 210, 'paper_height_mm' => 297,
            'label_width_mm' => 64, 'label_height_mm' => 33, 'columns' => 3, 'rows' => 8,
            'gap_x_mm' => 2, 'gap_y_mm' => 2, 'margin_left_mm' => 7, 'margin_top_mm' => 10,
            'offset_x_mm' => 0, 'offset_y_mm' => 0,
        ];
    }

    /** Validate in hundredths of a millimetre, matching persisted DECIMAL(8,2). */
    public function geometry(array $input): array
    {
        $hundredths = [];
        foreach (array_diff(self::GEOMETRY_FIELDS, ['columns', 'rows']) as $field) {
            $value = $input[$field] ?? null;
            if ((! is_int($value) && ! is_float($value) && ! is_string($value))
                || (is_string($value) && strlen($value) > 32) || ! is_numeric($value)
                || ! is_finite((float) $value)) {
                $this->invalid($field, 'Укажите конечное число в миллиметрах.');
            }
            $number = (float) $value;
            if (abs($number) > 1000 || abs(round($number * 100) - $number * 100) > 0.000001) {
                $this->invalid($field, 'Допустимо не более двух знаков после запятой.');
            }
            $hundredths[$field] = (int) round($number * 100);
        }
        foreach (['paper_width_mm', 'paper_height_mm'] as $field) {
            if ($hundredths[$field] < 5000 || $hundredths[$field] > 42000) {
                $this->invalid($field, 'Каждая сторона листа должна быть от 50 до 420 мм.');
            }
        }
        foreach (['label_width_mm', 'label_height_mm'] as $field) {
            if ($hundredths[$field] <= 0) {
                $this->invalid($field, 'Размер этикетки должен быть больше нуля.');
            }
        }
        foreach (['gap_x_mm', 'gap_y_mm', 'margin_left_mm', 'margin_top_mm'] as $field) {
            if ($hundredths[$field] < 0) {
                $this->invalid($field, 'Зазор или поле не может быть отрицательным.');
            }
        }
        $grid = [];
        foreach (['columns', 'rows'] as $field) {
            $value = $input[$field] ?? null;
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,2}$/D', (string) $value) || (int) $value > 500) {
                $this->invalid($field, 'Укажите целое число от 1 до 500.');
            }
            $grid[$field] = (int) $value;
        }
        if ($grid['columns'] * $grid['rows'] > 500) {
            $this->invalid('rows', 'На листе может быть не более 500 ячеек.');
        }
        foreach ([['x', 'columns', 'label_width_mm', 'margin_left_mm', 'paper_width_mm'], ['y', 'rows', 'label_height_mm', 'margin_top_mm', 'paper_height_mm']] as [$axis, $count, $size, $margin, $paper]) {
            $start = $hundredths[$margin] + $hundredths['offset_'.$axis.'_mm'];
            $end = $start + $grid[$count] * $hundredths[$size] + ($grid[$count] - 1) * $hundredths['gap_'.$axis.'_mm'];
            if ($start < 0 || $end > $hundredths[$paper]) {
                $this->invalid('offset_'.$axis.'_mm', 'Сетка с учётом полей и смещения выходит за границы листа по оси '.strtoupper($axis).'.');
            }
        }

        return array_merge(array_map(fn ($value) => $value / 100, $hundredths), $grid);
    }

    /** One-based start cell; subsequent pages always begin at cell 1, row first. */
    public function layout(array $geometry, int $count, int $startCell = 1): array
    {
        $g = $this->geometry($geometry);
        $capacity = $g['columns'] * $g['rows'];
        if ($count < 1 || $count > self::MAX_LABELS) {
            $this->invalid('target_ids', 'Выберите от 1 до 500 объектов.');
        }
        if ($startCell < 1 || $startCell > $capacity) {
            $this->invalid('start_cell', 'Начальная ячейка должна быть от 1 до '.$capacity.'.');
        }
        $pageCount = (int) ceil(($startCell - 1 + $count) / $capacity);
        if ($pageCount > self::MAX_PAGES) {
            $this->invalid('target_ids', 'Пачка превышает 50 листов. Уменьшите выборку или измените сетку.');
        }
        $pages = array_fill(0, $pageCount, []);
        for ($index = 0; $index < $count; $index++) {
            $absolute = $startCell - 1 + $index;
            $page = intdiv($absolute, $capacity);
            $position = $absolute % $capacity;
            $column = $position % $g['columns'];
            $row = intdiv($position, $g['columns']);
            $pages[$page][] = [
                'index' => $index, 'cell' => $position + 1, 'row' => $row + 1, 'column' => $column + 1,
                'x_mm' => round($g['margin_left_mm'] + $g['offset_x_mm'] + $column * ($g['label_width_mm'] + $g['gap_x_mm']), 2),
                'y_mm' => round($g['margin_top_mm'] + $g['offset_y_mm'] + $row * ($g['label_height_mm'] + $g['gap_y_mm']), 2),
                'width_mm' => $g['label_width_mm'], 'height_mm' => $g['label_height_mm'],
            ];
        }

        return ['geometry' => $g, 'capacity' => $capacity, 'pages_count' => $pageCount, 'pages' => $pages];
    }

    /** An empty editor still has one sheet; printable batches remain strictly nonempty. */
    public function editorLayout(array $geometry, int $count, int $startCell = 1): array
    {
        if ($count !== 0) {
            return $this->layout($geometry, $count, $startCell);
        }
        $layout = $this->layout($geometry, 1, $startCell);
        $layout['pages'] = [[]];

        return $layout;
    }

    public function saveProfile(User $actor, array $input): LabelProfile
    {
        Access::check($actor, 'write');
        $valid = Validator::make($input, [
            'name' => 'required|string|max:100', 'profile_id' => 'nullable|integer|min:1',
            'expected_version' => 'required_with:profile_id|integer|min:1',
        ])->validate();
        $geometry = $this->geometry($input);

        return DB::transaction(function () use ($valid, $geometry) {
            $profile = ! empty($valid['profile_id']) ? LabelProfile::whereKey($valid['profile_id'])->lockForUpdate()->firstOrFail() : new LabelProfile;
            $updating = $profile->exists;
            if ($updating) {
                Version::check($profile, $valid['expected_version']);
            }
            $profile->fill($geometry + ['name' => trim($valid['name'])]);
            if ($updating) {
                $profile->version++;
            }
            $profile->save();
            Audit::record($updating ? 'labels.profile.updated' : 'labels.profile.created', $profile, ['name' => $profile->name, 'geometry' => $geometry, 'version' => $profile->version]);

            return $profile;
        });
    }

    /** Resolve UUIDs in the requested domain only; preserve the submitted order. */
    public function targets(User $actor, mixed $type, mixed $ids): array
    {
        Access::check($actor, 'read');
        if (! in_array($type, ['asset', 'location'], true)) {
            $this->invalid('target_type', 'Выберите оборудование или кабинеты.');
        }
        if (! is_array($ids) || count($ids) < 1 || count($ids) > self::MAX_LABELS) {
            $this->invalid('target_ids', 'Выберите от 1 до 500 объектов.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                $this->invalid('target_ids', 'Выборка должна содержать UUID объектов.');
            }
        }
        $ids = array_map('strtolower', array_values($ids));
        if (count(array_unique($ids)) !== count($ids)) {
            $this->invalid('target_ids', 'Один объект выбран несколько раз.');
        }
        $model = $type === 'asset' ? Asset::class : Location::class;
        $models = $model::whereIn('uuid', $ids)->whereNull('archived_at')->get()->keyBy(fn ($item) => strtolower($item->uuid));
        if ($models->count() !== count($ids)) {
            $this->invalid('target_ids', 'Часть объектов не найдена или находится в архиве. Обновите выборку.');
        }
        $resolver = app(QrResolver::class);

        return array_map(function ($id) use ($models, $resolver, $type) {
            $item = $models[$id];

            return ['uuid' => $item->uuid, 'type' => $type, 'code' => $item->code, 'name' => $item->name, 'qr_url' => $resolver->url($item)];
        }, $ids);
    }

    public function qrPng(string $url): string
    {
        // The URL is encoded as text. No HTTP client or remote image is involved.
        return (new PngWriter)->write(new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 400, margin: 80))->getString();
    }

    /** Shared content boxes for the screen preview and the PDF canvas. */
    public function contentBox(array $cell): array
    {
        $w = $cell['width_mm'];
        $h = $cell['height_mm'];
        $padding = min(2, $w * 0.06, $h * 0.06);
        $horizontal = $w >= $h + 18;
        $size = min(28, $w - 2 * $padding, $h - 2 * $padding - ($horizontal || $h < 15 ? 0 : 7));
        $size = max(0.000001, $size);

        return [
            'qr_x_mm' => $horizontal ? $padding : ($w - $size) / 2,
            'qr_y_mm' => $horizontal ? ($h - $size) / 2 : $padding,
            'qr_size_mm' => $size,
            'text_x_mm' => $horizontal ? 2 * $padding + $size : $padding,
            'text_y_mm' => $horizontal ? max($padding, ($h - 18) / 2) : $padding + $size + 0.6,
            'text_width_mm' => max(0, $horizontal ? $w - $size - 3 * $padding : $w - 2 * $padding),
            'horizontal' => $horizontal, 'show_text' => $horizontal || $h >= 15,
        ];
    }

    public function pdf(array $geometry, array $targets, int $startCell = 1): string
    {
        $layout = $this->layout($geometry, count($targets), $startCell);

        return $this->withPdf($layout['geometry'], function (Dompdf $pdf, string $workspace) use ($layout, $targets) {
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            if (! $font) {
                throw new RuntimeException('Local PDF font is unavailable.');
            }
            foreach ($layout['pages'] as $pageIndex => $cells) {
                if ($pageIndex > 0) {
                    $canvas->new_page();
                }
                foreach ($cells as $cell) {
                    $target = $targets[$cell['index']];
                    $path = $workspace.'/qr-'.$cell['index'].'.png';
                    if (file_put_contents($path, $this->qrPng($target['qr_url'])) === false) {
                        throw new RuntimeException('Cannot write a local QR image.');
                    }
                    $box = $this->contentBox($cell);
                    $x = $cell['x_mm'];
                    $y = $cell['y_mm'];
                    $canvas->clipping_rectangle($this->pt($x), $this->pt($y), $this->pt($cell['width_mm']), $this->pt($cell['height_mm']));
                    $canvas->image($path, $this->pt($x + $box['qr_x_mm']), $this->pt($y + $box['qr_y_mm']), $this->pt($box['qr_size_mm']), $this->pt($box['qr_size_mm']));
                    if ($box['show_text']) {
                        $tx = $this->pt($x + $box['text_x_mm']);
                        $ty = $this->pt($y + $box['text_y_mm']);
                        $width = $this->pt($box['text_width_mm']);
                        $canvas->text($tx, $ty, $this->fitText($pdf, $target['code'], $font, 7, $width), $font, 7);
                        $lines = $this->wrapText($pdf, $target['name'], $font, 7, $width, $box['horizontal'] ? 3 : 1);
                        foreach ($lines as $line => $text) {
                            $canvas->text($tx, $ty + 11 + $line * 9, $text, $font, 7);
                        }
                    }
                    $canvas->clipping_end();
                }
            }

            return $pdf->output();
        });
    }

    public function testPdf(array $geometry): string
    {
        $g = $this->geometry($geometry);
        $layout = $this->layout($g, $g['columns'] * $g['rows']);

        return $this->withPdf($g, function (Dompdf $pdf) use ($layout, $g) {
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            foreach ($layout['pages'][0] as $cell) {
                $canvas->rectangle($this->pt($cell['x_mm']), $this->pt($cell['y_mm']), $this->pt($cell['width_mm']), $this->pt($cell['height_mm']), [0.55, 0.55, 0.55], 0.3);
                $canvas->text($this->pt($cell['x_mm'] + 1), $this->pt($cell['y_mm'] + 1), (string) $cell['cell'], $font, 6);
            }
            $canvas->text($this->pt(3), $this->pt(2), 'Skladex · 100% / Actual size', $font, 7);
            // The control line is always exactly 100 mm; small stock never scales it down.
            if ($g['paper_width_mm'] >= 120) {
                $y = $g['paper_height_mm'] - 10;
                $canvas->line($this->pt(10), $this->pt($y), $this->pt(110), $this->pt($y), [0, 0, 0], 0.7);
                foreach ([10, 110] as $x) {
                    $canvas->line($this->pt($x), $this->pt($y - 2), $this->pt($x), $this->pt($y + 2), [0, 0, 0], 0.7);
                }
                $canvas->text($this->pt(10), $this->pt($y + 3), '100 мм', $font, 8);
            }
            if ($g['paper_height_mm'] >= 120) {
                $x = $g['paper_width_mm'] - 5;
                $canvas->line($this->pt($x), $this->pt(10), $this->pt($x), $this->pt(110), [0, 0, 0], 0.7);
                foreach ([10, 110] as $y) {
                    $canvas->line($this->pt($x - 2), $this->pt($y), $this->pt($x + 2), $this->pt($y), [0, 0, 0], 0.7);
                }
            }
            if ($g['paper_width_mm'] < 120 && $g['paper_height_mm'] < 120) {
                $canvas->text($this->pt(3), $this->pt($g['paper_height_mm'] - 6), 'Лист мал для линии 100 мм', $font, 6);
            }

            return $pdf->output();
        });
    }

    public function pt(float $millimetres): float
    {
        return $millimetres * 72 / 25.4;
    }

    private function withPdf(array $geometry, callable $render): string
    {
        $root = storage_path('app/private/label-pdf');
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
            $options->setPdfBackend('CPDF');
            $pdf = new Dompdf($options);
            // No supplied HTML, CSS, image URL, path, script or font is parsed.
            $pdf->setPaper([0, 0, $this->pt($geometry['paper_width_mm']), $this->pt($geometry['paper_height_mm'])]);
            $pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:0}body{margin:0}</style></head><body></body></html>', 'UTF-8');
            $pdf->render();

            return $render($pdf, $workspace);
        } finally {
            // A random directory created above, never a user-provided path.
            File::deleteDirectory($workspace);
        }
    }

    private function fitText(Dompdf $pdf, string $text, string $font, float $size, float $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $text = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text) ?? '';
        if ($pdf->getFontMetrics()->getTextWidth($text, $font, $size) <= $width) {
            return $text;
        }
        while ($text !== '' && $pdf->getFontMetrics()->getTextWidth($text.'…', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $text === '' ? '' : $text.'…';
    }

    private function wrapText(Dompdf $pdf, string $text, string $font, float $size, float $width, int $maxLines): array
    {
        if ($maxLines === 1) {
            return [$this->fitText($pdf, $text, $font, $size, $width)];
        }
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $index => $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && $pdf->getFontMetrics()->getTextWidth($candidate, $font, $size) > $width) {
                $lines[] = $this->fitText($pdf, $current, $font, $size, $width);
                $current = $word;
                if (count($lines) >= $maxLines - 1) {
                    $current = implode(' ', array_slice($words, $index));
                    break;
                }
            } else {
                $current = $candidate;
            }
        }
        if (count($lines) < $maxLines) {
            $lines[] = $this->fitText($pdf, $current, $font, $size, $width);
        }

        return array_slice($lines, 0, $maxLines);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
