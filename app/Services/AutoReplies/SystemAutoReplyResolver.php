<?php

declare(strict_types=1);

namespace App\Services\AutoReplies;

use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\BotUser;
use Illuminate\Support\Facades\Log;

class SystemAutoReplyResolver
{
    /** @var array<string, string> */
    private const ENGLISH_FALLBACKS = [
        AutoReply::TYPE_WELCOME => 'Hello! How can I help you?',
        AutoReply::TYPE_DIALOG_CLOSED => 'Your request has been closed!',
        AutoReply::TYPE_FEEDBACK_REQUEST => 'Please rate the quality of our support:',
        AutoReply::TYPE_FEEDBACK_THANK_YOU => 'Thank you for your feedback! Your rating has been recorded.',
        AutoReply::TYPE_BAN => 'You have been blocked by the bot administration.',
    ];

    public function __construct(private readonly AutoReplyVariableRenderer $renderer)
    {
    }

    public function resolve(string $type, ?BotUser $botUser = null, ?string $locale = null): ?string
    {
        $locale = $this->normalizeLocale($locale ?? $botUser?->preferred_language_code);
        $reply = $this->systemReply($type);

        if ($reply === null) {
            $this->logResolution($type, $botUser, $locale, 'disabled_or_missing');
            return null;
        }

        if ($locale === 'ru') {
            $this->logResolution($type, $botUser, $locale, 'source_ru');
            return $this->render($reply->response, $botUser);
        }

        $localized = $this->readyTranslation($reply, $locale);
        $level = 'selected_locale';
        if ($localized === null && $locale !== 'en') {
            $localized = $this->readyTranslation($reply, 'en');
            $level = 'english_translation';
        }
        if ($localized === null) {
            $localized = self::ENGLISH_FALLBACKS[$type] ?? '';
            $level = 'builtin_english';
        }

        $this->logResolution($type, $botUser, $locale, $level);

        return $this->render($localized, $botUser);
    }

    private function systemReply(string $type): ?AutoReply
    {
        $trigger = AutoReply::systemTriggers()[$type] ?? null;
        if ($trigger === null) {
            return null;
        }

        return AutoReply::query()
            ->where('type', $type)
            ->where('trigger', $trigger)
            ->where('enabled', true)
            ->first();
    }

    private function readyTranslation(AutoReply $reply, string $locale): ?string
    {
        $text = AutoReplyTranslation::query()
            ->where('auto_reply_id', $reply->id)
            ->where('locale', $locale)
            ->where('status', AutoReplyTranslation::STATUS_READY)
            ->where('source_hash', AutoReply::sourceHash($reply->response))
            ->value('text');

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    private function render(string $text, ?BotUser $botUser): string
    {
        return $this->renderer->render($text, $botUser)[0];
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return $locale !== '' ? str_replace('_', '-', $locale) : 'en';
    }

    private function logResolution(string $type, ?BotUser $botUser, string $locale, string $level): void
    {
        Log::channel('app')->info('System auto-reply resolved', [
            'source' => 'system_auto_reply_resolution',
            'auto_reply_type' => $type,
            'bot_user_id' => $botUser?->id,
            'platform' => $botUser?->platform,
            'locale' => $locale,
            'fallback_level' => $level,
        ]);
    }
}
