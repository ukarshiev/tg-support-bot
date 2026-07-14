<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;

class SendLanguageSelectionMessage
{
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

        if (!$force && (!empty($botUser->preferred_language_code) || $this->selectorAlreadySent($botUser))) {
            return;
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

    private function selectorAlreadySent(BotUser $botUser): bool
    {
        $columns = Message::supportsStructuralKind() ? ['message_kind', 'text'] : ['text'];

        return Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('platform', $botUser->platform)
            ->where('message_type', 'outgoing')
            ->latest('id')
            ->limit(100)
            ->get($columns)
            ->contains(fn (Message $message): bool => $message->message_kind === Message::KIND_LANGUAGE_SELECTOR
                || $this->languages->isSelectorText($message->text));
    }
}
