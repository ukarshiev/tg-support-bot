<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\ExternalSource;
use Illuminate\Support\Str;
use RuntimeException;

class WebhookSigningSecretService
{
    /**
     * @return array{key_id: string, secret: string}
     */
    public function createPending(ExternalSource $source): array
    {
        $keyId = 'whk_' . Str::lower(Str::random(12));
        $secret = Str::random(64);

        $source->update([
            'pending_webhook_key_id' => $keyId,
            'pending_webhook_signing_secret' => $secret,
        ]);

        return ['key_id' => $keyId, 'secret' => $secret];
    }

    public function activatePending(ExternalSource $source): void
    {
        if ($source->pending_webhook_key_id === null || $source->pending_webhook_signing_secret === null) {
            throw new RuntimeException('Pending webhook signing key is not configured.');
        }

        $source->update([
            'webhook_key_id' => $source->pending_webhook_key_id,
            'webhook_signing_secret' => $source->pending_webhook_signing_secret,
            'pending_webhook_key_id' => null,
            'pending_webhook_signing_secret' => null,
        ]);
    }
}
