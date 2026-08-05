<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionItem>
 */
class PrescriptionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'medicine_name' => $this->faker->randomElement(['Paracetamol 500mg', 'Amoxicillin 250mg', 'Omeprazole 20mg', 'Metformin 500mg']),
            'dosage' => '1 tablet',
            'dosage_schedule' => ['morning' => '08:00', 'night' => '20:00'],
            'instructions' => 'Take after meals',
            'duration_days' => $this->faker->numberBetween(3, 14),
        ];
    }
}
