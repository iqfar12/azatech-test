<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            // Hash once per test run instead of once per user (bcrypt is slow on purpose).
            'password' => self::$password ??= bcrypt('password'),
            'role' => UserRole::Cashier,
        ];
    }

    public function admin(): self
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function cashier(): self
    {
        return $this->state(['role' => UserRole::Cashier]);
    }
}
