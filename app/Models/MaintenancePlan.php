<?php

namespace App\Models;

class MaintenancePlan extends OperationRecord
{
    protected $attributes = ['version' => 1, 'status' => 'active', 'lead_days' => 7, 'requires_other_acceptor' => false];

    protected function casts(): array
    {
        return ['anchor_on' => 'immutable_date', 'next_due_on' => 'immutable_date', 'last_completed_on' => 'immutable_date', 'checklist_schema' => 'array', 'requires_other_acceptor' => 'boolean'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function softwareInstallation()
    {
        return $this->belongsTo(SoftwareInstallation::class);
    }

    public function occurrences()
    {
        return $this->hasMany(MaintenanceOccurrence::class)->orderBy('sequence');
    }
}
