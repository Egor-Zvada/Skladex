<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class OperationRecord extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (Model $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
