<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                          $name
 * @property ExternalSource                  $external_source
 * @property string|null                     $token_hash
 * @property string|null                     $token_hint
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class ExternalSourceAccessTokens extends Model
{
    use HasFactory;

    protected $table = 'external_source_access_tokens';

    protected $fillable = [
        'external_source_id',
        'token',
        'token_hash',
        'token_hint',
        'active',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if (is_string($model->token) && $model->token !== '') {
                $model->token_hash = hash('sha256', $model->token);
                $model->token_hint = substr($model->token, -6);
            }
        });
    }

    /**
     * @return BelongsTo
     */
    public function external_source(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }
}
