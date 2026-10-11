<?php

namespace Database\Factories;

use App\Models\CaptureBucket;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CaptureBucket>
 */
class CaptureBucketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Webhook '.fake()->unique()->numberBetween(1, 99999);

        return [
            'environment_id' => Environment::factory(),
            'project_id' => fn (array $attributes) => Environment::find($attributes['environment_id'])->project_id,
            'name' => $name,
            'slug' => Str::slug($name),
            'token_hash' => hash('sha256', 'YOUR_CAPTURE_TOKEN'),
            'enabled' => true,
        ];
    }
}
