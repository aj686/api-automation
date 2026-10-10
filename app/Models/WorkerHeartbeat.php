<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Last sign of life from a queue worker; the UI warns when it goes stale.
 */
#[Fillable(['worker', 'beat_at'])]
#[WithoutTimestamps]
class WorkerHeartbeat extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'beat_at' => 'datetime',
        ];
    }
}
