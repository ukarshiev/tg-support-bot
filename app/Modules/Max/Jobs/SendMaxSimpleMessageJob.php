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

    /**
     * @return void
     */
    public function handle(): void
    {
        try {
            $response = $this->maxMethods->sendQuery(
                $this->queryParams->methodQuery,
                $this->queryParams->toArray()
            );

            // A freshly uploaded attachment may not be processed by MAX yet —
            // retry with backoff on `attachment.not.ready` (file/image replies).
            $retryDelays = [2, 4, 8, 16, 30];

            foreach ($retryDelays as $delay) {
                if ($response->response_code === 200) {
                    return;
                }

                if (!str_contains($response->error_message ?? '', 'attachment.not.ready')) {
                    throw new \Exception($response->error_message ?? 'SendMaxSimpleMessageJob: unknown error', 1);
                }

                sleep($delay);
                $response = $this->maxMethods->sendQuery(
                    $this->queryParams->methodQuery,
                    $this->queryParams->toArray()
                );
            }

            if ($response->response_code !== 200) {
                throw new \Exception($response->error_message ?? 'SendMaxSimpleMessageJob: attachment not ready after retries', 1);
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log(
                $e->getCode() === 1 ? 'warning' : 'error',
                $e->getMessage(),
                ['file' => $e->getFile(), 'line' => $e->getLine()]
            );
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
        //
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
