<?php

namespace App\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Ai\Actions\DeliverAiAnswerToUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAiReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param int                    $botUserId   BotUser primary key
     * @param TelegramUpdateDto|null $updateDto   Parsed webhook update; null when AI is triggered
     *                                            from a non-Telegram source (e.g. VK/Max).
     * @param string                 $userMessage Original user message text to send to AI
     */
    public function __construct(
        public readonly int $botUserId,
        public readonly ?TelegramUpdateDto $updateDto,
        public readonly string $userMessage,
    ) {
    }

    /**
     * Generate an AI reply, deliver it to the user, and post it to the
     * supergroup forum topic when the Telegram AI bot is configured.
     *
     * @param AiBotApi           $aiBotApi
     * @param AiAssistantService $aiService
     *
     * @return void
     */
    public function handle(AiBotApi $aiBotApi, AiAssistantService $aiService): void
    {
        try {
            $botUser = BotUser::find($this->botUserId);
            if ($botUser === null) {
                throw new \RuntimeException('BotUser not found: ' . $this->botUserId, 1);
            }

            $aiBotToken = (string) app(SettingsService::class)->get('telegram_ai.token');
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
            $telegramConnected = app(ChannelStatusService::class)->telegram()['connected']
                && $groupId !== '';
            $aiBotConfigured = $aiBotToken !== '' && $telegramConnected;

            // When the supergroup will be used, the topic must exist first.
            if ($aiBotConfigured && empty($botUser->topic_id)) {
                Log::channel('app')->info('SendAiReplyJob: topic_id not ready, releasing', [
                    'source' => 'send_ai_reply_topic_pending',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
                $this->release(5);

                return;
            }

            $aiRequest = new AiRequestDto(
                message: $this->userMessage,
                userId: $this->botUserId,
                platform: $botUser->platform ?? 'telegram',
                provider: (string) app(SettingsService::class)->get('ai.default_provider'),
                forceEscalation: false
            );

            $aiResponse = $aiService->processMessage($aiRequest);
            if ($aiResponse === null || trim((string) $aiResponse->response) === '') {
                throw new \RuntimeException('AI provider returned empty response', 1);
            }

            $replyText = $aiResponse->response;

            if ($aiBotConfigured) {
                $supergroupResponse = $aiBotApi->send('sendMessage', [
                    'chat_id' => $groupId,
                    'message_thread_id' => $botUser->topic_id,
                    'text' => $replyText,
                    'parse_mode' => 'html',
                ]);

                if ($supergroupResponse->ok !== true) {
                    throw new \RuntimeException('Telegram API error posting AI reply to supergroup: ' . json_encode((array) $supergroupResponse), 1);
                }

                AiMessage::create([
                    'bot_user_id' => $botUser->id,
                    'message_id' => $supergroupResponse->message_id,
                    'text_ai' => $replyText,
                    'text_manager' => $replyText,
                    'status' => AiMessage::STATUS_ACCEPTED,
                ]);

                Log::channel('app')->info('SendAiReplyJob: AI reply posted to supergroup', [
                    'source' => 'ai_reply_supergroup',
                    'bot_user_id' => $botUser->id,
                    'supergroup_message_id' => $supergroupResponse->message_id,
                ]);
            } else {
                // Supergroup not configured: record auto-reply for admin panel only.
                AiMessage::create([
                    'bot_user_id' => $botUser->id,
                    'message_id' => null,
                    'text_ai' => $replyText,
                    'text_manager' => $replyText,
                    'status' => AiMessage::STATUS_ACCEPTED,
                ]);
            }

            $delivered = app(DeliverAiAnswerToUser::class)->execute($botUser, $replyText, $this->updateDto);
            if (!$delivered) {
                throw new \RuntimeException('AI auto-reply delivery skipped: unsupported platform', 1);
            }

            Log::channel('app')->info('SendAiReplyJob: AI reply delivered', [
                'source' => 'ai_reply_sent',
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
            ]);
        } catch (\Throwable $e) {
            Log::channel('app')->log(
                $e->getCode() === 1 ? 'warning' : 'error',
                $e->getMessage(),
                ['source' => 'send_ai_reply_error', 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }
}
