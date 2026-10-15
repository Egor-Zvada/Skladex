<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodeCounter extends Model
{
    protected $guarded = [];

    protected $attributes = ['next_value' => 1];

    protected $primaryKey = 'prefix';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
