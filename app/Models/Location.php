<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['is_public' => true, 'version' => 1];

    protected $hidden = ['notes'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function qrAliases(): HasMany
    {
        return $this->hasMany(QrAlias::class, 'target_id')->where('target_type', 'location');
    }
}
