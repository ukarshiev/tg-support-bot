<?php

namespace App\Modules\Ai\Jobs;

use App\Helpers\AiHelper;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Доставляет в Telegram черновик, который был готов раньше forum-темы клиента. */
class SendPendingAiDraftToTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public array $backoff = [2, 5, 10, 30, 60, 120];

    public int $timeout = 20;

    private const TELEGRAM_TEXT_LIMIT = 4096;

    public function __construct(public readonly int $aiMessageId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiBotApi $aiBotApi): void
    {
        $draft = AiMessage::find($this->aiMessageId);
        if (
            $draft === null
            || $draft->status !== AiMessage::STATUS_PENDING
            || $draft->message_id !== null
        ) {
            return;
        }

        $botUser = BotUser::find($draft->bot_user_id);
        if ($botUser === null) {
            throw new \RuntimeException('BotUser not found for AI draft: ' . $draft->id, 1);
        }

        $token = (string) app(SettingsService::class)->get('telegram_ai.token');
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($token === '' || $groupId === '') {
            Log::channel('app')->warning('Pending AI draft cannot be delivered: Telegram AI bot is not configured', [
                'source' => 'send_pending_ai_draft_not_configured',
                'ai_message_id' => $draft->id,
                'bot_user_id' => $botUser->id,
            ]);

            return;
        }

        if (empty($botUser->topic_id)) {
            TopicCreateJob::dispatch($botUser->id);
            $this->release(3);

            return;
        }

        $sourceText = (string) ($draft->text_source ?: $draft->text_ai);
        $parts = $this->splitHtml($this->formatDraft(
            $sourceText,
            $draft->text_translated,
            $draft->target_locale,
        ));
        $lastResponse = null;

        foreach ($parts as $part) {
            $lastResponse = $aiBotApi->send('sendMessage', [
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'text' => $part,
                'parse_mode' => 'html',
            ]);

            if ($lastResponse->ok !== true) {
                throw new \RuntimeException('Telegram API error sending pending draft: ' . json_encode((array) $lastResponse), 1);
            }
        }

        if ($lastResponse === null) {
            throw new \RuntimeException('Telegram draft has no deliverable parts', 1);
        }

        $markupResponse = $aiBotApi->send('editMessageReplyMarkup', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'message_id' => $lastResponse->message_id,
            'reply_markup' => AiHelper::preparedAiReplyMarkup((int) $lastResponse->message_id, $sourceText),
        ]);

        if ($markupResponse->ok !== true) {
            throw new \RuntimeException('Telegram API error attaching pending draft actions: ' . json_encode((array) $markupResponse), 1);
        }

        // message_id указывает на последнюю часть: именно на ней находятся кнопки,
        // и существующий callback/reply-поиск AiMessage продолжает работать.
        $draft->update(['message_id' => $lastResponse->message_id]);

        Log::channel('app')->info('Pending AI draft delivered after Telegram topic became available', [
            'source' => 'send_pending_ai_draft_delivered',
            'ai_message_id' => $draft->id,
            'bot_user_id' => $botUser->id,
            'topic_id' => $botUser->topic_id,
            'parts_count' => count($parts),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->error('Pending AI draft delivery permanently failed', [
            'source' => 'send_pending_ai_draft_failed',
            'ai_message_id' => $this->aiMessageId,
            'error_class' => $exception::class,
        ]);
    }

    private function formatDraft(string $sourceText, ?string $translatedText, ?string $targetLocale): string
    {
        if ($targetLocale === null || $targetLocale === '' || $targetLocale === 'ru') {
            return "<b>🤖 ИИ-черновик</b>\n\n"
                . "<b>Оригинал без перевода:</b>\n" . e($sourceText);
        }

        $translatedBlock = $translatedText !== null && $translatedText !== ''
            ? e($translatedText)
            : 'Перевод пока недоступен.';

        return "<b>🤖 ИИ-черновик</b>\n\n"
            . "<b>🇷🇺 Для оператора:</b>\n" . e($sourceText) . "\n\n"
            . '<b>🌐 Клиенту на ' . strtoupper($targetLocale) . ":</b>\n" . $translatedBlock;
    }

    /**
     * Делит HTML на валидные самостоятельные сообщения. Открытые теги закрываются
     * в конце части и снова открываются в следующей; HTML-сущности не разрезаются.
     *
     * @return list<string>
     */
    private function splitHtml(string $html): array
    {
        $tokens = preg_split(
            '/(<\/?[a-zA-Z][^>]*>|&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);)/u',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $atomicTokens = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, '<') || str_starts_with($token, '&')) {
                $atomicTokens[] = $token;
                continue;
            }

            foreach (preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $atomicTokens[] = $character;
            }
        }

        $parts = [];
        $current = '';
        /** @var list<array{name: string, opening: string}> $openTags */
        $openTags = [];

        foreach ($atomicTokens as $token) {
            $nextOpenTags = $this->openTagsAfter($openTags, $token);
            $candidate = $current . $token;

            if ($current !== '' && mb_strlen($candidate . $this->closingTags($nextOpenTags)) > self::TELEGRAM_TEXT_LIMIT) {
                $parts[] = $current . $this->closingTags($openTags);
                $current = $this->openingTags($openTags) . $token;
            } else {
                $current = $candidate;
            }

            $openTags = $nextOpenTags;
        }

        if ($current !== '') {
            $parts[] = $current . $this->closingTags($openTags);
        }

        return $parts;
    }

    /**
     * @param list<array{name: string, opening: string}> $openTags
     * @return list<array{name: string, opening: string}>
     */
    private function openTagsAfter(array $openTags, string $token): array
    {
        if (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>$/', $token, $matches) === 1) {
            $openTags[] = ['name' => strtolower($matches[1]), 'opening' => $token];
        } elseif (preg_match('/^<\/([a-zA-Z][a-zA-Z0-9]*)>$/', $token, $matches) === 1) {
            $name = strtolower($matches[1]);
            if ($openTags !== [] && $openTags[array_key_last($openTags)]['name'] === $name) {
                array_pop($openTags);
            }
        }

        return $openTags;
    }

    /** @param list<array{name: string, opening: string}> $openTags */
    private function openingTags(array $openTags): string
    {
        return implode('', array_column($openTags, 'opening'));
    }

    /** @param list<array{name: string, opening: string}> $openTags */
    private function closingTags(array $openTags): string
    {
        return implode('', array_map(
            static fn (array $tag): string => '</' . $tag['name'] . '>',
            array_reverse($openTags),
        ));
    }
}
