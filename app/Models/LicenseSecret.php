<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseSecret extends Model
{
    protected $guarded = [];

    // No encrypted cast: only the separately authorized cipher service may decrypt.
    protected $hidden = ['ciphertext', 'key_id'];

    public function licenseInstance(): BelongsTo
    {
        return $this->belongsTo(LicenseInstance::class);
    }
}
