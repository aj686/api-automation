<?php

namespace App\Models;

use Database\Factories\RunResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row parsed from the Postman CLI report. Already redacted when stored.
 */
#[Fillable([
    'position', 'item_name', 'method', 'url', 'response_code',
    'response_time_ms', 'assertion', 'passed', 'error_message',
])]
#[WithoutTimestamps]
class RunResult extends Model
{
    /** @use HasFactory<RunResultFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
