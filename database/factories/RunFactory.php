<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A queued run: everything the CLI would report is still NULL (unknown).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'collection_id' => fn (array $attributes) => Collection::factory()->state([
                'project_id' => Environment::find($attributes['environment_id'])->project_id,
            ]),
            'project_id' => fn (array $attributes) => Environment::find($attributes['environment_id'])->project_id,
            'project_name' => fn (array $attributes) => Environment::find($attributes['environment_id'])->project->name,
            'environment_name' => fn (array $attributes) => Environment::find($attributes['environment_id'])->name,
            'environment_type' => fn (array $attributes) => Environment::find($attributes['environment_id'])->type->value,
            'collection_name' => fn (array $attributes) => Collection::find($attributes['collection_id'])->name,
            'status' => RunStatus::Queued,
            'trigger' => RunTrigger::Manual,
            'timeout_seconds' => 300,
        ];
    }

    public function passed(int $assertions = 3): static
    {
        return $this->finished(RunStatus::Pass, $assertions, 0);
    }

    public function failed(int $assertions = 3, int $failures = 1): static
    {
        return $this->finished(RunStatus::Fail, $assertions, $failures);
    }

    private function finished(RunStatus $status, int $assertions, int $failures): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'exit_code' => $failures > 0 ? 1 : 0,
            'cli_version' => '1.71.0',
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
            'duration_ms' => 5000,
            'total_requests' => $assertions,
            'total_assertions' => $assertions,
            'passed_assertions' => $assertions - $failures,
            'failed_assertions' => $failures,
        ]);
    }
}
