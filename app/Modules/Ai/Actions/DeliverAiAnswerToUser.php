<?php

namespace App\Modules\Ai\Actions;

use App\Models\BotUser;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Max\Jobs\SendMaxMessageJob;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkMessageJob;
use App\Platform\PlatformChannelRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch an AI-generated answer to the end user using the platform-specific
 * send job. Used by AI auto-reply (`SendAiReplyJob`) and AI draft accept
 * (`AiAcceptMessage`) so both flows deliver to the same set of platforms.
 *
 * Built-in platforms (telegram/vk/max) are handled directly. Any other platform
 * is delegated to a {@see \App\Contracts\PlatformChannel} registered in the
 * {@see PlatformChannelRegistry} by a pluggable module (e.g. the paid Avito
 * package) — so the core needs no edits to support a new platform.
 */
class DeliverAiAnswerToUser
{
    /**
     * @param BotUser                $botUser   Target user
     * @param string                 $text      AI answer text to deliver
     * @param TelegramUpdateDto|null $updateDto Optional originating TG update (callback or webhook).
     *                                          Forwarded to the platform-specific job so its saveMessage
     *                                          path keeps the same shape as a manager-driven reply.
     *
     * @return bool true if a send job was dispatched (or a registered channel handled it),
     *              false if platform is unsupported
     */
    public function execute(BotUser $botUser, string $text, ?TelegramUpdateDto $updateDto = null): bool
    {
        Log::channel('loki')->info('DeliverAiAnswerToUser: routing', [
            'source' => 'ai_deliver_routing',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'chat_id' => $botUser->chat_id,
            'text_length' => mb_strlen($text),
        ]);

        switch ($botUser->platform) {
            case 'telegram':
                SendTelegramMessageJob::dispatch(
                    $botUser->id,
                    $updateDto ?? $this->emptyTelegramUpdate(),
                    TGTextMessageDto::from([
                        'methodQuery' => 'sendMessage',
                        'typeSource' => 'private',
                        'chat_id' => $botUser->chat_id,
                        'text' => $text,
                        'parse_mode' => 'html',
                    ]),
                    'outgoing',
                );
                return true;

            case 'vk':
                SendVkMessageJob::dispatch(
                    $botUser->id,
                    $updateDto,
                    VkTextMessageDto::from([
                        'methodQuery' => 'messages.send',
                        'peer_id' => $botUser->chat_id,
                        'message' => $this->stripHtmlForPlainText($text),
                    ]),
                );
                return true;

            case 'max':
                SendMaxMessageJob::dispatch(
                    $botUser->id,
                    $updateDto,
                    MaxTextMessageDto::from([
                        'methodQuery' => 'sendMessage',
                        'user_id' => $botUser->chat_id,
                        'text' => $this->stripHtmlForPlainText($text),
                    ]),
                );
                return true;

            default:
                $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);

                if ($channel !== null) {
                    $channel->deliverAiAnswer($botUser, $text, $updateDto);

                    Log::channel('loki')->info('DeliverAiAnswerToUser: delivered via registered channel', [
                        'source' => 'ai_deliver_registered_channel',
                        'bot_user_id' => $botUser->id,
                        'platform' => $botUser->platform,
                    ]);

                    return true;
                }

                Log::channel('loki')->warning('DeliverAiAnswerToUser: unsupported platform', [
                    'source' => 'ai_deliver_unsupported_platform',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
                return false;
        }
    }

    /**
     * AI drafts are stored with Telegram HTML markup (`<b>`, `<i>`, …) because
     * the supergroup post uses `parse_mode=html`. VK and Max channels expect
     * plain text and would otherwise render the literal tags. Strip them and
     * decode HTML entities so the user sees a clean message.
     *
     * @param string $text
     *
     * @return string
     */
    private function stripHtmlForPlainText(string $text): string
    {
        $plain = strip_tags($text);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($plain);
    }

    /**
     * Build a minimal stub TelegramUpdateDto for AI flows that did not originate
     * from a Telegram webhook (e.g. AI auto-reply triggered by a VK/Max message).
     *
     * @return TelegramUpdateDto
     */
    private function emptyTelegramUpdate(): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: 0,
            typeQuery: 'message',
            aiTechMessage: false,
            typeSource: 'private',
        );
    }
}
