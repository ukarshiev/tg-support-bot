<?php

namespace App\Modules\Vk\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MirrorVkIncomingMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $botUserId,
        public readonly int $messageId,
        public readonly string $eventId,
        public readonly ?array $geo = null,
        private readonly ?TelegramMethods $telegramMethods = null,
    ) {
        $this->onQueue('telegram-mirror');
    }

    public function uniqueId(): string
    {
        return 'vk-incoming:' . $this->messageId;
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    public function handle(): void
    {
        $botUser = BotUser::find($this->botUserId);
        $message = Message::with('attachments')->find($this->messageId);
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        if ($botUser === null || $message === null || $groupId === '') {
            return;
        }

        if (empty($botUser->topic_id)) {
            TopicCreateJob::withChain([
                new self($this->botUserId, $this->messageId, $this->eventId, $this->geo),
            ])->onQueue('telegram-mirror')->dispatch($this->botUserId);

            return;
        }

        $parts = $this->parts($message);
        foreach ($parts as $index => $part) {
            $this->deliverPart($botUser, $message, $groupId, $index, $part);
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'icon_custom_emoji_id' => __('icons.incoming'),
        ]));
    }

    /**
     * @return array<int, array{method: string, params: array<string, mixed>}>
     */
    private function parts(Message $message): array
    {
        if ($message->attachments->isEmpty() && !empty($this->geo['coordinates'])) {
            return [[
                'method' => 'sendLocation',
                'params' => [
                    'latitude' => $this->geo['coordinates']['latitude'],
                    'longitude' => $this->geo['coordinates']['longitude'],
                ],
            ]];
        }

        if ($message->attachments->isEmpty()) {
            return trim((string) $message->text) === '' ? [] : [[
                'method' => 'sendMessage',
                'params' => ['text' => $message->text],
            ]];
        }

        $parts = [];
        foreach ($message->attachments as $index => $attachment) {
            [$method, $field] = match ($attachment->file_type) {
                'photo' => ['sendPhoto', 'photo'],
                'voice' => ['sendVoice', 'voice'],
                default => ['sendDocument', 'document'],
            };

            $params = [$field => $attachment->file_id];
            if ($index === 0 && trim((string) $message->text) !== '') {
                $params['caption'] = $message->text;
            }
            $parts[] = ['method' => $method, 'params' => $params];
        }

        return $parts;
    }

    /**
     * @param array{method: string, params: array<string, mixed>} $part
     */
    private function deliverPart(
        BotUser $botUser,
        Message $message,
        string $groupId,
        int $index,
        array $part,
    ): void {
        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', "vk-mirror|{$message->id}|{$botUser->topic_id}|{$index}")],
            [
                'bot_user_id' => $botUser->id,
                'message_id' => $message->id,
                'trace_id' => $this->eventId,
                'destination' => 'telegram-support-topic',
                'operation' => $part['method'],
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            return;
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = ($this->telegramMethods ?? new TelegramMethods())->sendQueryTelegram(
                $part['method'],
                [
                    'chat_id' => $groupId,
                    'message_thread_id' => $botUser->topic_id,
                    ...$part['params'],
                ],
            );
        } catch (Throwable $e) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => $e::class,
            ]);
            throw $e;
        }

        if ($response->ok) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => $response->message_id,
                'delivered_at' => now(),
            ]);
            if ((int) $message->to_id === 0 && $response->message_id !== null) {
                $message->update(['to_id' => $response->message_id]);
            }

            return;
        }

        $this->handleFailure($operation, $response);
    }

    private function handleFailure(DeliveryOperation $operation, TelegramAnswerDto $response): void
    {
        if ($response->response_code === 400 && in_array($response->type_error, [
            'TOPIC_NOT_FOUND',
            'TOPIC_DELETED',
            'TOPIC_ID_INVALID',
        ], true)) {
            BotUser::whereKey($this->botUserId)->update(['topic_id' => null]);
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => 'Telegram topic disappeared; recreation queued',
            ]);
            TopicCreateJob::withChain([
                new self($this->botUserId, $this->messageId, $this->eventId, $this->geo),
            ])->onQueue('telegram-mirror')->dispatch($this->botUserId);

            return;
        }

        $transient = $response->response_code === 429 || ($response->response_code ?? 0) >= 500;
        $operation->update([
            'status' => $transient ? DeliveryOperation::STATUS_RETRYING : DeliveryOperation::STATUS_FAILED,
            'last_error' => sprintf('code=%s type=%s', $response->response_code, $response->type_error),
        ]);

        if ($transient) {
            throw new RuntimeException('Transient Telegram mirror failure');
        }

        Log::channel('app')->warning('VK mirror rejected by Telegram', [
            'source' => 'vk_telegram_mirror_failed',
            'bot_user_id' => $this->botUserId,
            'message_id' => $this->messageId,
            'response_code' => $response->response_code,
            'type_error' => $response->type_error,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        DeliveryOperation::query()
            ->where('message_id', $this->messageId)
            ->where('destination', 'telegram-support-topic')
            ->whereIn('status', [DeliveryOperation::STATUS_PENDING, DeliveryOperation::STATUS_PROCESSING, DeliveryOperation::STATUS_RETRYING])
            ->update(['status' => DeliveryOperation::STATUS_FAILED, 'last_error' => $exception::class]);
    }
}
