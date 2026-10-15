<?php

namespace App\Models;

class InventoryReport extends OperationRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
