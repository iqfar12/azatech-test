<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

final class AuthService
{
    /**
     * Pre-computed bcrypt hash used when the e-mail does not exist, so that response
     * time does not reveal whether an account exists (user enumeration).
     */
    private const DUMMY_HASH = '$2y$12$apR6.W18c6EQucDBiNDXquTP1QoIA8YKYF6bkbIVaYHCETOspVaz2';

    /**
     * @return array{user: User, token: string, expires_at: CarbonImmutable}
     */
    public function login(string $email, string $password, string $deviceName): array
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        $valid = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if ($user === null || ! $valid) {
            Log::warning('auth.login_failed', ['email_hash' => hash('sha256', mb_strtolower($email))]);

            throw new BusinessRuleException('Email atau password salah', 401);
        }

        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('shop.auth.token_ttl_minutes'));

        // Abilities mirror the role; authorization itself is done by Policies.
        $token = $user->createToken($deviceName, [$user->role->value], $expiresAt);

        Log::info('auth.login', ['user_id' => $user->id, 'role' => $user->role->value]);

        return ['user' => $user, 'token' => $token->plainTextToken, 'expires_at' => $expiresAt];
    }

    public function logout(User $user): void
    {
        // Revoke only the token used for this request (other devices stay logged in).
        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        Log::info('auth.logout', ['user_id' => $user->id]);
    }
}
