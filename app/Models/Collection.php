<?php

namespace App\Models;

use App\Enums\CollectionKind;
use App\Services\CollectionImporter;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An imported Postman collection. The file itself stays a Postman artifact;
 * this row only records where it is and what it is.
 */
#[Fillable(['name', 'slug', 'kind', 'stored_path', 'original_filename', 'sha256', 'schema_version'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Collection $collection) {
            $collection->slug ??= Str::slug($collection->name);
        });

        // The database cascade cannot remove files, so the model does.
        static::deleted(function (Collection $collection) {
            Storage::disk(CollectionImporter::DISK)->delete($collection->stored_path);
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
            'kind' => CollectionKind::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
