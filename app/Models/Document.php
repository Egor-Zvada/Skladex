<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $hidden = ['path', 'sha256'];
}
