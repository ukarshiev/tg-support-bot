<?php

namespace App\Modules\Telegram\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\Support\TelegramPipelineTrace;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendTelegramMirrorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly int $botUserId,
        public readonly int $messageId,
        public readonly ?string $text,
        public readonly string $traceId,
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
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        if ($botUser === null || $botUser->platform !== 'telegram' || $groupId === '') {
            return;
        }

        $message = Message::with('attachments')->find($this->messageId);
        if ($message === null || $message->bot_user_id !== $botUser->id) {
            throw new RuntimeException("Telegram mirror source message {$this->messageId} was not found");
        }

        [$method, $params] = $this->buildTelegramRequest($message, $groupId, $botUser->topic_id);

        $operationKey = hash('sha256', implode('|', [
            'telegram-mirror',
            $this->botUserId,
            $this->messageId,
        ]));

        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => $operationKey],
            [
                'bot_user_id' => $this->botUserId,
                'message_id' => $this->messageId,
                'trace_id' => $this->traceId,
                'destination' => 'telegram-support-topic',
                'operation' => $method,
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
            $this->createTopicThenRetry();

            return;
        }

        if (Cache::pull("telegram:topic-reopen:{$botUser->id}", false) || $botUser->isClosed()) {
            if (! $this->reopenTopic($botUser, $groupId, $operation)) {
                return;
            }
        }

        // Topic could have changed while it was being recovered.
        $botUser->refresh();
        if (empty($botUser->topic_id)) {
            $this->createTopicThenRetry();

            return;
        }
        [$method, $params] = $this->buildTelegramRequest($message, $groupId, $botUser->topic_id);

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => now(),
            'last_error' => null,
        ]);

        TelegramPipelineTrace::log('support_mirror_started', $this->traceId, [
            'bot_user_id' => $this->botUserId,
            'message_id' => $this->messageId,
            'attempt' => $this->attempts(),
        ]);

        $response = (new TelegramMethods())->sendQueryTelegram($method, $params);

        if ($response->ok) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => $response->message_id,
                'delivered_at' => now(),
            ]);
            TelegramPipelineTrace::log('support_mirror_completed', $this->traceId, [
                'bot_user_id' => $this->botUserId,
                'message_id' => $this->messageId,
                'mirror_message_id' => $response->message_id,
            ]);

            return;
        }

        $error = sprintf('Telegram mirror failed: code=%s type=%s', $response->response_code, $response->type_error);
        $isServerError = (int) ($response->rawData['error_code'] ?? $response->response_code ?? 0) >= 500;
        $operation->update([
            'status' => $isServerError || $response->response_code === 429
                ? DeliveryOperation::STATUS_RETRYING
                : DeliveryOperation::STATUS_FAILED,
            'last_error' => $error,
        ]);

        TelegramPipelineTrace::log('pipeline_failed', $this->traceId, [
            'stage' => 'support_mirror',
            'response_code' => $response->response_code,
            'type_error' => $response->type_error,
        ]);

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

        if ($response->response_code === 400 && $this->isTopicClosed($response)) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => $error,
            ]);
            if ($this->reopenTopic($botUser, $groupId, $operation)) {
                $this->release(1);
            }

            return;
        }

        if ($isServerError) {
            throw new RuntimeException($error);
        }

        Log::channel('app')->warning($error, [
            'source' => 'telegram_outgoing_bot_mirror_failed',
            'bot_user_id' => $this->botUserId,
            'message_id' => $this->messageId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DeliveryOperation::query()
            ->where('bot_user_id', $this->botUserId)
            ->where('message_id', $this->messageId)
            ->where('destination', 'telegram-support-topic')
            ->where('status', '!=', DeliveryOperation::STATUS_DELIVERED)
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => 'Job exhausted retries: ' . $exception::class,
            ]);

        Log::channel('app')->error('Telegram support mirror permanently failed', [
            'source' => 'telegram_support_mirror_failed',
            'bot_user_id' => $this->botUserId,
            'message_id' => $this->messageId,
            'error_class' => $exception::class,
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildTelegramRequest(Message $message, string $groupId, ?int $topicId): array
    {
        $base = ['chat_id' => $groupId];
        if (! empty($topicId)) {
            $base['message_thread_id'] = $topicId;
        }

        if ($message->message_type !== 'incoming') {
            return ['sendMessage', $base + ['text' => (string) $this->text]];
        }

        $attachment = $message->attachments->first();
        if ($attachment === null) {
            return ['sendMessage', $base + ['text' => (string) ($this->text ?? $message->text)]];
        }

        [$method, $field] = match ($attachment->file_type) {
            'photo' => ['sendPhoto', 'photo'],
            'voice' => ['sendVoice', 'voice'],
            'sticker' => ['sendSticker', 'sticker'],
            'video_note' => ['sendVideoNote', 'video_note'],
            default => ['sendDocument', 'document'],
        };

        $params = $base + [$field => $attachment->file_id];
        if (in_array($method, ['sendPhoto', 'sendDocument'], true) && is_string($message->text) && trim($message->text) !== '') {
            $params['caption'] = $message->text;
        }

        return [$method, $params];
    }

    private function createTopicThenRetry(): void
    {
        TopicCreateJob::withChain([
            new self($this->botUserId, $this->messageId, $this->text, $this->traceId),
        ])->onQueue('telegram-mirror')->dispatch($this->botUserId);
    }

    private function reopenTopic(BotUser $botUser, string $groupId, DeliveryOperation $operation): bool
    {
        if (empty($botUser->topic_id)) {
            $this->createTopicThenRetry();

            return false;
        }

        $response = (new TelegramMethods())->sendQueryTelegram('reopenForumTopic', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
        ]);

        if ($response->ok || ($response->response_code === 400 && $response->type_error === 'TOPIC_NOT_MODIFIED')) {
            $botUser->update(['is_closed' => false, 'closed_at' => null]);

            return true;
        }

        if ($response->response_code === 400 && in_array($response->type_error, [
            'TOPIC_NOT_FOUND',
            'TOPIC_DELETED',
            'TOPIC_ID_INVALID',
        ], true)) {
            $botUser->update(['topic_id' => null]);
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => 'Telegram topic disappeared while reopening',
            ]);
            $this->createTopicThenRetry();

            return false;
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_RETRYING,
            'last_error' => sprintf('Topic reopen failed: code=%s type=%s', $response->response_code, $response->type_error),
        ]);

        if ($response->response_code === 429) {
            $this->release((int) ($response->rawData['parameters']['retry_after'] ?? 3));

            return false;
        }

        throw new RuntimeException((string) $operation->last_error);
    }

    private function isTopicClosed(\App\Modules\Telegram\DTOs\TelegramAnswerDto $response): bool
    {
        $description = (string) ($response->rawData['description'] ?? '');

        return $response->type_error === 'TOPIC_CLOSED'
            || str_contains($description, 'TOPIC_CLOSED')
            || str_contains(strtolower($description), 'topic is closed');
    }
}
