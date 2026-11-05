<?php

declare(strict_types=1);

namespace App\Services\Idempotency;

use App\Exceptions\IdempotencyException;
use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Idempotency for non-idempotent POSTs (order creation).
 *
 * Lifecycle of a key (scoped by user + endpoint):
 *
 *   (none) ──claim──▶ processing ──action committed──▶ completed ──TTL──▶ (pruned)
 *                         │
 *                         └─ action failed (validation / stock / crash) → row deleted,
 *                            the key may be retried because nothing was persisted.
 *
 *  - The claim is a single INSERT ... ON CONFLICT DO NOTHING RETURNING id, committed
 *    on its own, so a concurrent duplicate request immediately sees "processing" → 409.
 *  - The "completed" marker + stored response are written INSIDE the business
 *    transaction: the order and its idempotency record commit (or roll back) together.
 *  - lock_token is a fencing token: a request whose processing record was taken over
 *    after the timeout cannot overwrite or delete the new owner's record.
 */
final class IdempotencyService
{
    /**
     * @param  Closure(): array{status: int, body: array<string, mixed>}  $action
     */
    public function execute(int $userId, string $scope, string $key, string $fingerprint, Closure $action): IdempotentResult
    {
        $claim = $this->claim($userId, $scope, $key, $fingerprint);

        if ($claim instanceof IdempotentResult) {
            return $claim;
        }

        try {
            return DB::transaction(function () use ($claim, $action): IdempotentResult {
                $result = $action();

                $updated = IdempotencyKey::query()
                    ->whereKey($claim['id'])
                    ->where('lock_token', $claim['token'])
                    ->where('status', IdempotencyKey::STATUS_PROCESSING)
                    ->update([
                        'status' => IdempotencyKey::STATUS_COMPLETED,
                        'response_status' => $result['status'],
                        'response_body' => json_encode($result['body'], JSON_THROW_ON_ERROR),
                        'updated_at' => CarbonImmutable::now(),
                    ]);

                if ($updated !== 1) {
                    // Our claim was taken over (we exceeded the processing timeout). Roll back our work.
                    throw IdempotencyException::inProgress();
                }

                return new IdempotentResult($result['status'], $result['body'], replayed: false);
            }, 3);
        } catch (Throwable $e) {
            // Nothing was committed, so the key is released and the client may retry with it.
            IdempotencyKey::query()
                ->whereKey($claim['id'])
                ->where('lock_token', $claim['token'])
                ->where('status', IdempotencyKey::STATUS_PROCESSING)
                ->delete();

            throw $e;
        }
    }

    /**
     * Deletes expired keys. Scheduled hourly (routes/console.php).
     */
    public function pruneExpired(): int
    {
        return IdempotencyKey::query()->where('expires_at', '<', CarbonImmutable::now())->delete();
    }

    /**
     * @return array{id: int, token: string}|IdempotentResult
     */
    private function claim(int $userId, string $scope, string $key, string $fingerprint): array|IdempotentResult
    {
        // Two attempts: the second one covers "the existing row expired / was released in between".
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now = CarbonImmutable::now();
            $token = (string) Str::uuid();

            $row = DB::selectOne(
                <<<'SQL'
                    INSERT INTO idempotency_keys
                        (user_id, scope, idempotency_key, request_fingerprint, status, lock_token, locked_at, expires_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (user_id, scope, idempotency_key) DO NOTHING
                    RETURNING id
                SQL,
                [
                    $userId, $scope, $key, $fingerprint, IdempotencyKey::STATUS_PROCESSING, $token,
                    $now, $now->addMinutes((int) config('shop.idempotency.ttl_minutes')), $now, $now,
                ],
            );

            if ($row !== null) {
                return ['id' => (int) $row->id, 'token' => $token];
            }

            $existing = IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                continue; // released by a failed request a moment ago → try to claim again
            }

            if ($existing->expires_at->isPast()) {
                IdempotencyKey::query()->whereKey($existing->id)->where('expires_at', '<', $now)->delete();

                continue;
            }

            if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                throw IdempotencyException::payloadMismatch();
            }

            if ($existing->status === IdempotencyKey::STATUS_COMPLETED) {
                return new IdempotentResult(
                    (int) $existing->response_status,
                    $existing->response_body ?? [],
                    replayed: true,
                );
            }

            // Still processing. Take over only if the owner looks dead (crash between claim and commit).
            $timeout = (int) config('shop.idempotency.processing_timeout_seconds');
            if ($existing->locked_at->lt($now->subSeconds($timeout))) {
                $newToken = (string) Str::uuid();
                $taken = IdempotencyKey::query()
                    ->whereKey($existing->id)
                    ->where('status', IdempotencyKey::STATUS_PROCESSING)
                    ->where('lock_token', $existing->lock_token)
                    ->update(['lock_token' => $newToken, 'locked_at' => $now, 'updated_at' => $now]);

                if ($taken === 1) {
                    return ['id' => $existing->id, 'token' => $newToken];
                }
            }

            throw IdempotencyException::inProgress();
        }

        throw IdempotencyException::inProgress();
    }
}
