<?php

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $event, Model $subject, array $safeData = []): void
    {
        // Explicit metadata only; secrets, request bodies and model dumps never belong here.
        foreach (['password', 'key', 'license_key', 'secret', 'ciphertext', 'setup_token', 'token'] as $key) {
            unset($safeData[$key]);
        }
        AuditEvent::create(['actor_id' => auth()->id(), 'event' => $event, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(), 'subject_uuid' => $subject->uuid ?? null, 'data' => $safeData, 'created_at' => now()]);
    }
}
