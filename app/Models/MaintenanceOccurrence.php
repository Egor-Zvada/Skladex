<?php

namespace App\Models;

class MaintenanceOccurrence extends OperationRecord
{
    protected $attributes = ['status' => 'pending', 'missed_periods' => 0];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'schedule_snapshot' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }
}
