<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\LabelService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LabelTest extends TestCase
{
    public function test_row_major_start_cell_applies_only_to_first_page(): void
    {
        $service = new LabelService;
        $layout = $service->layout($service->defaults(), 30, 5);
        $this->assertSame([20, 10], array_map('count', $layout['pages']));
        $this->assertSame(5, $layout['pages'][0][0]['cell']);
        $this->assertSame(2, $layout['pages'][0][0]['row']);
        $this->assertSame(2, $layout['pages'][0][0]['column']);
        $this->assertEquals(73, $layout['pages'][0][0]['x_mm']);
        $this->assertEquals(45, $layout['pages'][0][0]['y_mm']);
        $this->assertSame(1, $layout['pages'][1][0]['cell']);
        $this->assertSame(20, $layout['pages'][1][0]['index']);
        $this->assertEquals(7, $layout['pages'][1][0]['x_mm']);
        $this->assertEquals(10, $layout['pages'][1][0]['y_mm']);
    }

    public function test_signed_printer_offsets_change_both_axes_without_changing_pitch(): void
    {
        $service = new LabelService;
        $g = array_replace($service->defaults(), ['offset_x_mm' => -7, 'offset_y_mm' => 2.25]);
        $layout = $service->layout($g, 4);
        $this->assertEquals(0, $layout['pages'][0][0]['x_mm']);
        $this->assertEquals(12.25, $layout['pages'][0][0]['y_mm']);
        $this->assertEquals(66, $layout['pages'][0][1]['x_mm']);
        $this->assertEquals(47.25, $layout['pages'][0][3]['y_mm']);
        $this->assertEqualsWithDelta(283.4645669291, $service->pt(100), 0.0000001);
    }

    #[DataProvider('invalidGeometry')]
    public function test_invalid_geometry_is_rejected(string $field, mixed $value): void
    {
        $service = new LabelService;
        $this->expectException(ValidationException::class);
        $service->geometry(array_replace($service->defaults(), [$field => $value]));
    }

    public static function invalidGeometry(): array
    {
        return [
            'zero width' => ['label_width_mm', 0], 'negative height' => ['label_height_mm', -1],
            'negative gap' => ['gap_x_mm', -0.01], 'negative top' => ['margin_top_mm', -1],
            'too wide' => ['label_width_mm', 100], 'too tall' => ['label_height_mm', 100],
            'nan' => ['label_width_mm', NAN], 'infinite' => ['offset_x_mm', INF],
            'string nan' => ['label_height_mm', 'NaN'], 'array' => ['gap_y_mm', []],
            'excess precision' => ['offset_x_mm', 0.001], 'wrong paper' => ['paper_height_mm', 300],
            'too many columns' => ['columns', 501], 'zero rows' => ['rows', 0],
            'fractional rows' => ['rows', 1.5], 'over 500 cells' => ['rows', 200],
            'left off page' => ['offset_x_mm', -7.01], 'right off page' => ['offset_x_mm', 7.01],
            'above page' => ['offset_y_mm', -10.01], 'below page' => ['offset_y_mm', 9.01],
        ];
    }

    #[DataProvider('invalidBatch')]
    public function test_invalid_counts_start_cells_and_page_limits_are_rejected(int $count, int $start, bool $singleCell): void
    {
        $service = new LabelService;
        $g = $service->defaults();
        if ($singleCell) {
            $g = array_replace($g, ['columns' => 1, 'rows' => 1]);
        }
        $this->expectException(ValidationException::class);
        $service->layout($g, $count, $start);
    }

    public static function invalidBatch(): array
    {
        return [[0, 1, false], [501, 1, false], [1, 0, false], [1, 25, false], [51, 1, true]];
    }

    public function test_unknown_target_type_is_rejected_before_any_database_lookup(): void
    {
        $actor = new User(['name' => 'Test', 'role' => 'operator', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        (new LabelService)->targets($actor, 'license', ['00000000-0000-4000-8000-000000000001']);
    }

    public function test_no_caller_supplied_url_is_accepted_as_a_target_id(): void
    {
        $actor = new User(['name' => 'Test', 'role' => 'operator', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        (new LabelService)->targets($actor, 'asset', ['https://example.invalid/image.png']);
    }

    public function test_pdf_and_calibration_sheet_are_local_a4_documents_without_a_database(): void
    {
        $service = new LabelService;
        $before = glob(storage_path('app/private/label-pdf/job-*')) ?: [];
        $uuid = '00000000-0000-4000-8000-000000000001';
        $targets = [['type' => 'asset', 'uuid' => $uuid, 'name' => 'Синтетическая проверка: микрофон ИТ', 'code' => 'SKX-A-QA', 'qr_url' => 'https://skladex.invalid/p/a/'.$uuid]];
        $pdf = $service->pdf($service->defaults(), $targets, 5);
        $test = $service->testPdf($service->defaults());
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringStartsWith('%PDF-', $test);
        $this->assertMatchesRegularExpression('~/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+595\.2[0-9]+\s+841\.8[0-9]+\s*\]~', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertStringNotContainsString('/JavaScript', $pdf);
        $this->assertStringNotContainsString('/URI', $pdf);
        $this->assertSame($before, glob(storage_path('app/private/label-pdf/job-*')) ?: []);
    }
}
