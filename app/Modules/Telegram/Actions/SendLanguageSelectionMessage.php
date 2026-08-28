<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;
use Illuminate\Support\Facades\Cache;

class SendLanguageSelectionMessage
{
    // One minute suppresses selector floods during a short message burst while
    // still reminding a client who postponed the language choice.
    private const SELECTOR_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly SupportLanguageService $languages,
    ) {
    }

    public function execute(TelegramUpdateDto $update, bool $force = false): void
    {
        if ($update->typeSource !== 'private') {
            return;
        }

        $botUser = BotUser::getOrCreateByTelegramUpdate($update);
        if ($botUser === null) {
            return;
        }

        $cacheKey = "telegram:language-selector:bot-user:{$botUser->id}";

        if (!$force) {
            if (!empty($botUser->preferred_language_code)) {
                return;
            }

            if (!Cache::add($cacheKey, true, self::SELECTOR_COOLDOWN_SECONDS)) {
                return;
            }
        } else {
            // Explicit commands always show the selector, but also suppress an
            // immediate automatic repeat from the client's next message.
            Cache::add($cacheKey, true, self::SELECTOR_COOLDOWN_SECONDS);
        }

        SendTelegramMessageJob::dispatch(
            $botUser->id,
            $update,
            TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => $update->chatId,
                'message_thread_id' => $update->messageThreadId,
                'text' => $this->languages->prompt(locale: $botUser->preferred_language_code),
                'parse_mode' => 'html',
                'reply_markup' => [
                    'inline_keyboard' => $this->languages->keyboard(),
                ],
                'messageKind' => Message::KIND_LANGUAGE_SELECTOR,
            ]),
            'outgoing'
        );
    }
}
