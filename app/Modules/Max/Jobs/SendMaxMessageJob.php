<?php

namespace App\Modules\Max\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Models\Feedback;
use App\Models\Message;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMaxMessageJob extends AbstractSendMessageJob
{
    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    public mixed $updateDto;

    public mixed $queryParams;

    public string $typeMessage = 'outgoing';

    private mixed $maxMethods;

    public function __construct(
        int $botUserId,
        ?TelegramUpdateDto $updateDto,
        MaxTextMessageDto $queryParams,
        mixed $maxMethods = null,
        public readonly ?int $feedbackId = null,
    ) {
        $this->botUserId = $botUserId;
        $this->updateDto = $updateDto;
        $this->queryParams = $queryParams;
        $this->maxMethods = $maxMethods ?? new MaxMethods();
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $botUser = BotUser::findOrFail($this->botUserId);

        $response = $this->maxMethods->sendQuery(
            $this->queryParams->methodQuery,
            $this->queryParams->toArray()
        );

        if ($response->response_code === 200) {
            $this->saveMessage($botUser, $response);
            $this->updateTopic($botUser, $this->typeMessage);

            return;
        }

        Log::channel('app')->warning('MAX message delivery failed', [
            'source' => 'max_message_failed',
            'method' => $this->queryParams->methodQuery,
            'response_code' => $response->response_code,
            'attachment_not_ready' => str_contains($response->error_message ?? '', 'attachment.not.ready'),
        ]);
        throw new \RuntimeException('MAX query rejected: HTTP ' . ($response->response_code ?? 0));
    }

    public function backoff(): array
    {
        return [2, 4, 8, 16, 30];
    }

    public function failed(Throwable $exception): void
    {
        if ($this->feedbackId !== null) {
            Feedback::whereKey($this->feedbackId)
                ->where('status', 'awaiting_rating')
                ->update(['status' => 'delivery_failed']);
        }
    }

    /**
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
            'to_id' => 0,
            'text' => $this->queryParams->text
                ?? ($hasUpdateDto ? ($this->updateDto->text ?? $this->updateDto->caption) : null),
        ]);

        if ($hasUpdateDto && !empty($this->updateDto->fileId)) {
            $message->attachments()->create([
                'file_id' => $this->updateDto->fileId,
                'file_type' => $this->updateDto->fileType ?? 'document',
            ]);
        }
    }

    /**
     * @param BotUser $botUser
     * @param mixed   $resultQuery
     *
     * @return void
     */
    protected function editMessage(BotUser $botUser, mixed $resultQuery): void
    {
        //
    }
}
