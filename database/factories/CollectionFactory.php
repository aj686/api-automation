<?php

namespace Database\Factories;

use App\Enums\CollectionKind;
use App\Models\Collection;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only — no file is written to stored_path.
 *
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = fake()->randomElement(CollectionKind::cases());
        $name = ucfirst($kind->value).' '.fake()->unique()->word();
        $slug = Str::slug($name);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'slug' => $slug,
            'kind' => $kind,
            'stored_path' => 'collections/'.Str::ulid().'.json',
            'original_filename' => $slug.'.postman_collection.json',
            'sha256' => hash('sha256', $slug),
            'schema_version' => 'v2.1.0',
        ];
    }
}
