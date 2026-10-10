<?php

namespace App\Models;

use App\Enums\EnvironmentType;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'type', 'base_url_hint', 'allow_automation'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Environment $environment) {
            $environment->slug ??= Str::slug($environment->name);

            // Production is excluded from automation unless explicitly allowed (plan section 6).
            $environment->allow_automation ??= ! $environment->isProduction();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EnvironmentType::class,
            'allow_automation' => 'boolean',
        ];
    }

    public function isProduction(): bool
    {
        return $this->type === EnvironmentType::Production;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<EnvironmentVariable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }
}
