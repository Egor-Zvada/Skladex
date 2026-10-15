<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySnapshotItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
