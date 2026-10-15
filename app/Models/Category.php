<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];

    protected $attributes = ['kind' => 'standard', 'maintenance_policy' => 'unspecified'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
