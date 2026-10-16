<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
