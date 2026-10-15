<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
