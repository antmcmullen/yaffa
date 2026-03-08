<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PiggyBankFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $targetAmount = $this->faker->numberBetween(100000, 1000000);
        $currentAmount = $this->faker->numberBetween(0, $targetAmount);

        return [
            'name' => $this->faker->unique()->words(3, true),
            'target_amount' => $targetAmount,
            'current_amount' => $currentAmount,
            'start_date' => $this->faker->optional()->date(),
            'target_date' => $this->faker->optional()->dateTimeBetween('now', '+5 years')?->format('Y-m-d'),
            'notes' => $this->faker->optional()->sentence(),
            'active' => true,
            'user_id' => User::inRandomOrder()->first()->getAttribute('id'),
        ];
    }
}
