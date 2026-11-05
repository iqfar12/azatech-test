<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Idempotency\IdempotencyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('shop:prune-idempotency-keys', function (IdempotencyService $idempotency): void {
    $this->info('Pruned '.$idempotency->pruneExpired().' expired idempotency keys.');
})->purpose('Delete expired idempotency keys');

Artisan::command('shop:seed-if-empty', function (): int {
    if (User::query()->exists()) {
        $this->info('Database already seeded, skipping.');

        return 0;
    }

    return $this->call('db:seed', ['--force' => true]);
})->purpose('Seed demo data on first boot only (used by the Docker entrypoint)');

Schedule::command('shop:prune-idempotency-keys')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
