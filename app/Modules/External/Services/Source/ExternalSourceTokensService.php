<?php

declare(strict_types=1);

namespace App\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalSourceTokensService
{
    public function __construct(private ExternalSourceAccessTokens $externalSourceAccessTokens)
    {
    }

    /**
     * Create or regenerate the bearer token for the given external source.
     *
     * A new prefixed token is created while the previous active token receives
     * a 24-hour expiry. The raw token value is returned so the
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

        return DB::transaction(function () use ($sourceId): string {
            $newAccessToken = $this->generateToken();

            $this->externalSourceAccessTokens
                ->where('external_source_id', $sourceId)
                ->where('active', true)
                ->whereNull('revoked_at')
                ->update(['expires_at' => now()->addDay(), 'updated_at' => now()]);

            $this->externalSourceAccessTokens->create([
                'external_source_id' => $sourceId,
                'token' => null,
                'token_hash' => hash('sha256', $newAccessToken),
                'token_hint' => substr($newAccessToken, -6),
                'active' => true,
            ]);

            return $newAccessToken;
        });
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

    public function revoke(int $tokenId, int $sourceId): bool
    {
        return $this->externalSourceAccessTokens
            ->whereKey($tokenId)
            ->where('external_source_id', $sourceId)
            ->update(['active' => false, 'revoked_at' => now()]) === 1;
    }

    /**
     * Generate a prefixed token with 64 cryptographically random characters.
     *
     * @return string
     */
    private function generateToken(): string
    {
        return 'ext_' . Str::random(64);
    }
}
