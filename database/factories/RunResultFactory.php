<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\RunResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunResult>
 */
class RunResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'position' => fake()->numberBetween(0, 50),
            'item_name' => 'GET '.fake()->word(),
            'method' => 'GET',
            'url' => 'https://api.example.invalid/'.fake()->word(),
            'response_code' => 200,
            'response_time_ms' => fake()->numberBetween(5, 500),
            'assertion' => 'Status code is 200',
            'passed' => true,
            'error_message' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_code' => 500,
            'passed' => false,
            'error_message' => 'expected response to have status code 200 but got 500',
        ]);
    }
}
