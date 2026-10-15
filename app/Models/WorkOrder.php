<?php

namespace App\Models;

class WorkOrder extends OperationRecord
{
    protected $attributes = ['version' => 1, 'status' => 'open', 'kind' => 'repair', 'priority' => 'normal', 'attempt' => 1, 'requires_other_acceptor' => false, 'counts_for_plan' => false];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'checklist_schema' => 'array', 'checklist_answers' => 'array', 'materials' => 'array', 'completed_at' => 'immutable_datetime', 'result_recorded_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'counts_for_plan' => 'boolean', 'requires_other_acceptor' => 'boolean', 'service_cost' => 'decimal:2'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function softwareInstallation()
    {
        return $this->belongsTo(SoftwareInstallation::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function occurrence()
    {
        return $this->belongsTo(MaintenanceOccurrence::class, 'maintenance_occurrence_id');
    }

    public function events()
    {
        return $this->hasMany(WorkOrderEvent::class)->orderBy('id');
    }

    public function getSubjectNameAttribute(): string
    {
        return $this->asset?->name ?? $this->softwareInstallation?->service_name ?? 'Установка ПО #'.$this->software_installation_id;
    }
}
