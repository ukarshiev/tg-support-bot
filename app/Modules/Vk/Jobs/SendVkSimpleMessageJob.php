<?php

namespace App\Modules\Vk\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Vk\Api\VkMethods;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendVkSimpleMessageJob extends AbstractSendMessageJob
{
    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    public mixed $updateDto;

    public mixed $queryParams;

    public string $typeMessage = 'outgoing';

    private mixed $vkMethods;

    public function __construct(
        VkTextMessageDto $queryParams,
        mixed $vkMethods = null,
        public readonly ?int $feedbackId = null,
    ) {
        $this->queryParams = $queryParams;

        $this->vkMethods = $vkMethods ?? new VkMethods();
    }

    public function handle(): void
    {
        $methodQuery = $this->queryParams->methodQuery;
        $dataQuery = $this->queryParams->toArray();

        $response = $this->vkMethods->sendQueryVk($methodQuery, $dataQuery);
        if ($response->response_code === 200) {
            return;
        }

        Log::channel('app')->warning('VK simple message delivery failed', [
            'source' => 'vk_simple_message_failed',
            'method' => $methodQuery,
            'response_code' => $response->response_code,
            'error_type' => $response->error_type,
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

    public function failed(Throwable $exception): void
    {
        if ($this->feedbackId !== null) {
            Feedback::whereKey($this->feedbackId)
                ->where('status', 'awaiting_rating')
                ->update(['status' => 'delivery_failed']);
        }
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
        //
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
