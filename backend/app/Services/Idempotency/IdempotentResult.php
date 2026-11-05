<?php

declare(strict_types=1);

namespace App\Services\Idempotency;

final readonly class IdempotentResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {
    }
}
