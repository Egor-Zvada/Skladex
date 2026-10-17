<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Asset extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'stock', 'condition' => 'unknown', 'maintenance_policy' => 'unspecified', 'version' => 1,
    ];

    // Public responses still require an explicit allowlist resource.
    protected $hidden = ['private_notes', 'specifications'];

    protected static function booted(): void
    {
        static::creating(function (Asset $asset): void {
            $reserved = DB::table('retired_asset_codes')->where('code', $asset->code)->orWhere('asset_uuid', $asset->uuid)
                ->when($asset->id !== null, fn ($q) => $q->orWhere('former_asset_id', $asset->id))->exists();
            abort_if($reserved, 409, 'Код или идентификатор удалённого предмета навсегда зарезервирован для его истории.');
        });
    }

    protected function casts(): array
    {
        return [
            'specifications' => 'array', 'purchased_on' => 'date', 'warranty_until' => 'date',
            'version' => 'integer', 'archived_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssetComponent::class, 'parent_asset_id')->whereNull('removed_at');
    }

    public function componentHistory(): HasMany
    {
        return $this->hasMany(AssetComponent::class, 'parent_asset_id');
    }

    public function physicalPlacement(): HasOne
    {
        return $this->hasOne(PhysicalPlacement::class, 'asset_id');
    }

    public function containedPlacements(): HasMany
    {
        return $this->hasMany(PhysicalPlacement::class, 'parent_asset_id');
    }

    public function kits(): BelongsToMany
    {
        return $this->belongsToMany(Kit::class, 'kit_members')->withTimestamps();
    }

    public function softwareInstallations(): HasMany
    {
        return $this->hasMany(SoftwareInstallation::class);
    }

    public function licenseAssignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class);
    }

    public function activeLicenseAssignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class)->whereNull('released_at');
    }

    public function qrAliases(): HasMany
    {
        return $this->hasMany(QrAlias::class, 'target_id')->where('target_type', 'asset');
    }
}
