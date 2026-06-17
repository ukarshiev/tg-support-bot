<?php

namespace App\Modules\Vk\Services;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Ai\Services\ShouldAiReply;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendVkTelegramMessageJob;
use App\Modules\Telegram\Services\ActionService\Send\ToTgMessageService;
use App\Modules\Vk\DTOs\VkUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class VkMessageService extends ToTgMessageService
{
    protected string $source = 'vk';

    protected string $typeMessage = 'incoming';

    protected mixed $update;

    protected ?BotUser $botUser;

    protected TGTextMessageDto $messageParamsDTO;

    public function __construct(VkUpdateDto $update)
    {
        parent::__construct($update);
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function handleUpdate(): void
    {
        try {
            if ($this->update->type !== 'message_new') {
                throw new \Exception('Unknown event type', 1);
            }

            // When the Telegram supergroup is configured, forward the message to
            // the user's forum topic; the job persists the row after the API call.
            // When the group is NOT configured, persist directly so the admin
            // workspace always shows incoming VK messages (group-OFF path).
            if (!empty((string) app(SettingsService::class)->get('telegram.group_id'))) {
                if (!empty($this->update->listFileUrl)) {
                    $this->sendDocument();
                } elseif (!empty($this->update->text)) {
                    $this->sendMessage();
                } elseif (!empty($this->update->geo)) {
                    $this->sendLocation();
                }
            } else {
                $this->persistIncomingVkMessage();
                if (!empty($this->update->text)) {
                    $this->maybeDispatchAi($this->update->text);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log($e->getCode() === 1 ? 'warning' : 'error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    /**
     * Persist an incoming VK message directly to the `messages` table without
     * routing it through the Telegram supergroup.
     *
     * Called only when no telegram.group_id is configured. The group-ON path
     * persists via SendVkTelegramMessageJob::saveMessage() instead, so the two
     * branches are mutually exclusive and produce exactly one row each.
     *
     * @return void
     */
    protected function persistIncomingVkMessage(): void
    {
        $message = Message::create([
            'bot_user_id' => $this->botUser->id,
            'platform' => $this->botUser->platform,
            'message_type' => 'incoming',
            'from_id' => $this->update->id ?? 0,
            'to_id' => 0,
            'text' => $this->update->text ?? null,
        ]);

        foreach ($this->update->listAttachments as $attachment) {
            $message->attachments()->create([
                'file_id' => $attachment['file_id'],
                'file_type' => $attachment['type'],
                'file_name' => $attachment['file_name'] ?? null,
            ]);
        }
    }

    /**
     * @return void
     */
    protected function sendDocument(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendDocument';
        $this->messageParamsDTO->document = $this->update->listFileUrl[0];

        $this->messageParamsDTO->caption = $this->update->text ?? '';

        SendVkTelegramMessageJob::dispatch(
            $this->botUser->id,
            $this->update,
            $this->messageParamsDTO,
        );
    }

    /**
     * @return void
     */
    protected function sendLocation(): void
    {
        $this->messageParamsDTO->methodQuery = 'sendLocation';
        $this->messageParamsDTO->latitude = $this->update->geo['coordinates']['latitude'];
        $this->messageParamsDTO->longitude = $this->update->geo['coordinates']['longitude'];

        SendVkTelegramMessageJob::dispatch(
            $this->botUser->id,
            $this->update,
            $this->messageParamsDTO,
        );
    }

    /**
     * @return void
     */
    protected function sendMessage(): void
    {
        $this->messageParamsDTO->text = $this->update->text;

        SendVkTelegramMessageJob::dispatch(
            $this->botUser->id,
            $this->update,
            $this->messageParamsDTO,
        );

        $this->maybeDispatchAi($this->update->text);
    }

    /**
     * Trigger AI generation for an incoming VK text message, when gating allows.
     *
     * The draft/auto-reply job is dispatched without a TelegramUpdateDto — the
     * jobs derive the platform from BotUser and assemble the supergroup post
     * and the platform-specific user delivery themselves.
     *
     * @param string|null $text
     *
     * @return void
     */
    protected function maybeDispatchAi(?string $text): void
    {
        if ($this->botUser === null) {
            return;
        }

        $shouldAiReply = app(ShouldAiReply::class);
        if (!$shouldAiReply->shouldGenerateForBotUserText($this->botUser, $text)) {
            return;
        }

        if ((bool) app(SettingsService::class)->get('ai.auto_reply')) {
            SendAiReplyJob::dispatch($this->botUser->id, null, (string) $text);
        } else {
            SendAiDraftJob::dispatch($this->botUser->id, null, (string) $text);
        }
    }

    /**
     * @return void
     */
    protected function sendPhoto(): void
    {
        //
    }

    /**
     * @return void
     */
    protected function sendSticker(): void
    {
        //
    }

    /**
     * @return void
     */
    protected function sendContact(): void
    {
        //
    }

    /**
     * @return void
     */
    protected function sendVideoNote(): void
    {
        //
    }

    /**
     * @return void
     */
    protected function sendVoice(): void
    {
        //
    }
}
