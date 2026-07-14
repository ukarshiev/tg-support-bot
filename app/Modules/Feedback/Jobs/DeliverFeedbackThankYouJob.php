<?php

namespace App\Modules\Feedback\Jobs;

use App\Contracts\LocalizedSystemMessageChannel;
use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Feedback;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Vk\Api\VkMethods;
use App\Platform\PlatformChannelRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeliverFeedbackThankYouJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 180];

    public int $timeout = 30;

    public function __construct(
        public readonly int $feedbackId,
        public readonly string $text,
        public readonly ?int $telegramMessageId = null,
        public readonly ?int $telegramChatId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $feedback = Feedback::with('botUser')->find($this->feedbackId);
        if ($feedback === null || $feedback->botUser === null) {
            return;
        }

        /** @var BotUser $botUser */
        $botUser = $feedback->botUser;
        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', 'feedback-thank-you:' . $feedback->id)],
            [
                'bot_user_id' => $botUser->id,
                'trace_id' => 'feedback:' . $feedback->id,
                'destination' => $botUser->platform . '-client',
                'operation' => 'feedback-thank-you',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            return;
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => $operation->started_at ?? now(),
        ]);

        try {
            $externalId = $this->deliver($botUser);
            $operation->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => is_numeric($externalId) ? (int) $externalId : null,
                'last_error' => null,
                'delivered_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        DeliveryOperation::where('operation_key', hash('sha256', 'feedback-thank-you:' . $this->feedbackId))
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

        Log::channel('app')->error('Feedback thank-you permanently failed; rating is preserved', [
            'source' => 'feedback_thank_you_failed_terminal',
            'feedback_id' => $this->feedbackId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    private function deliver(BotUser $botUser): int|string|null
    {
        if ($botUser->platform === 'telegram') {
            $method = $this->telegramMessageId !== null && $this->telegramChatId !== null
                ? 'editMessageText'
                : 'sendMessage';
            $params = $method === 'editMessageText'
                ? [
                    'chat_id' => $this->telegramChatId,
                    'message_id' => $this->telegramMessageId,
                    'text' => $this->text,
                    'parse_mode' => 'html',
                    'reply_markup' => ['inline_keyboard' => []],
                ]
                : [
                    'chat_id' => $botUser->chat_id,
                    'text' => $this->text,
                    'parse_mode' => 'html',
                ];
            $response = TelegramMethods::sendQueryTelegram($method, $params);

            if ($response->ok !== true) {
                throw new \RuntimeException('Telegram rejected feedback thank-you, response_code=' . ($response->response_code ?? 0));
            }

            return $response->message_id;
        }

        if ($botUser->platform === 'vk') {
            $response = VkMethods::sendQueryVk('messages.send', [
                'peer_id' => $botUser->chat_id,
                'message' => $this->text,
            ]);
            if ($response->response_code !== 200) {
                throw new \RuntimeException('VK rejected feedback thank-you: ' . ($response->error_message ?? 'unknown error'));
            }

            return is_int($response->response) ? $response->response : null;
        }

        if ($botUser->platform === 'max') {
            $response = app(MaxMethods::class)->sendQuery('sendMessage', [
                'user_id' => (int) $botUser->chat_id,
                'text' => $this->text,
            ]);
            if ($response->response_code !== 200) {
                throw new \RuntimeException('Max rejected feedback thank-you: ' . ($response->error_message ?? 'unknown error'));
            }

            return is_int($response->response) || is_string($response->response) ? $response->response : null;
        }

        $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);
        if ($channel instanceof LocalizedSystemMessageChannel) {
            $channel->sendSystemMessage($botUser, AutoReply::TYPE_FEEDBACK_THANK_YOU, $this->text);

            return null;
        }

        throw new \RuntimeException('Unsupported feedback thank-you platform: ' . $botUser->platform);
    }
}
