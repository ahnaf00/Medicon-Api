<?php

namespace Database\Factories;

use App\Models\Hospital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hospital>
 */
class HospitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Hospital & Medical Center',
            'address' => $this->faker->streetAddress() . ', Dhaka',
            'latitude' => $this->faker->latitude(23.7, 23.9),
            'longitude' => $this->faker->longitude(90.3, 90.5),
            'emergency_phone' => '+8802' . $this->faker->numerify('#######'),
            'is_24_7' => true,
        ];
    }
}
