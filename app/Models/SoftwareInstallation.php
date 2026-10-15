<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoftwareInstallation extends Model
{
    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['installed_on' => 'date', 'last_verified_at' => 'datetime', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(SoftwareRelease::class, 'software_release_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
