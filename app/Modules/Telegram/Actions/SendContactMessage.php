<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use App\Modules\Telegram\Services\ContactSummaryFormatter;
use App\Services\Settings\SettingsService;

class SendContactMessage
{
    public function __construct(
        private GetChat $getChat,
        private ContactSummaryFormatter $formatter,
    ) {
    }

    public function execute(BotUser $botUser, ?string $telegramLanguageCode = null): void
    {
        SendContactMessageJob::dispatch($botUser->id, $telegramLanguageCode);
    }

    public function sendNow(BotUser $botUser, ?string $telegramLanguageCode = null): TelegramAnswerDto
    {
        if (empty($botUser->topic_id)) {
            throw new \RuntimeException("Cannot send contact card without Telegram topic for bot user {$botUser->id}");
        }

        $dto = $this->getQueryParams($botUser, $telegramLanguageCode);

        return TelegramMethods::sendQueryTelegram(
            $dto->methodQuery,
            $dto->toArray(),
            $dto->token,
        );
    }

    /**
     * @param BotUser $botUser
     *
     * @return TGTextMessageDto
     */
    public function getQueryParams(BotUser $botUser, ?string $telegramLanguageCode = null): TGTextMessageDto
    {
        if (empty($botUser->topic_id)) {
            throw new \RuntimeException("Cannot build contact card without Telegram topic for bot user {$botUser->id}");
        }

        return TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => $this->buildText($botUser, $telegramLanguageCode),
            'parse_mode' => 'html',
            'reply_markup' => [
                'inline_keyboard' => $this->buildKeyboard($botUser),
            ],
        ]);
    }

    /**
     * @param BotUser $botUser
     *
     * @return string
     */
    private function buildText(BotUser $botUser, ?string $telegramLanguageCode = null): string
    {
        $chatData = [];

        if ($botUser->platform === 'telegram') {
            try {
                $chatData = $this->getChat->execute($botUser->chat_id)->rawData['result'] ?? [];
            } catch (\Throwable) {
                $chatData = [];
            }
        }

        if ($telegramLanguageCode !== null && $telegramLanguageCode !== '') {
            $chatData['language_code'] = $telegramLanguageCode;
        }

        return $this->formatter->toTelegramHtml($botUser, $chatData);
    }

    /**
     * @param BotUser $botUser
     *
     * @return array
     */
    private function buildKeyboard(BotUser $botUser): array
    {
        $banButton = [
            'text' => $botUser->isBanned()
                ? __('messages.but_ban_user_false')
                : __('messages.but_ban_user_true'),
            'callback_data' => $botUser->isBanned()
                ? 'topic_user_ban_false'
                : 'topic_user_ban_true',
        ];

        return [
            [
                $banButton,
            ],
            [
                [
                    'text' => __('messages.but_close_topic'),
                    'callback_data' => 'close_topic',
                ],
            ],
        ];
    }
}
