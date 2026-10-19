<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectoryConfiguration extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    public $incrementing = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'version' => 'integer'];
    }
}
