<?php

namespace App\Models;

class MaintenanceRequest extends OperationRecord
{
    protected $attributes = ['version' => 1, 'status' => 'new', 'priority' => 'normal'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function softwareInstallation()
    {
        return $this->belongsTo(SoftwareInstallation::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
