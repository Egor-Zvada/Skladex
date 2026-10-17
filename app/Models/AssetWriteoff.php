<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AssetWriteoff extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['snapshot', 'purge_backup_path', 'purge_backup_sha256'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'written_off_at' => 'datetime', 'restored_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    public function writtenOffBy()
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }
}
