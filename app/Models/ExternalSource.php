<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                     $id
 * @property string                  $name
 * @property string                  $webhook_url
 * @property int                     $user_id
 * @property array<int, string>|null $allowed_ips
 * @property-read User $user
 */
class ExternalSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'webhook_url',
        'user_id',
        'allowed_ips',
        'webhook_key_id',
        'webhook_signing_secret',
        'pending_webhook_key_id',
        'pending_webhook_signing_secret',
    ];

    protected $casts = [
        'allowed_ips' => 'array',
        'webhook_signing_secret' => 'encrypted',
        'pending_webhook_signing_secret' => 'encrypted',
    ];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the given request IP is allowed for this source.
     *
     * An empty/NULL allowlist means no restriction — any IP is allowed.
     *
     * @param string|null $ip
     *
     * @return bool
     */
    public function isIpAllowed(?string $ip): bool
    {
        $allowed = array_values(array_filter(array_map('trim', $this->allowed_ips ?? [])));

        if (empty($allowed)) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
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
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }
}
