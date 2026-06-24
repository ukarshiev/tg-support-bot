<?php

namespace App\Modules\Admin\Jobs;

use App\Models\BotUser;
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
    ) {
    }

    /**
     * Post the admin-panel reply mirror to the user's supergroup forum topic.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
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
                Log::channel('app')->warning('MirrorAdminReplyToGroupJob: Telegram API error', [
                    'source' => 'mirror_admin_reply_error',
                    'bot_user_id' => $botUser->id,
                    'response' => (array) $response,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('app')->error($e->getMessage(), [
                'source' => 'mirror_admin_reply_exception',
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
