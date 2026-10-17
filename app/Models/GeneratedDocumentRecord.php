<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedDocumentRecord extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['template_snapshot' => 'array', 'document_date' => 'date', 'finalized_at' => 'datetime', 'version' => 'integer', 'template_version' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(GeneratedDocumentItem::class, 'document_record_id')->orderBy('position');
    }

    public function getNumberAttribute(): string
    {
        return 'DOC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
