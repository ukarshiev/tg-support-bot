<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Translation\Support\TelegramMarkupSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SelectLanguage
{
    public function __construct(
        private readonly SupportLanguageService $languages,
        private readonly SendContactMessage $sendContactMessage,
        private readonly AnswerCallbackQuery $answerCallbackQuery,
        private readonly TelegramMarkupSanitizer $telegramMarkupSanitizer,
    ) {
    }

    public function execute(BotUser $botUser, TelegramUpdateDto $update): void
    {
        $code = $this->languages->codeFromCallback($update->callbackData);
        $language = $this->languages->find($code);

        if ($code === null || $language === null) {
            $this->answerCallbackQuery->execute($update);
            Log::channel('app')->warning('Telegram language callback ignored: unknown language code', [
                'source' => 'telegram_language_flow',
                'bot_user_id' => $botUser->id,
                'chat_id' => $botUser->chat_id,
                'callback_id' => $update->callbackId,
                'callback_data' => $update->callbackData,
            ]);

            return;
        }

        $this->answerCallbackQuery->execute($update);

        $hadSelectedLanguage = !empty($botUser->preferred_language_code)
            || $botUser->preferred_language_selected_at !== null;

        $resolvedGreeting = $this->languages->greeting($code, $botUser);
        $greeting = is_string($resolvedGreeting) && $resolvedGreeting !== ''
            ? $this->telegramMarkupSanitizer->toPlainText($resolvedGreeting)
            : null;
        $lockKey = sprintf('telegram:language-flow:%d:%s', $botUser->id, $update->callbackId ?: $code);
        if (!Cache::add($lockKey, true, now()->addMinute())) {
            Log::channel('app')->info('Telegram language callback skipped by callback lock', [
                'source' => 'telegram_language_flow',
                'bot_user_id' => $botUser->id,
                'chat_id' => $botUser->chat_id,
                'language_code' => $code,
                'callback_id' => $update->callbackId,
            ]);

            return;
        }

        Log::channel('app')->info('Telegram language callback accepted', [
            'source' => 'telegram_language_flow',
            'bot_user_id' => $botUser->id,
            'chat_id' => $botUser->chat_id,
            'language_code' => $code,
            'language_name' => $language['name'],
            'callback_id' => $update->callbackId,
            'had_selected_language' => $hadSelectedLanguage,
        ]);

        $botUser->update([
            'preferred_language_code' => $code,
            'preferred_language_name' => $language['name'],
            'preferred_language_selected_at' => now(),
        ]);

        $botUser->refresh();

        if (!$hadSelectedLanguage) {
            $this->sendContactMessage->execute($botUser, $update->languageCode);
        }

        if ($greeting !== null && $greeting !== '') {
            SendTelegramMessageJob::dispatch(
                $botUser->id,
                $update,
                TGTextMessageDto::from([
                    'methodQuery' => 'sendMessage',
                    'chat_id' => $botUser->chat_id,
                    'text' => $greeting,
                    'parse_mode' => null,
                    'messageKind' => Message::KIND_SYSTEM,
                ]),
                'outgoing'
            );
        }

        Log::channel('app')->info('Telegram welcome dispatch queued', [
            'source' => 'telegram_language_flow',
            'bot_user_id' => $botUser->id,
            'chat_id' => $botUser->chat_id,
            'language_code' => $code,
            'callback_id' => $update->callbackId,
            'greeting_hash' => $greeting === null ? null : md5($greeting),
        ]);
    }
}
