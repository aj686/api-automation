<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Placeholder values only — never a real credential (CLAUDE.md hard rules).
 *
 * @extends Factory<EnvironmentVariable>
 */
class EnvironmentVariableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'key' => fake()->unique()->lexify('var_????'),
            'value' => 'https://api.example.invalid',
            'is_secret' => false,
            'enabled' => true,
        ];
    }

    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => fake()->unique()->lexify('api_key_????'),
            'value' => 'YOUR_API_KEY',
            'is_secret' => true,
        ]);
    }
}
