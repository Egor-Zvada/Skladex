<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitMember extends Model
{
    protected $guarded = [];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
