<?php

namespace App\Modules\Telegram\Jobs;

use App\Models\BotUser;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendTelegramTopicMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly int $botUserId,
        public readonly string $text,
        public readonly ?string $parseMode = 'html',
    ) {
        $this->onQueue('telegram-mirror');
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    public function handle(): void
    {
        $botUser = BotUser::find($this->botUserId);
        if ($botUser === null) {
            throw new RuntimeException("Telegram bot user {$this->botUserId} was not found");
        }

        if (empty($botUser->topic_id)) {
            $this->createTopicThenRetry();

            return;
        }

        $response = TelegramMethods::sendQueryTelegram('sendMessage', array_filter([
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => $this->text,
            'parse_mode' => $this->parseMode,
        ], static fn ($value) => $value !== null));

        if ($response->ok) {
            return;
        }

        if ($response->response_code === 429) {
            $this->release((int) ($response->rawData['parameters']['retry_after'] ?? 3));

            return;
        }

        if ($response->response_code === 400 && in_array($response->type_error, [
            'TOPIC_NOT_FOUND',
            'TOPIC_DELETED',
            'TOPIC_ID_INVALID',
        ], true)) {
            $botUser->update(['topic_id' => null]);
            $this->createTopicThenRetry();

            return;
        }

        throw new RuntimeException(sprintf(
            'Telegram topic service message failed: code=%s type=%s',
            $response->response_code,
            $response->type_error,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->error('Telegram topic service message permanently failed', [
            'source' => 'telegram_topic_service_message_failed',
            'bot_user_id' => $this->botUserId,
            'error_class' => $exception::class,
        ]);
    }

    private function createTopicThenRetry(): void
    {
        TopicCreateJob::withChain([
            new self($this->botUserId, $this->text, $this->parseMode),
        ])->onQueue('telegram-mirror')->dispatch($this->botUserId);
    }
}
