<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetComponent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'installed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'child_asset_id');
    }
}
