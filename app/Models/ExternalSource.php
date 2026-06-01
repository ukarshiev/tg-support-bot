<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 * @property string $webhook_url
 * @property int    $user_id
 * @property-read User $user
 */
class ExternalSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'webhook_url',
        'user_id',
    ];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(ExternalSourceAccessTokens::class, 'external_source_id');
    }

    /**
     * Return the single active access token record for this source, or null.
     *
     * @return ExternalSourceAccessTokens|null
     */
    public function activeToken(): ?ExternalSourceAccessTokens
    {
        /** @var ExternalSourceAccessTokens|null */
        return ExternalSourceAccessTokens::where('external_source_id', $this->id)
            ->where('active', true)
            ->first();
    }
}
