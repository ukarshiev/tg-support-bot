<?php

namespace App\Modules\Admin\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mirror an admin-panel reply to the user's Telegram supergroup forum topic.
 *
 * Sends a text-only mirror with a labelled prefix («👤 Ответ из админки:\n») using the
 * main bot token. If the user's topic_id is not yet assigned (TopicCreateJob
 * still running), the job releases itself for 5 seconds and retries.
 *
 * This job NEVER creates a messages row and NEVER re-delivers to the user —
 * it only posts the informational mirror to the supergroup.
 */
class MirrorAdminReplyToGroupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 10, 20, 30, 60];

    public int $timeout = 20;

    /**
     * @param int    $botUserId BotUser primary key
     * @param string $text      Reply text (already sent to the user)
     * @param string $prefix    Label (with emoji + trailing newline) prepended in the group,
     *                          e.g. "👤 Ответ из админки:\n" or "🤖 Ответ ИИ:\n"
     */
    public function __construct(
        public readonly int $botUserId,
        public readonly string $text,
        public readonly string $prefix = "👤 Ответ из админки:\n",
        public readonly ?int $sourceMessageId = null,
        public readonly bool $mirrorEnabled = true,
    ) {
        $this->onQueue($this->mirrorEnabled ? 'telegram-mirror' : 'default');
    }

    /**
     * Post the admin-panel reply mirror to the user's supergroup forum topic.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            $this->confirmClientDelivery();

            if (!$this->mirrorEnabled) {
                return;
            }

            $botUser = BotUser::find($this->botUserId);
            if ($botUser === null) {
                Log::channel('app')->warning('MirrorAdminReplyToGroupJob: BotUser not found', [
                    'source' => 'mirror_admin_reply',
                    'bot_user_id' => $this->botUserId,
                ]);

                return;
            }

            if (empty($botUser->topic_id)) {
                Log::channel('app')->info('MirrorAdminReplyToGroupJob: topic_id not ready, releasing', [
                    'source' => 'mirror_admin_reply_topic_pending',
                    'bot_user_id' => $botUser->id,
                ]);
                $this->release(5);

                return;
            }

            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
            if ($groupId === '') {
                return;
            }

            $mirrorText = $this->prefix . $this->text;

            $response = TelegramMethods::sendQueryTelegram('sendMessage', [
                'chat_id' => $groupId,
                'message_thread_id' => $botUser->topic_id,
                'text' => $mirrorText,
            ]);

            if (!$response->ok) {
                throw new \RuntimeException(
                    'Telegram mirror rejected, response_code=' . ($response->response_code ?? 0)
                );
            }
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Admin reply mirror attempt failed', [
                'source' => 'mirror_admin_reply_exception',
                'bot_user_id' => $this->botUserId,
                'attempt' => $this->attempts(),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->error('Admin reply mirror permanently failed', [
            'source' => 'mirror_admin_reply_failed_terminal',
            'bot_user_id' => $this->botUserId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    private function confirmClientDelivery(): void
    {
        if ($this->sourceMessageId === null) {
            return;
        }

        DeliveryOperation::query()
            ->where('operation_key', hash('sha256', 'admin-reply:' . $this->sourceMessageId))
            ->update([
                'status' => DeliveryOperation::STATUS_DELIVERED,
                'attempts' => 1,
                'last_error' => null,
                'started_at' => now(),
                'delivered_at' => now(),
            ]);

        Log::channel('app')->info('Admin reply client delivery confirmed', [
            'source' => 'admin_reply_delivery_confirmed',
            'message_id' => $this->sourceMessageId,
            'bot_user_id' => $this->botUserId,
        ]);
    }
}
