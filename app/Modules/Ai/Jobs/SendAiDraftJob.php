<?php

namespace App\Modules\Ai\Jobs;

use App\Helpers\AiHelper;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Admin\Services\ChannelStatusService;
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

class SendAiDraftJob implements ShouldQueue
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
     * Generate an AI draft and persist it for the admin panel workspace.
     * Additionally posts the draft to the supergroup forum topic when the
     * Telegram AI bot is configured (telegram_ai.token is set).
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
                Log::channel('app')->info('SendAiDraftJob: topic_id not ready, releasing', [
                    'source' => 'send_ai_draft_topic_pending',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
                $this->release(5);

                return;
            }

            // Generate AI draft text using the existing service.
            $aiRequest = new AiRequestDto(
                message: $this->userMessage,
                userId: $this->botUserId,
                platform: $botUser->platform ?? 'telegram',
                provider: (string) app(SettingsService::class)->get('ai.default_provider'),
                forceEscalation: false
            );

            $aiResponse = $aiService->processMessage($aiRequest);
            if ($aiResponse === null) {
                throw new \RuntimeException('AI provider returned null', 1);
            }

            if ($aiBotConfigured) {
                $this->postDraftToSupergroup($aiBotApi, $botUser, $aiResponse->response, $aiBotToken, $groupId);
            } else {
                // Supergroup not configured: persist draft for admin panel only.
                AiMessage::create([
                    'bot_user_id' => $botUser->id,
                    'message_id' => null,
                    'text_ai' => $aiResponse->response,
                    'text_manager' => '',
                    'status' => AiMessage::STATUS_PENDING,
                ]);

                Log::channel('app')->info('SendAiDraftJob: draft created (no AI bot configured)', [
                    'source' => 'send_ai_draft_no_ai_bot',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log(
                $e->getCode() === 1 ? 'warning' : 'error',
                $e->getMessage(),
                ['source' => 'send_ai_draft_error', 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }

    /**
     * Post draft to the Telegram supergroup and persist the AiMessage with Telegram message_id.
     * The AiMessage is also visible in the admin panel workspace via the pending drafts list.
     *
     * @param AiBotApi $aiBotApi
     * @param BotUser  $botUser
     * @param string   $aiResponseText
     * @param string   $aiBotToken
     * @param string   $groupId
     *
     * @return void
     */
    private function postDraftToSupergroup(
        AiBotApi $aiBotApi,
        BotUser $botUser,
        string $aiResponseText,
        string $aiBotToken,
        string $groupId,
    ): void {
        $draftText = AiHelper::preparedAiAnswer('', $aiResponseText);

        $response = $aiBotApi->send('sendMessage', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'text' => $draftText,
            'parse_mode' => 'html',
        ]);

        if ($response->ok !== true) {
            throw new \RuntimeException('Telegram API error sending draft: ' . json_encode((array) $response), 1);
        }

        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'message_id' => $response->message_id,
            'text_ai' => $aiResponseText,
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $aiBotApi->send('editMessageReplyMarkup', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'message_id' => $response->message_id,
            'reply_markup' => AiHelper::preparedAiReplyMarkup((int) $aiMessage->message_id, $aiResponseText),
        ]);
    }
}
