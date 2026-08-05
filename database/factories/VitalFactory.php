<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vital>
 */
class VitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sys = $this->faker->numberBetween(110, 135);
        $dia = $this->faker->numberBetween(70, 88);
        return [
            'user_id' => User::factory(),
            'blood_pressure' => "{$sys}/{$dia}",
            'pulse_rate' => $this->faker->numberBetween(65, 95),
            'glucose_level' => $this->faker->randomFloat(2, 4.5, 7.8),
            'oxygen_saturation' => $this->faker->numberBetween(96, 99),
            'logged_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ];
    }
}
