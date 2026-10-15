<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseAssignment extends Model
{
    protected $guarded = [];

    protected $hidden = ['active_guard'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function licenseInstance(): BelongsTo
    {
        return $this->belongsTo(LicenseInstance::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
