<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\LabelProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            // Only system dictionaries. No users, credentials, rooms, assets or legacy data.
            $categories = [
                ['computers', 'Компьютеры', 'it', 'computer'],
                ['servers', 'Серверы', 'it', 'standard'],
                ['network', 'Сетевое оборудование', 'it', 'standard'],
                ['peripherals', 'Периферийное оборудование', 'it', 'standard'],
                ['racks', 'Серверные и коммутационные шкафы', 'it', 'rack'],
                ['components', 'Комплектующие ИТ', 'it', 'standard'],
                ['other-it', 'Другое ИТ-оборудование', 'it', 'standard'],
                ['microphones', 'Микрофоны', 'audio', 'standard'],
                ['mixers', 'Микшерные пульты', 'audio', 'standard'],
                ['amplifiers', 'Усилители', 'audio', 'standard'],
                ['loudspeakers', 'Акустические системы', 'audio', 'standard'],
                ['audio-interfaces', 'Аудиоинтерфейсы', 'audio', 'standard'],
                ['other-audio', 'Другое звуковое оборудование', 'audio', 'standard'],
            ];

            foreach ($categories as [$slug, $name, $domain, $kind]) {
                Category::firstOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'domain' => $domain,
                    'kind' => $kind,
                    'maintenance_policy' => 'unspecified',
                ]);
            }

            // A starting layout, not a claim that a physical printer has been calibrated.
            LabelProfile::firstOrCreate(['name' => 'Базовый A4: 45 × 25 мм, 4 × 10'], [
                'paper_width_mm' => '210.00',
                'paper_height_mm' => '297.00',
                'label_width_mm' => '45.00',
                'label_height_mm' => '25.00',
                'columns' => 4,
                'rows' => 10,
                'gap_x_mm' => '3.00',
                'gap_y_mm' => '2.00',
                'margin_left_mm' => '10.00',
                'margin_top_mm' => '10.00',
                'offset_x_mm' => '0.00',
                'offset_y_mm' => '0.00',
                'version' => 1,
            ]);
        });
    }
}
