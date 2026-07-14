<?php

namespace App\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Modules\Admin\Jobs\MirrorAdminReplyToGroupJob;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Ai\Actions\DeliverAiAnswerToUser;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Доставляет сохранённый AI-ответ с повторами и меняет статус только по факту API-успеха. */
class DeliverAiMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 180];

    public int $timeout = 45;

    public function __construct(
        public readonly int $aiMessageId,
        public readonly ?TelegramUpdateDto $updateDto = null,
        public readonly bool $deleteDraftAfterDelivery = false,
        public readonly bool $mirrorAfterDelivery = true,
    ) {
        $this->onQueue('ai');
    }

    public function handle(DeliverAiAnswerToUser $delivery): void
    {
        $aiMessage = AiMessage::find($this->aiMessageId);
        if ($aiMessage === null) {
            Log::channel('app')->warning('AI delivery skipped: draft not found', [
                'source' => 'ai_delivery_draft_not_found',
                'ai_message_id' => $this->aiMessageId,
            ]);

            return;
        }

        if ($aiMessage->status === AiMessage::STATUS_ACCEPTED) {
            return;
        }

        /** @var BotUser|null $botUser */
        $botUser = $aiMessage->botUser;
        if ($botUser === null) {
            throw new \RuntimeException('BotUser not found for AI message ' . $aiMessage->id);
        }

        $clientText = $this->clientText($aiMessage, $botUser);
        $existingOperation = DeliveryOperation::where(
            'operation_key',
            hash('sha256', 'ai-delivery:' . $aiMessage->id),
        )->first();

        if ($existingOperation?->status === DeliveryOperation::STATUS_DELIVERED) {
            $aiMessage->update(['status' => AiMessage::STATUS_ACCEPTED]);
            if ($this->deleteDraftAfterDelivery) {
                $this->deleteDraftMessage($aiMessage, $botUser);
            }
            if ($this->mirrorAfterDelivery) {
                $this->mirrorAnswer($botUser, $clientText);
            }

            return;
        }

        $delivery->execute($botUser, $clientText, $this->updateDto, $aiMessage);

        $aiMessage->update(['status' => AiMessage::STATUS_ACCEPTED]);

        if ($this->deleteDraftAfterDelivery) {
            $this->deleteDraftMessage($aiMessage, $botUser);
        }

        if ($this->mirrorAfterDelivery) {
            $this->mirrorAnswer($botUser, $clientText);
        }

        Log::channel('app')->info('AI message accepted after confirmed delivery', [
            'source' => 'ai_message_accepted_after_delivery',
            'ai_message_id' => $aiMessage->id,
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'attempt' => $this->attempts(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        AiMessage::whereKey($this->aiMessageId)
            ->where('status', 'delivery_pending')
            ->update(['status' => 'delivery_failed']);

        DeliveryOperation::where('operation_key', hash('sha256', 'ai-delivery:' . $this->aiMessageId))
            ->update([
                'status' => DeliveryOperation::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

        Log::channel('app')->critical('AI message delivery permanently failed', [
            'source' => 'ai_delivery_failed_terminal',
            'ai_message_id' => $this->aiMessageId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    private function clientText(AiMessage $aiMessage, BotUser $botUser): string
    {
        $locale = strtolower(trim((string) $botUser->preferred_language_code));

        if ($locale === 'ru') {
            $russian = trim((string) ($aiMessage->text_source ?: $aiMessage->text_ai));
            if ($russian !== '') {
                return $russian;
            }
        }

        $translated = trim((string) $aiMessage->text_translated);
        if ($locale !== '' && $locale !== 'ru' && $aiMessage->translation_status === 'ready' && $translated !== '') {
            return $translated;
        }

        Log::channel('app')->warning('AI client text uses safe English fallback', [
            'source' => 'ai_safe_english_fallback',
            'ai_message_id' => $aiMessage->id,
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'locale' => $locale !== '' ? $locale : null,
            'translation_status' => $aiMessage->translation_status,
        ]);

        return 'A support agent will reply shortly. We could not prepare a safe localized answer.';
    }

    private function deleteDraftMessage(AiMessage $aiMessage, BotUser $botUser): void
    {
        $token = (string) app(SettingsService::class)->get('telegram_ai.token');
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        if ($token === '' || $groupId === '' || $aiMessage->message_id === null) {
            return;
        }

        $response = TelegramMethods::sendQueryTelegram('deleteMessage', [
            'chat_id' => $groupId,
            'message_id' => (int) $aiMessage->message_id,
        ], $token);

        if ($response->ok !== true) {
            Log::channel('app')->warning('Delivered AI draft cleanup failed', [
                'source' => 'ai_delivered_draft_cleanup_failed',
                'ai_message_id' => $aiMessage->id,
                'response_code' => $response->response_code,
            ]);
        }
    }

    private function mirrorAnswer(BotUser $botUser, string $text): void
    {
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($groupId === '' || !app(ChannelStatusService::class)->telegram()['connected']) {
            return;
        }

        $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain !== '') {
            MirrorAdminReplyToGroupJob::dispatch($botUser->id, $plain, "🤖 Ответ ИИ:\n");
        }
    }
}
