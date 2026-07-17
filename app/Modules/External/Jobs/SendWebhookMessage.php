<?php

namespace App\Modules\External\Jobs;

use App\Models\ExternalSource;
use App\Services\Webhook\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendWebhookMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $url;

    public array $payload;

    public int $sourceId;

    public string $deliveryId;

    public int $tries = 3;

    public array $backoff = [60, 180, 300];

    public function __construct(string $url, array $payload, int $sourceId)
    {
        $this->url = $url;
        $this->payload = $payload;
        $this->sourceId = $sourceId;
        $this->deliveryId = (string) Str::uuid();
    }

    /**
     * @return void
     */
    public function handle(WebhookService $webhook): void
    {
        try {
            if (empty($this->url)) {
                throw new \Exception('Webhook URL is empty', 1);
            }

            $source = ExternalSource::find($this->sourceId);
            if (! $source || ! hash_equals((string) $source->webhook_url, $this->url)) {
                throw new \RuntimeException('Webhook source is unavailable.');
            }

            $response = $webhook->sendMessage($source, $this->payload, $this->deliveryId);
            if ($response === null) {
                throw new \RuntimeException('Webhook delivery failed.');
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log($e->getCode() === 1 ? 'warning' : 'error', 'Webhook delivery job failed', [
                'error_type' => $e::class,
            ]);

            throw $e;
        }
    }
}
