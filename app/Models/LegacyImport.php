<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class LegacyImport extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime', 'applied_at' => 'datetime', 'source_counts' => 'array', 'result_counts' => 'array', 'metadata' => 'array'];
    }
}
