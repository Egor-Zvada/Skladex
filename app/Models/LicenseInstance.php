<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LicenseInstance extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['metric' => 'device', 'version' => 1];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'expires_on' => 'date', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class, 'software_product_id');
    }

    public function secret(): HasOne
    {
        return $this->hasOne(LicenseSecret::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class)->whereNull('released_at');
    }
}
