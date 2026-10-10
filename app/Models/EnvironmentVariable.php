<?php

namespace App\Models;

use Database\Factories\EnvironmentVariableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One variable of an environment: configuration (base_url) or a secret
 * (api_key), told apart only by is_secret.
 *
 * value is always encrypted at rest, secret or not (decisions.md D-012).
 * It is hidden from toArray()/toJson() so a model can never be serialised
 * into an API response or a log with its value attached; callers that need
 * the value read $variable->value explicitly.
 */
#[Fillable(['key', 'value', 'is_secret', 'enabled'])]
#[Hidden(['value'])]
class EnvironmentVariable extends Model
{
    /** @use HasFactory<EnvironmentVariableFactory> */
    use HasFactory;

    /** Shared by the UI forms and EnvironmentImporter. */
    public const KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,100}$/';

    public const MAX_VALUE_LENGTH = 8000;

    /** What a stored secret looks like in the UI (plan section 13). */
    public const MASK = '********';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
