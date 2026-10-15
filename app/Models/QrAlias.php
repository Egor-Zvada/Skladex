<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrAlias extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_id' => 'integer', 'created_at' => 'datetime'];
    }

    // target_id is resolved only together with target_type by the QR resolver.
}
