<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_user_id' => User::factory(),
            'doctor_user_id' => User::factory(),
            'diagnosis_summary' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['active', 'completed', 'cancelled']),
        ];
    }
}
