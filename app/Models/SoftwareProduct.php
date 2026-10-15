<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareProduct extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['kind' => 'third_party', 'version' => 1];

    protected $hidden = ['repository_url'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(SoftwareRelease::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(SoftwareInstallation::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(LicenseInstance::class);
    }
}
