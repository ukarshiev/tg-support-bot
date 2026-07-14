<?php

namespace App\Modules\Max\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use Illuminate\Support\Facades\Log;

class SendMaxSimpleMessageJob extends AbstractSendMessageJob
{
    public int $tries = 5;

    public int $timeout = 20;

    public int $botUserId;

    public mixed $updateDto;

    public mixed $queryParams;

    public string $typeMessage = 'outgoing';

    private mixed $maxMethods;

    public function __construct(
        MaxTextMessageDto $queryParams,
        mixed $maxMethods = null,
    ) {
        $this->queryParams = $queryParams;
        $this->maxMethods = $maxMethods ?? new MaxMethods();
    }

    public function handle(): void
    {
        $response = $this->maxMethods->sendQuery(
            $this->queryParams->methodQuery,
            $this->queryParams->toArray()
        );

        if ($response->response_code === 200) {
            return;
        }

        Log::channel('app')->warning('MAX simple message delivery failed', [
            'source' => 'max_simple_message_failed',
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

    protected function saveMessage(BotUser $botUser, mixed $resultQuery): void
    {
        //
    }

    protected function editMessage(BotUser $botUser, mixed $resultQuery): void
    {
        //
    }
}
