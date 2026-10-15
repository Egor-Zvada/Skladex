<?php

namespace App\Models;

class InventoryCampaign extends OperationRecord
{
    protected $attributes = ['version' => 1, 'status' => 'draft'];

    protected function casts(): array
    {
        return ['scope' => 'array', 'started_at' => 'immutable_datetime', 'reconciliation_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function snapshotItems()
    {
        return $this->hasMany(InventorySnapshotItem::class);
    }

    public function observations()
    {
        return $this->hasMany(InventoryObservation::class);
    }

    public function resolutions()
    {
        return $this->hasMany(InventoryResolution::class);
    }

    public function reports()
    {
        return $this->hasMany(InventoryReport::class);
    }
}
