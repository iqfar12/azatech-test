<?php

declare(strict_types=1);

namespace App\Exceptions;

final class IdempotencyException extends BusinessRuleException
{
    /**
     * Same key, different payload → 422 Unprocessable Content
     * (as recommended by the IETF draft "The Idempotency-Key HTTP Header Field").
     * It is a client bug, not a state conflict: retrying will never succeed.
     */
    public static function payloadMismatch(): self
    {
        return new self(
            'Idempotency-Key sudah dipakai untuk request dengan payload berbeda',
            422,
            ['Idempotency-Key' => ['Gunakan key baru untuk payload yang berbeda.']],
        );
    }

    /**
     * First request with this key is still running → 409 Conflict + Retry-After.
     * Retrying shortly will return the stored response.
     */
    public static function inProgress(): self
    {
        return new self(
            'Request dengan Idempotency-Key ini masih diproses',
            409,
            ['Idempotency-Key' => ['Request sebelumnya belum selesai, coba lagi sebentar lagi.']],
            ['Retry-After' => '1'],
        );
    }

    public static function missingOrInvalid(): self
    {
        return new self(
            'Header Idempotency-Key wajib berupa UUID',
            422,
            ['Idempotency-Key' => ['Header Idempotency-Key wajib diisi dengan UUID yang valid.']],
        );
    }
}
