<?php

namespace App\Modules\Telegram\Controllers;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Ai\Actions\AcceptAiDraftReplyMessage;
use App\Modules\Ai\Actions\EditAiMessage;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Ai\Services\ShouldAiReply;
use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Telegram\Actions\BannedContactMessage;
use App\Modules\Telegram\Actions\CloseTopic;
use App\Modules\Telegram\Actions\SelectLanguage;
use App\Modules\Telegram\Actions\SendAiAnswerMessage;
use App\Modules\Telegram\Actions\SendBannedMessage;
use App\Modules\Telegram\Actions\SendContactMessage;
use App\Modules\Telegram\Actions\SendLanguageSelectionMessage;
use App\Modules\Telegram\Actions\SendStartMessage;
use App\Modules\Telegram\Actions\ShowLanguageSelectionPage;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Telegram\Services\Commands\AutoAiModeCommand;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Telegram\Services\Tg\TgEditMessageService;
use App\Modules\Telegram\Services\Tg\TgMessageService;
use App\Modules\Telegram\Services\TgExternal\TgExternalEditService;
use App\Modules\Telegram\Services\TgExternal\TgExternalMessageService;
use App\Modules\Telegram\Services\TgMax\TgMaxMessageService;
use App\Modules\Telegram\Services\TgVk\TgVkEditService;
use App\Modules\Telegram\Services\TgVk\TgVkMessageService;
use App\Modules\Telegram\Support\TelegramPipelineTrace;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramBotController
{
    private TelegramUpdateDto $dataHook;

    protected ?string $platform;

    private ?BotUser $botUser;

    private ?string $incomingDeduplicationKey = null;

    private ?int $incomingDeliveryOperationId = null;

    public function __construct(Request $request)
    {
        $dataHook = TelegramUpdateDto::fromRequest($request);
        if (empty($dataHook)) {
            abort(200);
        }
        $this->dataHook = $dataHook;

        if (app(AutoAiModeCommand::class)->handleIfCommand($this->dataHook)) {
            abort(200);
        }

        if ($this->dataHook->typeSource === 'private') {
            $this->botUser = (new BotUser())->getUserByChatId($this->dataHook->chatId, 'telegram');
            $this->platform = 'telegram';
        } else {
            $this->botUser = (new BotUser())->getByTopicId($this->dataHook->messageThreadId);
            $this->platform = $this->botUser->platform ?? null;
        }

        if (empty($this->botUser) || empty($this->platform)) {
            abort(200);
        }
    }

    /**
     * Check type source
     *
     * @return bool
     */
    protected function isSupergroup(): bool
    {
        return $this->dataHook->typeSource === 'supergroup';
    }

    /**
     * Check message
     *
     * @return void
     */
    protected function checkBotQuery(): void
    {
        if ($this->dataHook->pinnedMessageStatus) {
            return;
        }

        if ($this->dataHook->typeQuery === 'callback_query') {
            if (app(SupportLanguageService::class)->isPageCallback($this->dataHook->callbackData)) {
                app(ShowLanguageSelectionPage::class)->execute($this->botUser, $this->dataHook);
            } elseif (app(SupportLanguageService::class)->isLanguageCallback($this->dataHook->callbackData)) {
                app(SelectLanguage::class)->execute($this->botUser, $this->dataHook);
            } elseif (str_contains($this->dataHook->callbackData, 'topic_user_ban_')) {
                $banStatus = $this->dataHook->callbackData === 'topic_user_ban_true';
                app(BannedContactMessage::class)->execute($this->botUser, $banStatus, $this->dataHook->messageId);
            } elseif ($this->dataHook->callbackData === 'close_topic') {
                app(CloseTopic::class)->execute($this->botUser);
            } elseif (str_starts_with((string) $this->dataHook->callbackData, 'feedback_rate_')) {
                app(HandleFeedbackRating::class)->execute(
                    callbackData: (string) $this->dataHook->callbackData,
                    messageId: $this->dataHook->messageId,
                    chatId: $this->dataHook->typeSource === 'private' ? $this->dataHook->chatId : null,
                    actor: $this->botUser,
                );
            }

            return;
        }
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function bot_query(): void
    {
        $traceId = TelegramPipelineTrace::id($this->dataHook);

        try {
            $this->checkBotQuery();
            if ($this->dataHook->typeQuery === 'callback_query') {
                return;
            }
            if ($this->dataHook->editedTopicStatus && $this->dataHook->typeSource === 'supergroup') {
                SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
                    'methodQuery' => 'deleteMessage',
                    'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
                    'message_id' => $this->dataHook->messageId,
                ]));
            } elseif (!$this->dataHook->isBot) {
                if ($this->dataHook->typeSource === 'supergroup') {
                    if ($this->dataHook->text === '/contact' && $this->isSupergroup()) {
                        app(SendContactMessage::class)->execute($this->botUser);
                        return;
                    }
                }

                switch ($this->platform) {
                    case 'telegram':
                        $this->controllerPlatformTg();
                        break;

                    case 'vk':
                        $this->controllerPlatformVk();
                        break;

                    case 'max':
                        $this->controllerPlatformMax();
                        break;

                    case 'ignore':
                        return;

                    default:
                        $this->controllerExternalPlatform();
                        break;
                }
            }

            $this->completeIncomingProcessing();
        } catch (\Throwable $e) {
            if ($this->incomingDeduplicationKey !== null) {
                Cache::forget($this->incomingDeduplicationKey);
            }

            $this->markIncomingProcessingForRetry($e);

            throw $e;
        } finally {
            TelegramPipelineTrace::log('controller_completed', $traceId, [
                'update_id' => $this->dataHook->updateId,
                'type_query' => $this->dataHook->typeQuery,
                'bot_user_id' => $this->botUser?->id,
            ]);
        }
    }

    /**
     * Controller tg message
     *
     * @return void
     */
    private function controllerPlatformTg(): void
    {
        if ($this->botUser->isBanned() && $this->dataHook->typeSource === 'private') {
            app(SendBannedMessage::class)->execute($this->botUser);
            return;
        } elseif ($this->dataHook->aiTechMessage) {
            if (str_contains((string) $this->dataHook->text, 'ai_message_edit_')) {
                app(EditAiMessage::class)->execute($this->dataHook);
            }
        } else {
            switch ($this->dataHook->typeQuery) {
                case 'message':
                    if (app(AcceptAiDraftReplyMessage::class)->handle($this->dataHook, $this->botUser)) {
                        return;
                    }

                    if (str_contains((string) $this->dataHook->text, '/ai_generate') && $this->isSupergroup()) {
                        app(SendAiAnswerMessage::class)->execute($this->dataHook);
                        return;
                    }

                    if ($this->shouldSkipDuplicateIncomingPrivateMessage()) {
                        return;
                    }

                    $this->reopenClosedDialogForIncomingPrivateMessage();
                    $this->notifyIncomingMessage();

                    if (in_array($this->dataHook->text, ['/lang', '/language'], true) && !$this->isSupergroup()) {
                        app(SendStartMessage::class)->force($this->dataHook);
                    } elseif ($this->dataHook->text === '/start' && !$this->isSupergroup()) {
                        app(SendStartMessage::class)->execute($this->dataHook);
                    } elseif ($this->dataHook->typeSource === 'private' && empty($this->botUser->preferred_language_code)) {
                        app(SendLanguageSelectionMessage::class)->execute($this->dataHook);
                    }

                    $this->maybeDispatchAi();
                    break;

                case 'edited_message':
                    (new TgEditMessageService($this->dataHook))->handleUpdate();
                    break;

                default:
                    throw new \Exception("Unknown event type: {$this->dataHook->typeQuery}");
            }
        }
    }

    /**
     * Handle an incoming user message: always save to DB and, when the
     * Telegram supergroup is configured (telegram.token + telegram.group_id),
     * also forward to the user's forum topic.
     *
     * Admin panel workspace always shows the message from the DB.
     * The supergroup is an optional addition — enabled automatically when
     * the Telegram channel integration is fully configured.
     *
     * When the group is NOT configured, the message is persisted directly
     * to the `messages` table so the admin workspace still shows it.
     * The two branches are mutually exclusive: no duplicate rows are created.
     *
     * @return void
     */
    private function notifyIncomingMessage(): void
    {
        // Forward to supergroup only when Telegram channel is fully configured.
        if (app(ChannelStatusService::class)->telegram()['connected']
            && !empty((string) app(SettingsService::class)->get('telegram.group_id'))
        ) {
            // Group path: TgMessageService dispatches SendTelegramMessageJob.
            // If the topic does not exist yet, SendTelegramMessageJob creates it
            // through one chained TopicCreateJob and then retries the forward.
            // Do not dispatch TopicCreateJob here too: two independent topic
            // creation jobs can race and create duplicate Telegram forum topics
            // for the same client.
            (new TgMessageService($this->dataHook))->handleUpdate();
        } else {
            // Group-OFF path: persist the incoming message directly so the admin
            // workspace shows it even when no supergroup is configured.
            $this->persistIncomingTelegramMessage();
        }
    }

    /**
     * Telegram long polling can redeliver the same private message when the
     * poller loses its in-memory offset after network/restart noise. We must not
     * save it or trigger AI twice.
     */
    private function shouldSkipDuplicateIncomingPrivateMessage(): bool
    {
        if ($this->dataHook->typeSource !== 'private' || $this->dataHook->typeQuery !== 'message') {
            return false;
        }

        if (($this->dataHook->messageId ?? 0) <= 0 || $this->botUser === null) {
            return false;
        }

        $alreadySaved = Message::query()
            ->where('bot_user_id', $this->botUser->id)
            ->where('platform', $this->botUser->platform)
            ->where('message_type', 'incoming')
            ->where('from_id', $this->dataHook->messageId)
            ->exists();

        $operationKey = hash('sha256', sprintf(
            'telegram-ingress:%s:%s',
            $this->botUser->id,
            $this->dataHook->messageId,
        ));
        $operation = DeliveryOperation::where('operation_key', $operationKey)->first();

        if ($operation?->status === DeliveryOperation::STATUS_DELIVERED) {
            $this->logDuplicateIncoming($alreadySaved, false, 'delivered_operation');

            return true;
        }

        // Запись могла появиться до внедрения журнала операций. Считаем такое
        // событие завершённым, чтобы исторический update не запустил ИИ повторно.
        if ($operation === null && $alreadySaved) {
            DeliveryOperation::create([
                'operation_key' => $operationKey,
                'bot_user_id' => $this->botUser->id,
                'trace_id' => TelegramPipelineTrace::id($this->dataHook),
                'destination' => 'internal',
                'operation' => 'telegram_ingress',
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'attempts' => 1,
                'started_at' => now(),
                'delivered_at' => now(),
            ]);
            $this->logDuplicateIncoming(true, false, 'legacy_saved_message');

            return true;
        }

        $operation ??= DeliveryOperation::firstOrCreate(
            ['operation_key' => $operationKey],
            [
                'bot_user_id' => $this->botUser->id,
                'trace_id' => TelegramPipelineTrace::id($this->dataHook),
                'destination' => 'internal',
                'operation' => 'telegram_ingress',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            $this->logDuplicateIncoming($alreadySaved, false, 'delivered_operation');

            return true;
        }

        $cacheKey = sprintf(
            'telegram:incoming:%s:%s:%s',
            $this->botUser->platform,
            $this->botUser->chat_id,
            $this->dataHook->messageId,
        );
        $firstSeen = Cache::add($cacheKey, true, now()->addMinutes(2));

        if (! $firstSeen) {
            $this->logDuplicateIncoming($alreadySaved, true, 'concurrent_claim');

            return true;
        }

        // Если дальнейшая обработка завершится исключением, bot_query()
        // удалит этот claim, и poller безопасно повторит тот же update.
        $this->incomingDeduplicationKey = $cacheKey;
        $this->incomingDeliveryOperationId = $operation->id;
        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'last_error' => null,
            'started_at' => now(),
        ]);

        return false;
    }

    private function completeIncomingProcessing(): void
    {
        if ($this->incomingDeliveryOperationId === null) {
            return;
        }

        DeliveryOperation::whereKey($this->incomingDeliveryOperationId)->update([
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'delivered_at' => now(),
            'last_error' => null,
        ]);
    }

    private function markIncomingProcessingForRetry(\Throwable $exception): void
    {
        if ($this->incomingDeliveryOperationId === null) {
            return;
        }

        DeliveryOperation::whereKey($this->incomingDeliveryOperationId)->update([
            'status' => DeliveryOperation::STATUS_RETRYING,
            'last_error' => $exception::class,
        ]);
    }

    private function logDuplicateIncoming(bool $alreadySaved, bool $cacheHit, string $reason): void
    {
        Log::channel('app')->info('TelegramBotController: duplicate incoming private message skipped', [
            'source' => 'telegram_duplicate_incoming_skipped',
            'bot_user_id' => $this->botUser?->id,
            'chat_id' => $this->botUser?->chat_id,
            'message_id' => $this->dataHook->messageId,
            'already_saved' => $alreadySaved,
            'cache_hit' => $cacheHit,
            'reason' => $reason,
        ]);
    }

    /**
     * A new private message from the client means the support dialog is active again.
     *
     * The Telegram topic is reopened later by SendTelegramMessageJob when the group is configured.
     * Here we update BotUser immediately so AI gating sees the dialog as active in the same request.
     */
    private function reopenClosedDialogForIncomingPrivateMessage(): void
    {
        if ($this->dataHook->typeSource !== 'private' || ! $this->botUser?->isClosed()) {
            return;
        }

        // DB открываем сразу для AI-gating, а отдельный маркер гарантирует,
        // что mirror-job физически переоткроет Telegram forum topic.
        Cache::put("telegram:topic-reopen:{$this->botUser->id}", true, now()->addDay());

        $this->botUser->update([
            'is_closed' => false,
            'closed_at' => null,
        ]);

        $this->botUser->refresh();
    }

    /**
     * Persist an incoming Telegram message directly to the `messages` table
     * without routing it through the supergroup.
     *
     * Called only when the Telegram group is not configured. The group-ON path
     * persists via SendTelegramMessageJob::saveMessage() instead, so the two
     * branches are mutually exclusive and produce exactly one row each.
     *
     * @return void
     */
    private function persistIncomingTelegramMessage(): void
    {
        try {
            $message = Message::firstOrCreateForSourceEvent('telegram', $this->dataHook->messageId ?? 0, [
                'bot_user_id' => $this->botUser->id,
                'message_type' => 'incoming',
                'message_kind' => Message::KIND_CHAT,
                'delivery_status' => Message::DELIVERY_DELIVERED,
                'from_id' => $this->dataHook->messageId ?? 0,
                'to_id' => 0,
                // Capture caption for media messages (photo / document) per BR-002a.
                'text' => $this->dataHook->text ?? $this->dataHook->caption ?? null,
            ]);

            // Persist any media attachment so the admin can preview it.
            if (!empty($this->dataHook->fileId)) {
                $message->attachments()->create([
                    'file_id' => $this->dataHook->fileId,
                    'file_type' => $this->dataHook->fileType ?? 'document',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('app')->error(
                'persistIncomingTelegramMessage: failed to persist message',
                ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            );

            throw $e;
        }
    }

    /**
     * Trigger AI generation for the incoming user message, if the gating rules pass.
     *
     * Posts the AI output as the AI bot in the supergroup topic (visual marker for managers)
     * and, when auto-reply is on, also delivers the same text to the user from the main bot.
     *
     * @return void
     */
    private function maybeDispatchAi(): void
    {
        $shouldAiReply = app(ShouldAiReply::class);

        if (!$shouldAiReply->shouldGenerateForUserMessage($this->dataHook, $this->botUser)) {
            return;
        }

        if (
            (bool) app(SettingsService::class)->get('ai.auto_reply')
            && !$shouldAiReply->shouldUseDraftOnly($this->botUser, $this->dataHook->text)
        ) {
            SendAiReplyJob::dispatch($this->botUser->id, $this->dataHook, $this->dataHook->text);
        } else {
            SendAiDraftJob::dispatch($this->botUser->id, $this->dataHook, $this->dataHook->text);
        }
    }

    /**
     * Controller VK message.
     *
     * @return void
     */
    private function controllerPlatformVk(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgVkMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgVkEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Unknown event type: {$this->dataHook->typeQuery}");
        }
    }

    /**
     * Controller Max message.
     *
     * @return void
     */
    private function controllerPlatformMax(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgMaxMessageService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Unknown event type: {$this->dataHook->typeQuery}");
        }
    }

    /**
     * Controller external message.
     *
     * @return void
     */
    private function controllerExternalPlatform(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgExternalMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgExternalEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Unknown event type: {$this->dataHook->typeQuery}");
        }
    }
}
