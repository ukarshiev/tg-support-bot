<?php

namespace App\Modules\Ai\Services;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class ShouldAiReply
{
    /**
     * Слова и фразы, при которых автоответ клиенту запрещён.
     * AI всё ещё может подготовить черновик для оператора, но не должен
     * самостоятельно отвечать по деньгам, подпискам, доступам и компенсациям.
     *
     * @var array<int, string>
     */
    private const HUMAN_OPERATOR_KEYWORDS = [
        'оплат', 'платеж', 'платёж', 'деньг', 'финанс', 'возврат', 'рефанд',
        'refund', 'payment', 'paid', 'money', 'charge', 'charged', 'billing',
        'подписк', 'тариф', 'продл', 'доступ', 'компенсац', 'бонус', 'скидк',
        'subscription', 'subscribed', 'joined', 'access', 'extend', 'extension',
        'compensation', 'bonus', 'discount',
        'недоступ', 'не доступ', 'не работает', 'лежал', 'упал', 'жалоб', 'претензи',
        'unavailable', 'not available', 'down', 'does not work', "doesn't work",
        'complaint', 'unhappy', 'disappointed', 'frustrated', 'unfortunate',
    ];

    /**
     * Determine whether AI should generate a reply for an incoming user message
     * processed by the main Telegram bot.
     *
     * Rules (all must pass):
     * 1. AI is globally enabled.
     * 2. Message arrived from a private chat with the main bot.
     * 3. Update type is a regular message (not callback, edited, etc.).
     * 4. Text is non-empty and not a slash-command (/start, /contact, etc.).
     * 5. Telegram user has selected a support language.
     * 6. Bot user exists, is not banned, is not closed.
     *
     * @param TelegramUpdateDto $update
     * @param BotUser|null      $botUser
     *
     * @return bool
     */
    public function shouldGenerateForUserMessage(TelegramUpdateDto $update, ?BotUser $botUser): bool
    {
        if (!$this->isAiEnabled()) {
            $this->logSkip('ai_disabled', $update, $botUser);
            return false;
        }

        if ($update->typeSource !== 'private') {
            $this->logSkip('not_private_chat', $update, $botUser, [
                'type_source' => $update->typeSource,
            ]);
            return false;
        }

        if ($update->typeQuery !== 'message') {
            $this->logSkip('not_message_query', $update, $botUser, [
                'type_query' => $update->typeQuery,
            ]);
            return false;
        }

        if (!$this->isReplyableText($update->text)) {
            $this->logSkip('empty_or_command_text', $update, $botUser, [
                'text_length' => mb_strlen((string) $update->text),
            ]);
            return false;
        }

        if (!$this->hasSelectedLanguage($botUser)) {
            $this->logSkip('language_not_selected', $update, $botUser, [
                'platform' => $botUser?->platform,
                'preferred_language_code' => $botUser?->preferred_language_code,
            ]);
            return false;
        }

        if (!$this->isUserActive($botUser)) {
            $this->logSkip('user_inactive', $update, $botUser, [
                'bot_user_found' => $botUser !== null,
                'is_banned' => $botUser?->isBanned(),
                'is_closed' => $botUser?->isClosed(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Determine whether AI should generate a reply for an incoming message
     * received from a non-Telegram source (VK, Max, etc.).
     *
     * Reuses the AI-enabled, replyable-text and user-active checks from the
     * Telegram path, but does not depend on a TelegramUpdateDto.
     *
     * @param BotUser|null $botUser
     * @param string|null  $text
     *
     * @return bool
     */
    public function shouldGenerateForBotUserText(?BotUser $botUser, ?string $text): bool
    {
        if (!$this->isAiEnabled()) {
            $this->logExternalSkip('ai_disabled', $botUser);
            return false;
        }

        if (!$this->isReplyableText($text)) {
            $this->logExternalSkip('empty_or_command_text', $botUser, [
                'text_length' => mb_strlen((string) $text),
            ]);
            return false;
        }

        if (!$this->hasSelectedLanguage($botUser)) {
            $this->logExternalSkip('language_not_selected', $botUser, [
                'preferred_language_code' => $botUser?->preferred_language_code,
            ]);
            return false;
        }

        if (!$this->isUserActive($botUser)) {
            $this->logExternalSkip('user_inactive', $botUser, [
                'bot_user_found' => $botUser !== null,
                'is_banned' => $botUser?->isBanned(),
                'is_closed' => $botUser?->isClosed(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * True means: do not send an AI answer to the client automatically.
     * Instead, create an AI draft for a human operator.
     *
     * @param BotUser|null $botUser
     * @param string|null  $text
     *
     * @return bool
     */
    public function shouldUseDraftOnly(?BotUser $botUser, ?string $text): bool
    {
        if (!$this->hasHumanOperatorRisk($text)) {
            return false;
        }

        Log::channel('app')->info('ShouldAiReply: auto-reply forced to draft [human_operator_risk]', [
            'source' => 'ai_auto_reply_forced_to_draft',
            'reason' => 'human_operator_risk',
            'bot_user_id' => $botUser?->id,
            'platform' => $botUser?->platform,
            'topic_id' => $botUser?->topic_id,
            'text_length' => mb_strlen((string) $text),
            'text_hash' => $text === null ? null : hash('sha256', $text),
        ]);

        return true;
    }

    /**
     * @return bool
     */
    public function isAiEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('ai.enabled');
    }

    /**
     * Text must be non-empty and not a slash-command.
     *
     * @param string|null $text
     *
     * @return bool
     */
    public function isReplyableText(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        return !str_starts_with($trimmed, '/');
    }

    /**
     * Detect risky commercial/support topics where only a human should answer.
     *
     * @param string|null $text
     *
     * @return bool
     */
    public function hasHumanOperatorRisk(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $normalized = mb_strtolower($text);

        foreach (self::HUMAN_OPERATOR_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Автоответ разрешён только после явного выбора языка на любом канале.
     *
     * @param BotUser|null $botUser
     *
     * @return bool
     */
    public function hasSelectedLanguage(?BotUser $botUser): bool
    {
        if ($botUser === null) {
            return false;
        }

        return !empty($botUser->preferred_language_code);
    }

    /**
     * @param BotUser|null $botUser
     *
     * @return bool
     */
    public function isUserActive(?BotUser $botUser): bool
    {
        if ($botUser === null) {
            return false;
        }

        return !$botUser->isBanned() && !$botUser->isClosed();
    }

    /**
     * Log the reason AI generation was skipped.
     *
     * @param string            $reason
     * @param TelegramUpdateDto $update
     * @param BotUser|null      $botUser
     * @param array             $extra
     *
     * @return void
     */
    private function logSkip(string $reason, TelegramUpdateDto $update, ?BotUser $botUser, array $extra = []): void
    {
        Log::channel('app')->info("ShouldAiReply: skipped [{$reason}]", array_merge([
            'source' => 'ai_should_reply_skipped',
            'reason' => $reason,
            'bot_user_id' => $botUser?->id,
            'chat_id' => $update->chatId,
            'message_thread_id' => $update->messageThreadId,
        ], $extra));
    }

    /**
     * Log the reason AI generation was skipped for an external (non-TG) update.
     *
     * @param string       $reason
     * @param BotUser|null $botUser
     * @param array        $extra
     *
     * @return void
     */
    private function logExternalSkip(string $reason, ?BotUser $botUser, array $extra = []): void
    {
        Log::channel('app')->info("ShouldAiReply: skipped [{$reason}]", array_merge([
            'source' => 'ai_should_reply_skipped',
            'reason' => $reason,
            'bot_user_id' => $botUser?->id,
            'platform' => $botUser?->platform,
            'topic_id' => $botUser?->topic_id,
        ], $extra));
    }
}
