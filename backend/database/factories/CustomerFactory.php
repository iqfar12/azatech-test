<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    private static ?\Faker\Generator $idFaker = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $faker = self::$idFaker ??= \Faker\Factory::create('id_ID');
        $gender = $faker->randomElement(Gender::cases());

        return [
            'customer_name' => $faker->name($gender === Gender::Male ? 'male' : 'female'),
            'customer_address' => $faker->city(),
            'gender' => $gender,
            'birth_date' => $faker->dateTimeBetween('-60 years', '-17 years')->format('Y-m-d'),
            'phone' => '08'.$faker->numerify('##########'),
            'email' => mb_strtolower($faker->unique()->safeEmail()),
        ];
    }

    public function withoutEmail(): self
    {
        return $this->state(['email' => null]);
    }
}
