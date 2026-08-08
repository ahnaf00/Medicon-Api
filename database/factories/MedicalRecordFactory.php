<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sys = $this->faker->numberBetween(100, 150);
        $dia = $this->faker->numberBetween(60, 95);
        return [
            'blood_pressure' => $sys . '/' . $dia,
            'pulse_rate' => $this->faker->numberBetween(60, 100),
            'glucose_level' => $this->faker->randomFloat(2, 70, 140),
            'oxygen_saturation' => $this->faker->numberBetween(92, 100),
            'notes' => $this->faker->sentence(),
            'file_url' => null,
        ];
    }
}
