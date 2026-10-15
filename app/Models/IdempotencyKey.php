<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['request_hash', 'response_headers', 'response_body'];

    protected function casts(): array
    {
        return ['response_headers' => 'array', 'response_status' => 'integer', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
