<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call received on a capture URL. Already redacted when stored.
 */
#[Fillable(['method', 'path', 'query', 'headers', 'body', 'body_truncated', 'received_at'])]
#[WithoutTimestamps]
class CapturedRequest extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'body_truncated' => 'boolean',
            'received_at' => 'datetime:Y-m-d\TH:i:s.vP',
        ];
    }

    /**
     * @return BelongsTo<CaptureBucket, $this>
     */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(CaptureBucket::class, 'capture_bucket_id');
    }
}
