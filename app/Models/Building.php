<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['notes'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
