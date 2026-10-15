<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalPlacement extends Model
{
    protected $guarded = [];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function childAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }
}
