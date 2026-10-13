<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Services\RunOptions;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a collection against an environment.
 *
 * The *_name columns and environment_type are snapshots taken when the run
 * is created, so history stays readable after the project, environment or
 * collection is deleted (the foreign keys become NULL). environment_type is
 * kept as a plain string for the same reason: an old snapshot must never
 * fail to load because the enum changed later.
 */
#[Fillable([
    'project_id', 'environment_id', 'collection_id',
    'project_name', 'environment_name', 'environment_type', 'collection_name',
    'status', 'trigger', 'options', 'cancel_requested_at', 'pid', 'exit_code', 'cli_version',
    'timeout_seconds', 'started_at', 'finished_at', 'duration_ms',
    'total_requests', 'total_assertions', 'passed_assertions', 'failed_assertions',
    'error_code', 'error_message', 'log',
])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'trigger' => RunTrigger::class,
            'options' => 'array',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function runOptions(): RunOptions
    {
        return RunOptions::fromArray($this->options);
    }

    /**
     * "GetProjectInfo → Login · stop on error" for a run that did not send the
     * whole collection in file order; null for one that did (D-038).
     */
    public function sequenceSummary(): ?string
    {
        $options = $this->runOptions();
        if ($options->isDefault()) {
            return null;
        }

        $parts = [];
        if ($options->items !== []) {
            $names = array_column($options->items, 'name');
            $shown = array_slice($names, 0, 5);
            $parts[] = implode(' → ', $shown).(count($names) > 5 ? ' → +'.(count($names) - 5).' more' : '');
        }
        if ($options->bail) {
            $parts[] = 'stop on error';
        }

        return implode(' · ', $parts);
    }

    /**
     * A PASS that checked nothing proves nothing (plan section 5).
     */
    public function hasNoTests(): bool
    {
        return $this->status === RunStatus::Pass && $this->total_assertions === 0;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return HasMany<RunRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(RunRequest::class)->orderBy('position');
    }

    /**
     * @return HasMany<RunResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(RunResult::class)->orderBy('position');
    }
}
