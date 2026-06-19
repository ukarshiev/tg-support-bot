<?php

declare(strict_types=1);

namespace App\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use Illuminate\Support\Str;

class ExternalSourceTokensService
{
    public function __construct(private ExternalSourceAccessTokens $externalSourceAccessTokens)
    {
    }

    /**
     * Create or regenerate the bearer token for the given external source.
     *
     * The previous token record (if any) is replaced with a new 64-character
     * token that is set to active=true. The raw token value is returned so the
     * caller can surface a one-time reveal in the UI — it is never logged.
     *
     * @param int $sourceId
     *
     * @return string The new raw token (one-time reveal only — never log this value).
     *
     * @throws \Exception
     */
    public function setAccessToken(int $sourceId): string
    {
        $sourceItem = ExternalSource::where('id', $sourceId)->first();

        if (! $sourceItem) {
            throw new \Exception('Токен не создался. Ресурс не найден!');
        }

        $newAccessToken = $this->generateToken();

        $accessTokenData = [
            'token' => $newAccessToken,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $accessTokensItem = $this->externalSourceAccessTokens->where('external_source_id', $sourceId)->first();
        if (! $accessTokensItem) {
            $this->externalSourceAccessTokens->create(array_merge($accessTokenData, [
                'external_source_id' => $sourceId,
            ]));
        } else {
            $accessTokensItem->update($accessTokenData);
        }

        return $newAccessToken;
    }

    /**
     * Toggle the active flag on the access token record for the given source.
     *
     * If no token record exists for the source, the call is a no-op (returns false).
     *
     * @param int  $sourceId
     * @param bool $active
     *
     * @return bool Whether the update was applied (false when no token record exists).
     */
    public function setTokenActive(int $sourceId, bool $active): bool
    {
        $accessTokensItem = $this->externalSourceAccessTokens
            ->where('external_source_id', $sourceId)
            ->first();

        if (! $accessTokensItem) {
            return false;
        }

        $accessTokensItem->update(['active' => $active, 'updated_at' => now()]);

        return true;
    }

    /**
     * Generate a new public key for widget gateway access and persist it on the source.
     *
     * The previous public_key value is replaced. The raw key is returned so the caller
     * can surface a one-time reveal in the UI — it is never logged.
     *
     * @param ExternalSource $source
     *
     * @return string The new raw public key (one-time reveal only — never log this value).
     */
    public function rotatePublicKey(ExternalSource $source): string
    {
        $key = $this->generatePublicKey();

        $source->update(['public_key' => $key]);

        return $key;
    }

    /**
     * Generate a cryptographically random public key (~40 chars) for widget gateway use.
     *
     * @return string
     */
    public function generatePublicKey(): string
    {
        return 'pub_' . Str::random(36);
    }

    /**
     * Generate a cryptographically random 64-character token.
     *
     * @return string
     */
    private function generateToken(): string
    {
        return Str::random(64);
    }
}
