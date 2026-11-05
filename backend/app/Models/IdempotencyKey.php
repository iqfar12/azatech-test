<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $scope
 * @property string $request_fingerprint
 * @property string $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property string $lock_token
 * @property \Carbon\CarbonImmutable $locked_at
 * @property \Carbon\CarbonImmutable $expires_at
 */
class IdempotencyKey extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'idempotency_key', 'scope', 'request_fingerprint', 'status',
        'response_status', 'response_body', 'lock_token', 'locked_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'locked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
