<?php

namespace Database\Factories;

use App\Models\AiTriageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTriageLog>
 */
class AiTriageLogFactory extends Factory
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
            'symptoms_summary' => $this->faker->paragraph(),
            'urgency_level' => $this->faker->randomElement(['low', 'medium', 'high', 'emergency']),
            'recommended_action' => $this->faker->sentence(),
        ];
    }
}
