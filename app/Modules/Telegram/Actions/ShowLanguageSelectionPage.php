<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;

class ShowLanguageSelectionPage
{
    public function __construct(
        private readonly SupportLanguageService $languages,
        private readonly AnswerCallbackQuery $answerCallbackQuery,
    ) {
    }

    public function execute(BotUser $botUser, TelegramUpdateDto $update): void
    {
        $page = $this->languages->pageFromCallback($update->callbackData);

        $this->answerCallbackQuery->execute($update);

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            TGTextMessageDto::from([
                'methodQuery' => 'editMessageText',
                'chat_id' => $update->chatId,
                'message_id' => $update->messageId,
                'text' => $this->languages->prompt($page, $botUser->preferred_language_code),
                'parse_mode' => 'html',
                'reply_markup' => [
                    'inline_keyboard' => $this->languages->keyboard($page),
                ],
            ]),
            'outgoing'
        );
    }
}
