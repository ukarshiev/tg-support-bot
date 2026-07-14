<?php

namespace App\Modules\Max\Jobs;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MirrorMaxIncomingMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 45;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $botUserId,
        public readonly int $messageId,
        public readonly string $eventId,
        public readonly string $externalMessageId,
        private readonly ?TelegramMethods $telegramMethods = null,
    ) {
        $this->onQueue('telegram-mirror');
    }

    public function uniqueId(): string
    {
        return 'max-incoming:' . $this->messageId;
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
                new self($this->botUserId, $this->messageId, $this->eventId, $this->externalMessageId),
            ])->onQueue('telegram-mirror')->dispatch($this->botUserId);

            return;
        }

        foreach ($this->parts($message) as $index => $part) {
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
     * @return array<int, array{method: string, params: array<string, mixed>, remote_url?: string, file_field?: string}>
     */
    private function parts(Message $message): array
    {
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
            $params = [];
            if ($index === 0 && trim((string) $message->text) !== '') {
                $params['caption'] = $message->text;
            }
            $parts[] = [
                'method' => $method,
                'params' => $params,
                'remote_url' => $attachment->file_id,
                'file_field' => $field,
            ];
        }

        return $parts;
    }

    /**
     * @param array{method: string, params: array<string, mixed>, remote_url?: string, file_field?: string} $part
     */
    private function deliverPart(
        BotUser $botUser,
        Message $message,
        string $groupId,
        int $index,
        array $part,
    ): void {
        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', "max-mirror|{$message->id}|{$botUser->topic_id}|{$index}")],
            [
                'bot_user_id' => $botUser->id,
                'message_id' => $message->id,
                'trace_id' => 'max:' . $this->externalMessageId . ':' . $this->eventId,
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

        $tempPath = null;
        try {
            $params = [
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                ...$part['params'],
            ];
            if (isset($part['remote_url'], $part['file_field'])) {
                $fileResponse = Http::timeout(15)->retry(2, 200)->get($part['remote_url']);
                if ($fileResponse->failed()) {
                    throw new RuntimeException('MAX CDN download failed with HTTP ' . $fileResponse->status());
                }
                $tempPath = tempnam(sys_get_temp_dir(), 'max_');
                if ($tempPath === false || file_put_contents($tempPath, $fileResponse->body()) === false) {
                    throw new RuntimeException('MAX CDN temporary file could not be written');
                }
                $params['uploaded_file_path'] = $tempPath;
            }

            $response = ($this->telegramMethods ?? new TelegramMethods())->sendQueryTelegram($part['method'], $params);
        } catch (Throwable $e) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => $e::class,
            ]);
            throw $e;
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
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
                new self($this->botUserId, $this->messageId, $this->eventId, $this->externalMessageId),
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

        Log::channel('app')->warning('MAX mirror rejected by Telegram', [
            'source' => 'max_telegram_mirror_failed',
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
