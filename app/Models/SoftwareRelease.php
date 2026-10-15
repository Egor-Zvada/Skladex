<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareRelease extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_on' => 'date', 'supported_until' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(SoftwareInstallation::class);
    }
}
