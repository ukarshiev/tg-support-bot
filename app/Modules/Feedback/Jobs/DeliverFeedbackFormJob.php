<?php

namespace App\Modules\Feedback\Jobs;

use App\Contracts\LocalizedSystemMessageChannel;
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

class DeliverFeedbackFormJob implements ShouldQueue
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
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $feedback = Feedback::with('botUser')->find($this->feedbackId);
        if ($feedback === null || $feedback->status === 'superseded') {
            return;
        }

        /** @var BotUser|null $botUser */
        $botUser = $feedback->botUser;
        if ($botUser === null) {
            throw new \RuntimeException('Feedback BotUser not found');
        }

        $operation = $this->operation($feedback, $botUser);
        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            $feedback->update(['status' => 'awaiting_rating']);

            return;
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => $operation->started_at ?? now(),
        ]);

        try {
            $externalId = match ($botUser->platform) {
                'telegram' => $this->telegram((int) $botUser->chat_id, $botUser->id),
                'vk' => $this->vk((int) $botUser->chat_id, $botUser->id),
                'max' => $this->max((int) $botUser->chat_id, $botUser->id),
                default => $this->registeredChannel($botUser, $feedback->id),
            };

            $operation->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => is_numeric($externalId) ? (int) $externalId : null,
                'last_error' => null,
                'delivered_at' => now(),
            ]);
            $feedback->update(['status' => 'awaiting_rating']);

            Log::channel('app')->info('Feedback form delivery confirmed', [
                'source' => 'feedback_form_delivery_confirmed',
                'feedback_id' => $feedback->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
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
        Feedback::whereKey($this->feedbackId)
            ->where('status', 'delivery_pending')
            ->update(['status' => 'delivery_failed']);

        DeliveryOperation::where('operation_key', hash('sha256', 'feedback-form:' . $this->feedbackId))
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

        Log::channel('app')->critical('Feedback form delivery permanently failed', [
            'source' => 'feedback_form_delivery_failed_terminal',
            'feedback_id' => $this->feedbackId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    private function operation(Feedback $feedback, BotUser $botUser): DeliveryOperation
    {
        return DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', 'feedback-form:' . $feedback->id)],
            [
                'bot_user_id' => $feedback->bot_user_id,
                'trace_id' => 'feedback:' . $feedback->id,
                'destination' => $botUser->platform . '-client',
                'operation' => 'feedback-form',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );
    }

    private function telegram(int $chatId, int $botUserId): ?int
    {
        $response = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $chatId,
            'text' => $this->text,
            'parse_mode' => 'html',
            'reply_markup' => ['inline_keyboard' => [$this->telegramButtons($botUserId)]],
        ]);

        if ($response->ok !== true) {
            throw new \RuntimeException('Telegram rejected feedback form, response_code=' . ($response->response_code ?? 0));
        }

        return $response->message_id;
    }

    private function vk(int $peerId, int $botUserId): int|array
    {
        $response = VkMethods::sendQueryVk('messages.send', [
            'peer_id' => $peerId,
            'message' => $this->text,
            'keyboard' => json_encode($this->vkKeyboard($botUserId), JSON_THROW_ON_ERROR),
        ]);

        if ($response->response_code !== 200) {
            throw new \RuntimeException('VK rejected feedback form: HTTP ' . $response->response_code);
        }

        return $response->response;
    }

    private function max(int $userId, int $botUserId): int|string|null
    {
        $response = app(MaxMethods::class)->sendQuery('sendMessage', [
            'user_id' => $userId,
            'text' => $this->text,
            'keyboard' => [$this->maxButtons($botUserId)],
        ]);

        if ($response->response_code !== 200) {
            throw new \RuntimeException('Max rejected feedback form: HTTP ' . $response->response_code);
        }

        return is_int($response->response) || is_string($response->response) ? $response->response : null;
    }

    private function registeredChannel(BotUser $botUser, int $feedbackId): null
    {
        $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);
        if ($channel instanceof LocalizedSystemMessageChannel) {
            $channel->sendLocalizedFeedbackForm($botUser, $feedbackId, $this->text);

            return null;
        }

        if ($channel !== null) {
            $channel->sendFeedbackForm($botUser, $feedbackId);

            return null;
        }

        throw new \RuntimeException('Unsupported feedback platform: ' . $botUser->platform);
    }

    /** @return array<int, array{text:string, callback_data:string}> */
    private function telegramButtons(int $botUserId): array
    {
        return array_map(fn (int $score): array => [
            'text' => (string) $score,
            'callback_data' => "feedback_rate_{$botUserId}_{$this->feedbackId}_{$score}",
        ], range(1, 5));
    }

    /** @return array<string, mixed> */
    private function vkKeyboard(int $botUserId): array
    {
        $buttons = array_map(fn (int $score): array => ['action' => [
            'type' => 'callback',
            'label' => (string) $score,
            'payload' => json_encode(['command' => "feedback_rate_{$botUserId}_{$this->feedbackId}_{$score}"]),
        ]], range(1, 5));

        return ['inline' => true, 'buttons' => [$buttons]];
    }

    /** @return array<int, array<string, mixed>> */
    private function maxButtons(int $botUserId): array
    {
        return array_map(fn (int $score): array => [
            'type' => 'callback',
            'text' => (string) $score,
            'payload' => "feedback_rate_{$botUserId}_{$this->feedbackId}_{$score}",
        ], range(1, 5));
    }
}
