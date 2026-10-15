<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelProfile extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'paper_width_mm' => '210.00', 'paper_height_mm' => '297.00',
        'gap_x_mm' => '0.00', 'gap_y_mm' => '0.00',
        'margin_left_mm' => '0.00', 'margin_top_mm' => '0.00',
        'offset_x_mm' => '0.00', 'offset_y_mm' => '0.00', 'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'paper_width_mm' => 'decimal:2', 'paper_height_mm' => 'decimal:2',
            'label_width_mm' => 'decimal:2', 'label_height_mm' => 'decimal:2',
            'columns' => 'integer', 'rows' => 'integer', 'gap_x_mm' => 'decimal:2', 'gap_y_mm' => 'decimal:2',
            'margin_left_mm' => 'decimal:2', 'margin_top_mm' => 'decimal:2',
            'offset_x_mm' => 'decimal:2', 'offset_y_mm' => 'decimal:2', 'version' => 'integer',
        ];
    }
}
