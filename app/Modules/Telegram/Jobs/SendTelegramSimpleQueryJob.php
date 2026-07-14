<?php

namespace App\Modules\Telegram\Jobs;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramSimpleQueryJob extends AbstractSendMessageJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    /** @var TGTextMessageDto */
    public mixed $queryParams;

    public function __construct(
        TGTextMessageDto $queryParams,
        public readonly ?int $feedbackId = null,
    ) {
        $this->queryParams = $queryParams;
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        $destination = (string) $queryParams->chat_id;
        $this->onQueue($groupId !== '' && $destination === $groupId
            ? 'telegram-mirror'
            : 'telegram-interactive');
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10, 20];
    }

    public function handle(): void
    {
        try {
            if (!empty($this->queryParams->chat_id)) {
                $botUser = BotUser::where([
                    'chat_id' => $this->queryParams->chat_id,
                ])->first();
                $this->botUserId = $botUser->id ?? 0;
            }

            $methodQuery = $this->queryParams->methodQuery;
            $params = $this->queryParams->toArray();

            $response = TelegramMethods::sendQueryTelegram(
                $methodQuery,
                $params,
                $this->queryParams->token
            );

            if (!$response->ok) {
                $this->telegramResponseHandler($response);
                $transient = $response->response_code === 429 || ($response->response_code ?? 0) >= 500;
                if ($this->feedbackId !== null && (!$transient || $this->attempts() >= $this->tries)) {
                    Feedback::whereKey($this->feedbackId)
                        ->where('status', 'awaiting_rating')
                        ->update(['status' => 'delivery_failed']);
                }

                if ($transient || $response->type_error === 'MARKDOWN_ERROR') {
                    return;
                }

                if (in_array($response->type_error, ['TOPIC_NOT_MODIFIED', 'MESSAGE_NOT_MODIFIED'], true)) {
                    return;
                }

                throw new \RuntimeException(sprintf(
                    'Telegram query rejected: code=%s type=%s',
                    $response->response_code ?? 0,
                    $response->type_error ?? 'UNKNOWN',
                ));
            }
        } catch (\Throwable $e) {
            Log::channel('app')->log($e->getCode() === 1 ? 'warning' : 'error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->feedbackId !== null) {
            Feedback::whereKey($this->feedbackId)
                ->where('status', 'awaiting_rating')
                ->update(['status' => 'delivery_failed']);
        }

        Log::channel('app')->error('Telegram simple query permanently failed', [
            'source' => 'telegram_simple_query_failed',
            'method' => $this->queryParams->methodQuery,
            'feedback_id' => $this->feedbackId,
            'error_class' => $exception::class,
        ]);
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
}
