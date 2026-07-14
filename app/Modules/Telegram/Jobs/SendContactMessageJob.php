<?php

namespace App\Modules\Telegram\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Modules\Telegram\Actions\SendContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendContactMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public readonly string $operationKey;

    public function __construct(
        public readonly int $botUserId,
        public readonly ?string $telegramLanguageCode = null,
        ?string $operationKey = null,
    ) {
        $this->operationKey = $operationKey ?? hash('sha256', 'telegram-contact-card|' . $botUserId . '|' . (string) \Illuminate\Support\Str::uuid());
        $this->onQueue('telegram-mirror');
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    public function handle(SendContactMessage $sender): void
    {
        $botUser = BotUser::find($this->botUserId);
        if ($botUser === null) {
            throw new RuntimeException("Telegram bot user {$this->botUserId} was not found");
        }

        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => $this->operationKey],
            [
                'bot_user_id' => $botUser->id,
                'trace_id' => 'telegram:contact-card:' . $this->operationKey,
                'destination' => 'telegram-support-topic',
                'operation' => 'contact_card',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );
        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            return;
        }

        if (empty($botUser->topic_id)) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => 'Telegram topic is not assigned yet',
            ]);
            TopicCreateJob::withChain([
                new self($this->botUserId, $this->telegramLanguageCode, $this->operationKey),
            ])->onQueue('telegram-mirror')->dispatch($this->botUserId);

            return;
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => now(),
            'last_error' => null,
        ]);

        $response = $sender->sendNow($botUser, $this->telegramLanguageCode);
        if ($response->ok) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => $response->message_id,
                'delivered_at' => now(),
            ]);

            return;
        }

        if ($response->response_code === 429) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => 'Telegram rate limit',
            ]);
            $this->release((int) ($response->rawData['parameters']['retry_after'] ?? 3));

            return;
        }

        if ($response->response_code === 400 && in_array($response->type_error, [
            'TOPIC_NOT_FOUND',
            'TOPIC_DELETED',
            'TOPIC_ID_INVALID',
        ], true)) {
            $botUser->update(['topic_id' => null]);
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => 'Telegram topic was not found',
            ]);
            TopicCreateJob::withChain([
                new self($this->botUserId, $this->telegramLanguageCode, $this->operationKey),
            ])->onQueue('telegram-mirror')->dispatch($this->botUserId);

            return;
        }

        $operation->update([
            'status' => ($response->response_code ?? 0) >= 500
                ? DeliveryOperation::STATUS_RETRYING
                : DeliveryOperation::STATUS_FAILED,
            'last_error' => sprintf('code=%s type=%s', $response->response_code, $response->type_error),
        ]);

        throw new RuntimeException(sprintf(
            'Telegram contact card failed: code=%s type=%s',
            $response->response_code,
            $response->type_error,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        DeliveryOperation::query()
            ->where('operation_key', $this->operationKey)
            ->where('status', '!=', DeliveryOperation::STATUS_DELIVERED)
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => 'Job exhausted retries: ' . $exception::class,
            ]);

        Log::channel('app')->error('Telegram contact card permanently failed', [
            'source' => 'telegram_contact_card_failed',
            'bot_user_id' => $this->botUserId,
            'error_class' => $exception::class,
        ]);
    }
}
