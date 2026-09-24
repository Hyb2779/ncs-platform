<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    public function write(?User $actor, string $action, ?User $target = null, array $payload = []): void
    {
        ActivityLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $target ? $target->getMorphClass() : null,
            'target_id' => $target?->id,
            'ip' => Request::ip(),
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
