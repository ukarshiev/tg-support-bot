<?php

namespace App\Modules\External\Services;

use App\Models\BotUser;
use App\Models\ExternalUser;
use App\Models\Message;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\External\DTOs\ExternalMessageDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Services\Settings\SettingsService;

abstract class ExternalService
{
    protected string $typeMessage = '';

    protected ExternalMessageDto $update;

    protected ?BotUser $botUser;

    protected ?ExternalUser $externalUser;

    protected TGTextMessageDto $messageParamsDTO;

    public function __construct(ExternalMessageDto $update)
    {
        $this->update = $update;

        $this->botUser = (new BotUser())->getOrCreateExternalBotUser($this->update);

        if (empty($this->botUser)) {
            throw new \Exception('Пользователя не существует!');
        }
    }

    abstract public function handleUpdate(): void;

    /**
     * Whether the optional Telegram supergroup is configured (token/secret set
     * and a group_id present). When false, the admin workspace is the only
     * destination and incoming messages are persisted directly.
     *
     * @return bool
     */
    protected function groupConfigured(): bool
    {
        return app(ChannelStatusService::class)->telegram()['connected']
            && (string) app(SettingsService::class)->get('telegram.group_id') !== '';
    }

    /**
     * Persist an incoming external message directly to messages + external_messages.
     *
     * Always-both flow: when no Telegram group is configured the message must
     * still appear in the admin workspace, independent of Telegram delivery.
     * Mirrors the row shape produced by SendExternalTelegramMessageJob::saveMessage().
     *
     * @param string|null $text
     *
     * @return Message
     */
    protected function persistIncoming(?string $text): Message
    {
        $message = Message::create([
            'bot_user_id' => $this->botUser->id,
            'platform' => $this->botUser->platform,
            'message_type' => 'incoming',
            'from_id' => time(),
            'to_id' => time(),
        ]);

        $message->externalMessage()->create([
            'text' => $text,
            'file_type' => $this->update->file_type ?? null,
            'file_name' => $this->update->file_name ?? null,
        ]);

        return $message;
    }
}
