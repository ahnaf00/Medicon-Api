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
        // Test data only. Names say so, and the phone number cannot reach anyone:
        // a random +8802 number could belong to a real person.
        $n = $this->faker->unique()->numberBetween(1, 999);

        return [
            'name' => "Sample Hospital {$n} (test data)",
            'address' => "Test address {$n}, Dhaka",
            'latitude' => $this->faker->latitude(23.7, 23.9),
            'longitude' => $this->faker->longitude(90.3, 90.5),
            'emergency_phone' => '+880 0000-000000',
            'is_24_7' => $this->faker->boolean(),
        ];
    }
}
