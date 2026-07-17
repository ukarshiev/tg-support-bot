<?php

namespace App\Services\Webhook;

use App\Models\ExternalSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(private readonly OutboundWebhookUrlPolicy $urlPolicy)
    {
    }

    /**
     * @param array<string, mixed> $dataMessage
     */
    public function sendMessage(
        ExternalSource $source,
        array $dataMessage,
        string $deliveryId,
        ?int $timestamp = null,
    ): ?string {
        try {
            $validated = $this->urlPolicy->validate((string) $source->webhook_url);
            $secret = (string) ($source->webhook_signing_secret ?? '');
            $keyId = (string) ($source->webhook_key_id ?? '');
            if ($secret === '' || $keyId === '') {
                throw new OutboundWebhookException('signing_key_missing');
            }

            $timestamp ??= now()->timestamp;
            $body = json_encode($dataMessage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $timestamp . '.' . $deliveryId . '.' . $body, $secret);

            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_RESOLVE => $validated->curlResolve(),
                    ],
                ])
                ->withHeaders([
                    'X-Tg-Support-Webhook-Id' => $deliveryId,
                    'X-Tg-Support-Timestamp' => (string) $timestamp,
                    'X-Tg-Support-Key-Id' => $keyId,
                    'X-Tg-Support-Signature' => 'v1=' . $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($validated->url);

            if ($response->failed()) {
                throw new OutboundWebhookException($response->clientError() ? 'http_4xx' : 'http_5xx');
            }

            return $response->body();
        } catch (ConnectionException) {
            $this->logFailure('timeout', $source, $deliveryId);

            return null;
        } catch (OutboundWebhookException $e) {
            $this->logFailure($e->reason, $source, $deliveryId);

            return null;
        } catch (\Throwable $e) {
            Log::channel('app')->error('Ошибка доставки webhook', [
                'reason' => 'unexpected_error',
                'error_type' => $e::class,
                'source_id' => $source->id,
                'delivery_id' => $deliveryId,
            ]);

            return null;
        }
    }

    private function logFailure(string $reason, ExternalSource $source, string $deliveryId): void
    {
        Log::channel('app')->warning('Ошибка доставки webhook', [
            'reason' => $reason,
            'source_id' => $source->id,
            'delivery_id' => $deliveryId,
        ]);
    }
}
