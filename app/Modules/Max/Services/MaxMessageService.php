<?php

namespace App\Modules\Max\Services;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Ai\Services\ShouldAiReply;
use App\Modules\Max\DTOs\MaxUpdateDto;
use App\Modules\Max\Jobs\MirrorMaxIncomingMessageJob;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Services\ActionService\Send\ToTgMessageService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

class MaxMessageService extends ToTgMessageService
{
    protected string $source = 'max';

    protected string $typeMessage = 'incoming';

    protected mixed $update;

    protected ?BotUser $botUser;

    protected TGTextMessageDto $messageParamsDTO;

    public function __construct(MaxUpdateDto $update)
    {
        parent::__construct($update);
    }

    public function handleUpdate(): void
    {
        if ($this->update->type !== 'message_created') {
            throw new \InvalidArgumentException('Unsupported MAX event type: ' . $this->update->type);
        }

        $message = $this->persistIncomingMessage();

        if (!empty((string) app(SettingsService::class)->get('telegram.group_id')) && $this->hasDeliverableContent()) {
            MirrorMaxIncomingMessageJob::dispatch(
                $this->botUser->id,
                $message->id,
                $this->update->event_id,
                $this->update->id,
            );
        }

        $this->maybeDispatchAi($this->update->text, !empty($this->update->listAttachments));
    }

    private function persistIncomingMessage(): Message
    {
        return DB::transaction(function (): Message {
            BotUser::whereKey($this->botUser->id)->lockForUpdate()->firstOrFail();

            $message = Message::firstOrCreateForSourceEvent('max', $this->update->id, [
                'bot_user_id' => $this->botUser->id,
                'message_type' => 'incoming',
                'message_kind' => Message::KIND_CHAT,
                'delivery_status' => Message::DELIVERY_DELIVERED,
                'from_id' => $this->update->persistenceId(),
                'to_id' => 0,
                'text' => $this->update->text,
            ]);

            foreach ($this->update->listAttachments as $attachment) {
                $message->attachments()->firstOrCreate([
                    'file_id' => $attachment['file_id'],
                    'file_type' => $attachment['type'],
                ], [
                    'file_name' => $attachment['file_name'] ?? null,
                ]);
            }

            $message->load('attachments');

            return $message;
        });
    }

    private function maybeDispatchAi(?string $text, bool $hasMedia): void
    {
        if ($this->botUser === null || empty($this->botUser->preferred_language_code)) {
            return;
        }

        $mediaOnly = ($text === null || trim($text) === '') && $hasMedia;
        $aiText = $mediaOnly ? 'Пользователь отправил медиафайл без подписи.' : $text;
        $shouldAiReply = app(ShouldAiReply::class);
        if (!$shouldAiReply->shouldGenerateForBotUserText($this->botUser, $aiText)) {
            return;
        }

        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => hash('sha256', 'max-ai|' . $this->botUser->id . '|' . $this->update->id)],
            [
                'bot_user_id' => $this->botUser->id,
                'trace_id' => $this->update->event_id,
                'destination' => 'ai-support',
                'operation' => $mediaOnly ? 'draft-media' : 'generate-reply',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );
        if (!$operation->wasRecentlyCreated && $operation->status === DeliveryOperation::STATUS_DELIVERED) {
            return;
        }

        try {
            if ($mediaOnly) {
                SendAiDraftJob::dispatch($this->botUser->id, null, $aiText);
            } elseif (
                (bool) app(SettingsService::class)->get('ai.auto_reply')
                && !$shouldAiReply->shouldUseDraftOnly($this->botUser, $aiText)
            ) {
                SendAiReplyJob::dispatch($this->botUser->id, null, (string) $aiText);
            } else {
                SendAiDraftJob::dispatch($this->botUser->id, null, (string) $aiText);
            }
            $operation->update(['status' => DeliveryOperation::STATUS_DELIVERED, 'delivered_at' => now()]);
        } catch (\Throwable $e) {
            $operation->update(['status' => DeliveryOperation::STATUS_RETRYING, 'last_error' => $e::class]);
            throw $e;
        }
    }

    private function hasDeliverableContent(): bool
    {
        return trim((string) $this->update->text) !== '' || !empty($this->update->listAttachments);
    }

    protected function sendPhoto(): void
    {
    }

    protected function sendDocument(): void
    {
    }

    protected function sendLocation(): void
    {
    }

    protected function sendVoice(): void
    {
    }

    protected function sendSticker(): void
    {
    }

    protected function sendVideoNote(): void
    {
    }

    protected function sendContact(): void
    {
    }

    protected function sendMessage(): void
    {
    }
}
