<?php

namespace Database\Factories;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorProfile>
 */
class DoctorProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'specialty' => $this->faker->randomElement([
                'General Medicine', 'Cardiology', 'Pediatrics', 'Neurology', 'Dermatology', 'Orthopedics'
            ]),
            'qualification' => $this->faker->randomElement(['MBBS, FCPS', 'MBBS, MD', 'MBBS, MS']),
            'experience_years' => $this->faker->numberBetween(3, 25),
            'consultation_fee' => $this->faker->randomElement([500, 700, 1000, 1200, 1500]),
            'rating' => $this->faker->randomFloat(2, 4.2, 5.0),
            'bio' => $this->faker->paragraph(),
            'verification_status' => 'verified',
        ];
    }
}
