<?php

namespace App\Modules\Telegram\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Telegram\Support\TelegramPipelineTrace;
use App\Modules\Translation\Support\TelegramMarkupSanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class SendTelegramMessageJob extends AbstractSendMessageJob
{
    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    /** @var TelegramUpdateDto */
    public mixed $updateDto;

    /** @var TGTextMessageDto */
    public mixed $queryParams;

    public string $typeMessage;

    private TelegramMethods $telegramMethods;

    public float $queuedAt;

    public string $traceId;

    public function __construct(
        int $botUserId,
        TelegramUpdateDto $updateDto,
        TGTextMessageDto $queryParams,
        string $typeMessage,
        ?TelegramMethods $telegramMethods = null,
    ) {
        $this->botUserId = $botUserId;
        $this->updateDto = $updateDto;
        $this->queryParams = $queryParams;
        $this->typeMessage = $typeMessage;
        $this->queuedAt = microtime(true);
        $this->traceId = TelegramPipelineTrace::id($updateDto);
        $this->onQueue('telegram-interactive');

        $this->telegramMethods = $telegramMethods ?? new TelegramMethods();

        TelegramPipelineTrace::log('job_queued', $this->traceId, [
            'job' => self::class,
            'queue' => 'telegram-interactive',
            'bot_user_id' => $botUserId,
            'method' => $queryParams->methodQuery,
            'type_message' => $typeMessage,
        ]);
    }

    public function handle(): void
    {
        try {
            TelegramPipelineTrace::log('job_started', $this->traceId, [
                'job' => self::class,
                'queue' => 'telegram-interactive',
                'queue_wait_ms' => (int) round((microtime(true) - $this->queuedAt) * 1000),
                'attempt' => $this->attempts(),
            ]);

            $botUser = BotUser::find($this->botUserId);

            if ($botUser === null) {
                throw new \RuntimeException("Telegram bot user {$this->botUserId} was not found");
            }

            $methodQuery = $this->queryParams->methodQuery;
            $params = $this->queryParams->toArray();

            // Сначала фиксируем входящее сообщение, а Telegram support обслуживает
            // отдельная очередь: недоступность группы не блокирует клиента.
            if ($this->typeMessage === 'incoming') {
                $this->saveIncomingBeforeDelivery($botUser);
                $message = $this->persistedMessage($botUser);
                if ($message !== null) {
                    TelegramPipelineTrace::log('message_committed', $this->traceId, [
                        'message_id' => $message->id,
                        'bot_user_id' => $message->bot_user_id,
                    ]);
                    $this->queueIncomingMirror($botUser, $message);
                }

                return;
            }

            $deliveryOperation = $this->beginDeliveryOperation($botUser, $methodQuery, $params);
            if ($deliveryOperation?->status === DeliveryOperation::STATUS_DELIVERED) {
                return;
            }

            TelegramPipelineTrace::log('telegram_api_started', $this->traceId, [
                'method' => $methodQuery,
                'bot_user_id' => $this->botUserId,
            ]);
            $apiStartedAt = microtime(true);

            $response = $this->telegramMethods->sendQueryTelegram(
                $methodQuery,
                $params,
                $this->queryParams->token
            );

            TelegramPipelineTrace::log('telegram_api_completed', $this->traceId, [
                'method' => $methodQuery,
                'duration_ms' => (int) round((microtime(true) - $apiStartedAt) * 1000),
                'ok' => $response->ok,
                'response_code' => $response->response_code,
            ]);

            if ($response->ok === true) {
                if ($methodQuery !== 'editMessageText' && $methodQuery !== 'editMessageCaption') {
                    $this->saveMessage($botUser, $response);
                    $message = $this->persistedMessage($botUser);
                    $this->completeDeliveryOperation($deliveryOperation, $message, $response);
                    if ($message !== null) {
                        TelegramPipelineTrace::log('message_committed', $this->traceId, [
                            'message_id' => $message->id,
                            'bot_user_id' => $message->bot_user_id,
                        ]);
                    }
                    $this->queueOutgoingMirror($botUser, $message);
                    if (!empty($botUser->topic_id) && !$this->shouldSkipTopicIconUpdate()) {
                        $this->updateTopic($botUser, $this->typeMessage);
                    }
                    return;
                }
            } else {
                $this->markDeliveryOperationFailed($deliveryOperation, $response);
                $this->telegramResponseHandler($response);
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log($e->getCode() === 1 ? 'warning' : 'error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            TelegramPipelineTrace::log('pipeline_failed', $this->traceId, [
                'stage' => 'interactive_delivery',
                'error_class' => $e::class,
            ]);

            throw $e;
        }
    }

    /**
     * Save message to database after successful sending.
     *
     * @param BotUser $botUser
     * @param mixed   $resultQuery
     *
     * @return void
     */
    protected function saveMessage(BotUser $botUser, mixed $resultQuery): void
    {
        if (!$resultQuery instanceof TelegramAnswerDto) {
            throw new \Exception('Expected TelegramAnswerDto', 1);
        }

        $text = $this->typeMessage === 'incoming'
            ? $this->incomingText()
            : ($this->queryParams->text ?? $this->queryParams->caption ?? null);

        $sourceId = $this->typeMessage === 'outgoing' && $this->updateDto->updateId > 0
            ? $this->updateDto->updateId
            : $this->updateDto->messageId;

        $message = Message::firstOrNew([
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'message_type' => $this->typeMessage,
            'from_id' => $sourceId,
        ]);

        if (!$message->exists) {
            $message->to_id = $resultQuery->message_id ?? 0;
            $message->text = $text;
            if (Message::supportsStructuralKind()) {
                $message->message_kind = $this->queryParams->messageKind ?? Message::KIND_CHAT;
            }
            if (Message::supportsDeliveryStatus()) {
                $message->delivery_status = Message::DELIVERY_DELIVERED;
            }
        } else {
            if (($message->to_id ?? 0) === 0 && !empty($resultQuery->message_id)) {
                $message->to_id = $resultQuery->message_id;
            }
            if ($message->text === null && $text !== null) {
                $message->text = $text;
            }
        }

        $message->save();

        if ($this->typeMessage === 'incoming' && !empty($this->updateDto->fileId) && !$message->attachments()->exists()) {
            $message->attachments()->create([
                'file_id' => $this->updateDto->fileId,
                'file_type' => $this->updateDto->fileType ?? 'document',
            ]);
        }

        if ($this->typeMessage === 'outgoing') {
            $fileId = $this->queryParams->photo
                ?? $this->queryParams->document
                ?? $this->queryParams->voice
                ?? $this->queryParams->sticker
                ?? $this->queryParams->video_note
                ?? $this->queryParams->file_id;

            $fileType = match (true) {
                !empty($this->queryParams->photo) => 'photo',
                !empty($this->queryParams->document) => 'document',
                !empty($this->queryParams->voice) => 'voice',
                !empty($this->queryParams->sticker) => 'sticker',
                !empty($this->queryParams->video_note) => 'video_note',
                !empty($this->queryParams->file_id) => 'document',
                default => null,
            };

            if (!empty($fileId) && !empty($fileType) && !$message->attachments()->exists()) {
                $message->attachments()->create([
                    'file_id' => $fileId,
                    'file_type' => $fileType,
                ]);
            }
        }
    }

    /**
     * Edit message in database.
     *
     * @param mixed $resultQuery
     *
     * @return void
     */
    protected function editMessage(BotUser $botUser, mixed $resultQuery): void
    {
        //
    }

    private function saveIncomingBeforeDelivery(BotUser $botUser): void
    {
        if (($this->updateDto->messageId ?? 0) <= 0) {
            return;
        }

        $message = Message::firstOrCreateForSourceEvent('telegram', $this->updateDto->messageId, [
            'bot_user_id' => $botUser->id,
            'message_type' => 'incoming',
            'message_kind' => Message::KIND_CHAT,
            'delivery_status' => Message::DELIVERY_DELIVERED,
            'from_id' => $this->updateDto->messageId,
            'to_id' => 0,
            'text' => $this->incomingText(),
        ]);
        if ($message->text === null && $this->incomingText() !== null) {
            $message->update(['text' => $this->incomingText()]);
        }

        if (!empty($this->updateDto->fileId) && !$message->attachments()->exists()) {
            $message->attachments()->create([
                'file_id' => $this->updateDto->fileId,
                'file_type' => $this->updateDto->fileType ?? 'document',
            ]);
        }
    }

    private function shouldSkipTopicIconUpdate(): bool
    {
        $text = $this->typeMessage === 'incoming'
            ? ($this->updateDto->text ?? '')
            : ($this->queryParams->text ?? '');

        if ($this->typeMessage === 'incoming' && trim((string) $text) === '/start') {
            return true;
        }

        if ($this->typeMessage === 'outgoing' && app(SupportLanguageService::class)->isSelectorText((string) $text)) {
            return true;
        }

        if ($this->typeMessage === 'outgoing' && app(SupportLanguageService::class)->isLanguageCallback($this->updateDto->callbackData ?? null)) {
            return true;
        }

        return false;
    }

    private function incomingText(): ?string
    {
        $text = $this->updateDto->text
            ?? $this->updateDto->caption
            ?? $this->queryParams->text
            ?? null;

        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        if ($this->queryParams->latitude !== null && $this->queryParams->longitude !== null) {
            return sprintf(
                '📍 Геолокация: %s, %s',
                $this->queryParams->latitude,
                $this->queryParams->longitude,
            );
        }

        return null;
    }

    private function queueOutgoingMirror(BotUser $botUser, ?Message $message): void
    {
        if ($this->typeMessage !== 'outgoing' || $message === null) {
            return;
        }

        if ($botUser->platform !== 'telegram') {
            return;
        }

        // Выбор языка — техническое клиентское сообщение. Оно сохраняется для
        // дедупликации, но не должно появляться у операторов как ответ бота.
        if (
            $message->message_kind === Message::KIND_LANGUAGE_SELECTOR
            || app(SupportLanguageService::class)->isSelectorText($message->text)
        ) {
            return;
        }

        // Сообщение оператора уже пришло из этой supergroup-темы. Повторное
        // зеркало создавало ложное «🤖 Бот клиенту» рядом с исходным ответом.
        if ($this->updateDto->typeSource === 'supergroup') {
            return;
        }

        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($groupId === '') {
            return;
        }

        $text = $this->queryParams->text ?? $this->queryParams->caption ?? null;
        if (!is_string($text) || trim($text) === '') {
            return;
        }

        $mirrorText = app(TelegramMarkupSanitizer::class)->toPlainText("🤖 Бот клиенту:\n" . $text);

        SendTelegramMirrorJob::dispatch($botUser->id, $message->id, $mirrorText, $this->traceId)
            ->afterCommit();
        TelegramPipelineTrace::log('support_mirror_queued', $this->traceId, [
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
        ]);
    }

    private function queueIncomingMirror(BotUser $botUser, Message $message): void
    {
        if ($botUser->platform !== 'telegram') {
            return;
        }

        $text = is_string($message->text) && trim($message->text) !== ''
            ? app(TelegramMarkupSanitizer::class)->toPlainText($message->text)
            : null;
        if ($text === null && !$message->attachments()->exists()) {
            return;
        }

        SendTelegramMirrorJob::dispatch(
            $botUser->id,
            $message->id,
            $text,
            $this->traceId,
        )->afterCommit();

        TelegramPipelineTrace::log('support_mirror_queued', $this->traceId, [
            'bot_user_id' => $botUser->id,
            'message_id' => $message->id,
            'direction' => 'incoming',
        ]);
    }

    private function persistedMessage(BotUser $botUser): ?Message
    {
        $sourceId = $this->typeMessage === 'outgoing' && $this->updateDto->updateId > 0
            ? $this->updateDto->updateId
            : $this->updateDto->messageId;

        return Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('platform', $botUser->platform)
            ->where('message_type', $this->typeMessage)
            ->where('from_id', $sourceId)
            ->first();
    }

    private function beginDeliveryOperation(BotUser $botUser, string $method, array $params): ?DeliveryOperation
    {
        if ($this->typeMessage !== 'outgoing' || !in_array($method, ['sendMessage', 'sendPhoto', 'sendDocument', 'sendVoice'], true)) {
            return null;
        }

        $fingerprint = hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $operationKey = hash('sha256', implode('|', [
            $this->traceId,
            $botUser->id,
            (string) ($params['chat_id'] ?? ''),
            $method,
            $fingerprint,
        ]));

        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => $operationKey],
            [
                'bot_user_id' => $botUser->id,
                'trace_id' => $this->traceId,
                'destination' => 'telegram-client',
                'operation' => $method,
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if ($operation->status !== DeliveryOperation::STATUS_DELIVERED) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_PROCESSING,
                'attempts' => $operation->attempts + 1,
                'started_at' => now(),
                'last_error' => null,
            ]);
        }

        return $operation->refresh();
    }

    private function completeDeliveryOperation(?DeliveryOperation $operation, ?Message $message, TelegramAnswerDto $response): void
    {
        if ($operation === null) {
            return;
        }

        $operation->update([
            'message_id' => $message?->id,
            'external_message_id' => $response->message_id,
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    private function markDeliveryOperationFailed(?DeliveryOperation $operation, TelegramAnswerDto $response): void
    {
        if ($operation === null) {
            return;
        }

        $retryable = $response->response_code === 429 || ($response->response_code ?? 0) >= 500;
        $operation->update([
            'status' => $retryable ? DeliveryOperation::STATUS_RETRYING : DeliveryOperation::STATUS_FAILED,
            'last_error' => sprintf('code=%s type=%s', $response->response_code, $response->type_error),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DeliveryOperation::query()
            ->where('trace_id', $this->traceId)
            ->where('destination', 'telegram-client')
            ->where('status', '!=', DeliveryOperation::STATUS_DELIVERED)
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => 'Job exhausted retries: ' . $exception::class,
            ]);

        Log::channel('app')->error('Telegram client delivery permanently failed', [
            'source' => 'telegram_client_delivery_failed',
            'bot_user_id' => $this->botUserId,
            'trace_id' => $this->traceId,
            'error_class' => $exception::class,
        ]);
    }
}
