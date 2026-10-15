<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kit extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(KitMember::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'kit_members')->withTimestamps();
    }
}
