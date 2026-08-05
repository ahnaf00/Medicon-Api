<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'appointment_datetime' => $this->faker->dateTimeBetween('now', '+30 days'),
            'format' => $this->faker->randomElement(['video', 'in_person']),
            'status' => $this->faker->randomElement(['scheduled', 'completed', 'cancelled']),
            'notes' => $this->faker->sentence(),
        ];
    }
}
