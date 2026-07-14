<?php

namespace App\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Vk\Api\VkMethods;
use App\Platform\PlatformChannelRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Синхронно доставляет AI-ответ и фиксирует подтверждённый результат.
 *
 * Очередь и повторы находятся в DeliverAiMessageJob. Здесь сообщение в истории
 * создаётся только после успешного ответа платформы: запись больше не выглядит
 * отправленной, когда API фактически вернул ошибку.
 */
class DeliverAiAnswerToUser
{
    public function execute(
        BotUser $botUser,
        string $text,
        ?TelegramUpdateDto $updateDto = null,
        ?AiMessage $aiMessage = null,
    ): bool {
        $plainText = $this->stripHtmlForPlainText($text);
        if ($plainText === '') {
            throw new \RuntimeException('AI delivery rejected: empty client text');
        }

        $operation = $this->startOperation($botUser, $aiMessage);

        try {
            $externalMessageId = $this->deliver($botUser, $plainText, $updateDto);

            $message = Message::create([
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'message_type' => 'outgoing',
                'from_id' => 0,
                'to_id' => 0,
                'text' => $plainText,
            ]);

            $operation->update([
                'message_id' => $message->id,
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'external_message_id' => is_numeric($externalMessageId) ? (int) $externalMessageId : null,
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            Log::channel('app')->info('AI answer delivery confirmed', [
                'source' => 'ai_delivery_confirmed',
                'ai_message_id' => $aiMessage?->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'operation_key' => $operation->operation_key,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => DeliveryOperation::STATUS_RETRYING,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::channel('app')->warning('AI answer delivery attempt failed', [
                'source' => 'ai_delivery_retrying',
                'ai_message_id' => $aiMessage?->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'operation_key' => $operation->operation_key,
                'error_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function startOperation(BotUser $botUser, ?AiMessage $aiMessage): DeliveryOperation
    {
        $operationKey = $aiMessage !== null
            ? hash('sha256', 'ai-delivery:' . $aiMessage->id)
            : hash('sha256', 'ai-delivery:' . $botUser->id . ':' . now()->format('Uv') . ':' . bin2hex(random_bytes(8)));

        $operation = DeliveryOperation::firstOrCreate(
            ['operation_key' => $operationKey],
            [
                'bot_user_id' => $botUser->id,
                'trace_id' => 'ai-message:' . ($aiMessage !== null ? $aiMessage->id : 'direct'),
                'destination' => $botUser->platform . '-client',
                'operation' => 'ai-answer',
                'status' => DeliveryOperation::STATUS_PENDING,
            ],
        );

        if ($operation->status === DeliveryOperation::STATUS_DELIVERED) {
            throw new \LogicException('AI delivery operation is already completed');
        }

        $operation->update([
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => $operation->attempts + 1,
            'started_at' => $operation->started_at ?? now(),
        ]);

        return $operation;
    }

    private function deliver(BotUser $botUser, string $plainText, ?TelegramUpdateDto $updateDto): int|string|null
    {
        if ($botUser->platform === 'telegram') {
            $response = TelegramMethods::sendQueryTelegram('sendMessage', [
                'chat_id' => $botUser->chat_id,
                'text' => $plainText,
            ]);

            if ($response->ok !== true) {
                throw new \RuntimeException('Telegram rejected AI answer, response_code=' . ($response->response_code ?? 0));
            }

            return $response->message_id;
        }

        if ($botUser->platform === 'vk') {
            $response = VkMethods::sendQueryVk('messages.send', [
                'peer_id' => $botUser->chat_id,
                'message' => $plainText,
            ]);

            if ($response->response_code !== 200) {
                throw new \RuntimeException('VK rejected AI answer: HTTP ' . $response->response_code);
            }

            return is_int($response->response) ? $response->response : null;
        }

        if ($botUser->platform === 'max') {
            $response = app(MaxMethods::class)->sendQuery('sendMessage', [
                'user_id' => (int) $botUser->chat_id,
                'text' => $plainText,
            ]);

            if ($response->response_code !== 200) {
                throw new \RuntimeException('Max rejected AI answer: HTTP ' . $response->response_code);
            }

            return is_int($response->response) || is_string($response->response) ? $response->response : null;
        }

        $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);
        if ($channel === null) {
            throw new \RuntimeException('Unsupported AI delivery platform: ' . $botUser->platform);
        }

        $channel->deliverAiAnswer($botUser, $plainText, $updateDto);

        return null;
    }

    private function stripHtmlForPlainText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
