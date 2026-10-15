<?php

namespace App\Models;

class InventoryObservation extends OperationRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
