<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Services\Settings\SettingsService;

class SendContactMessage
{
    public function __construct(
        private GetChat $getChat,
        private SupportLanguageService $languages,
    ) {
    }

    public function execute(BotUser $botUser): void
    {
        $dto = $this->getQueryParams($botUser);
        SendTelegramSimpleQueryJob::dispatch($dto);
    }

    /**
     * @param BotUser $botUser
     *
     * @return TGTextMessageDto
     */
    public function getQueryParams(BotUser $botUser): TGTextMessageDto
    {
        return TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
            'text' => $this->buildText($botUser),
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
    private function buildText(BotUser $botUser): string
    {
        $chatData = [];

        if ($botUser->platform === 'telegram') {
            try {
                $chatData = $this->getChat->execute($botUser->chat_id)->rawData['result'] ?? [];
            } catch (\Throwable) {
                $chatData = [];
            }
        }

        $username = $botUser->username ?: ($chatData['username'] ?? null);
        $displayName = $botUser->display_name ?: trim(($chatData['first_name'] ?? '') . ' ' . ($chatData['last_name'] ?? ''));
        $languageName = $this->languages->displayName(
            $botUser->preferred_language_code,
            $botUser->preferred_language_name
        );
        $telegramLanguageCode = $chatData['language_code'] ?? null;
        $profileLink = $username ? 'https://telegram.me/' . $username : null;

        $text = "<b>КОНТАКТНАЯ ИНФОРМАЦИЯ</b>\n";
        $text .= 'Источник: ' . e($botUser->platform) . "\n";
        $text .= "ID: <code>{$botUser->chat_id}</code>\n";
        $text .= 'Имя: ' . e($displayName !== '' ? $displayName : 'не указано') . "\n";
        $text .= 'Пользователь: ' . ($username ? '<code>' . e($username) . '</code>' : 'не указан') . "\n";
        $text .= 'Ссылка: ' . ($profileLink ? e($profileLink) : 'не доступна') . "\n";
        $text .= 'Выбранный язык: ' . e($languageName) . "\n";
        $text .= 'Telegram language_code: ' . e($telegramLanguageCode ?: 'не доступен') . "\n";
        $text .= "Телефон: не передан\n";
        $text .= "Регион: не определён\n";
        $text .= 'Первое обращение: ' . e(optional($botUser->created_at)->format('d.m.Y H:i') ?: 'неизвестно') . "\n";
        $text .= 'Последняя активность: ' . e(optional($botUser->updated_at)->format('d.m.Y H:i') ?: 'неизвестно') . "\n";

        return $text;
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
