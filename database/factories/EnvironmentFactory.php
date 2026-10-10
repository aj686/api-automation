<?php

namespace Database\Factories;

use App\Enums\EnvironmentType;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Local', 'Development', 'Staging', 'UAT', 'Training'])
            .' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => EnvironmentType::Staging,
            // .invalid never resolves (RFC 2606), so a factory row cannot point at a real host.
            'base_url_hint' => 'https://'.Str::slug($name).'.example.invalid',
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Production',
            'slug' => 'production',
            'type' => EnvironmentType::Production,
        ]);
    }
}
