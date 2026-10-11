<?php

namespace App\Models;

use Database\Factories\CaptureBucketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * A capture URL for one project + environment (plan section 13, decisions.md D-032).
 *
 * The URL token is random and stored only as a SHA-256 hash; issueToken()
 * returns the plain token once. Buckets are disabled by default and can
 * never belong to a production environment (loophole 40).
 */
#[Fillable(['environment_id', 'name', 'slug', 'response_status', 'enabled', 'expires_after_hours'])]
#[Hidden(['token_hash'])]
class CaptureBucket extends Model
{
    /** @use HasFactory<CaptureBucketFactory> */
    use HasFactory;

    public const TOKEN_LENGTH = 40;

    protected static function booted(): void
    {
        static::creating(function (CaptureBucket $bucket) {
            $bucket->slug ??= Str::slug($bucket->name);
        });

        // Enforced here, not only in the UI: a production system pointed at a
        // capture URL would stop sending real payments or webhooks (loophole 40).
        static::saving(function (CaptureBucket $bucket) {
            if ($bucket->environment()->first()?->isProduction()) {
                throw new LogicException('Capture buckets cannot be created for production environments.');
            }
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
            'enabled' => 'boolean',
            'response_status' => 'integer',
            'expires_after_hours' => 'integer',
        ];
    }

    /**
     * Sets a new random token (invalidating the old URL) and returns it in clear, once.
     */
    public function issueToken(): string
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $this->token_hash = hash('sha256', $token);

        return $token;
    }

    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $token));
    }

    /**
     * Path the system under test calls; the host depends on where it runs from.
     */
    public function capturePath(string $token): string
    {
        return '/capture/'.$this->id.'/'.$token;
    }

    /**
     * Deletes records older than the bucket's retention (loophole 38).
     * Run on every capture and read instead of by a scheduler.
     */
    public function pruneExpired(): void
    {
        $this->requests()->where('received_at', '<', now()->subHours($this->expires_after_hours))->delete();
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
     * @return HasMany<CapturedRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(CapturedRequest::class);
    }
}
