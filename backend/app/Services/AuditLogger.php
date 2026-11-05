<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Context;

/**
 * Audit trail for sensitive actions (product delete, stock adjustment, order cancel).
 * Written inside the business transaction, so an audit row exists iff the action happened.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(?User $actor, string $action, string $subjectType, string $subjectId, array $payload = []): void
    {
        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'request_id' => Context::get('request_id'),
        ]);
    }
}
