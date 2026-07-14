<?php

namespace App\Modules\Vk\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Vk\Api\VkMethods;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use Illuminate\Support\Facades\Log;

class SendVkMessageJob extends AbstractSendMessageJob
{
    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    public mixed $updateDto;

    public mixed $queryParams;

    public string $typeMessage = 'outgoing';

    private mixed $vkMethods;

    public function __construct(
        int $botUserId,
        ?TelegramUpdateDto $updateDto,
        VkTextMessageDto $queryParams,
        mixed $vkMethods = null,
    ) {
        $this->botUserId = $botUserId;
        $this->updateDto = $updateDto;
        $this->queryParams = $queryParams;

        $this->vkMethods = $vkMethods ?? new VkMethods();
    }

    public function handle(): void
    {
        $botUser = BotUser::findOrFail($this->botUserId);

        $methodQuery = $this->queryParams->methodQuery;
        $dataQuery = $this->queryParams->toArray();

        Log::channel('app')->info('SendVkMessageJob: sending', [
            'source' => 'send_vk_message_start',
            'bot_user_id' => $this->botUserId,
            'method' => $methodQuery,
            'text_length' => mb_strlen((string) ($dataQuery['message'] ?? '')),
            'has_attachment' => !empty($dataQuery['attachment'] ?? null),
        ]);

        $response = $this->vkMethods->sendQueryVk($methodQuery, $dataQuery);

        if ($response->response_code === 200) {
            $this->saveMessage($botUser, $response);
            $this->updateTopic($botUser, $this->typeMessage);

            return;
        }

        Log::channel('app')->error('SendVkMessageJob: VK API error', [
            'source' => 'send_vk_message_api_error',
            'bot_user_id' => $this->botUserId,
            'method' => $methodQuery,
            'error_type' => $response->error_type,
            'response_code' => $response->response_code,
        ]);
        throw new \RuntimeException(sprintf(
            'VK query rejected: code=%s type=%s',
            $response->response_code ?? 0,
            $response->error_type ?? 'UNKNOWN',
        ));
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    /**
     * Save message to database after successful sending.
     *
     * @param BotUser $botUser
     * @param mixed   $resultQuery
     *
     * @return void
     */
    protected function saveMessage(BotUser $botUser, mixed $resultQuery): void
    {
        $hasUpdateDto = $this->updateDto instanceof TelegramUpdateDto;

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'message_type' => $this->typeMessage,
            'from_id' => $hasUpdateDto ? $this->updateDto->messageId : 0,
            'to_id' => $resultQuery->response,
            'text' => $this->queryParams->message ?? ($hasUpdateDto ? $this->updateDto->text : null),
        ]);

        if ($hasUpdateDto && !empty($this->updateDto->fileId)) {
            $message->attachments()->create([
                'file_id' => $this->updateDto->fileId,
                'file_type' => $this->updateDto->fileType ?? 'document',
            ]);
        }
    }

    /**
     * Edit message in database.
     *
     * @param mixed $resultQuery
     *
     * @return void
     */
    protected function editMessage(BotUser $botUser, mixed $resultQuery): void
    {
        //
    }
}
