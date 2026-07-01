<?php

namespace App\Modules\Telegram\Jobs;

use App\Models\BotUser;
use App\Models\ExternalUser;
use App\Modules\Telegram\Actions\GetChat;
use App\Modules\Telegram\Actions\SendContactMessage;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;

class TopicCreateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 300];

    private BotUser $botUser;

    private TelegramMethods $telegramMethods;

    private int $botUserId;

    public function __construct(
        int $botUserId,
        TelegramMethods $telegramMethods = null,
    ) {
        $this->botUserId = $botUserId;

        $this->telegramMethods = $telegramMethods ?? new TelegramMethods();
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        try {
            $this->botUser = BotUser::find($this->botUserId);

            $topicName = $this->generateNameTopic($this->botUser);

            $response = $this->telegramMethods->sendQueryTelegram('createForumTopic', [
                'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
                'name' => $topicName,
                'icon_custom_emoji_id' => __('icons.incoming'),
            ]);

            if ($response->ok === true) {
                $this->botUser->topic_id = $response->message_thread_id;
                $this->botUser->save();

                app(SendContactMessage::class)->execute($this->botUser);
                return;
            }

            if ($response->response_code === 429) {
                $retryAfter = $response->parameters->retry_after ?? 3;
                Log::warning("429 Too Many Requests. Retry after {$retryAfter} sec.");
                $this->release($retryAfter);
                return;
            }

            Log::error('TopicCreateJob: unknown error', [
                'response' => (array)$response,
            ]);
        } catch (\Throwable $e) {
            Log::channel('app')->log($e->getCode() === 1 ? 'warning' : 'error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    /**
     * Generate chat name.
     *
     * @param BotUser $botUser
     *
     * @return string
     */
    protected function generateNameTopic(BotUser $botUser): string
    {
        try {
            if ($botUser->platform === 'external_source') {
                $source = ExternalUser::getSourceById($botUser->chat_id);
                return "#{$botUser->chat_id} ({$source})";
            }

            $templateTopicName = (string) app(SettingsService::class)->get('telegram.template_topic_name');
            if (empty($templateTopicName)) {
                throw new \Exception('Template not found');
            }

            if (preg_match('/(\{platform})/', $templateTopicName)) {
                $templateTopicName = str_replace('{platform}', $botUser->platform, $templateTopicName);
            }

            $nameParts = $this->getPartsGenerateName($botUser->chat_id);
            if (empty($nameParts)) {
                throw new \Exception('Name parts not found');
            }

            // parsing template
            preg_match_all('/{([^}]+)}/', $templateTopicName, $matches);
            if (empty($matches[1])) {
                return trim($templateTopicName);
            }

            $paramsParts = array_combine($matches[0], $matches[1]);

            $topicName = $templateTopicName;
            foreach ($paramsParts as $key => $param) {
                $topicName = str_replace($key, (string) ($nameParts[$param] ?? ''), $topicName);
            }

            $topicName = preg_replace('/\(\s*\)/', '', $topicName) ?? $topicName;
            $topicName = preg_replace('/\s+/', ' ', $topicName) ?? $topicName;
            $topicName = trim($topicName);

            if ($topicName === '') {
                throw new \Exception('Topic name is empty');
            }

            return $topicName;
        } catch (\Throwable $e) {
            return '#' . $botUser->chat_id . ' (' . $botUser->platform . ')';
        }
    }

    /**
     * Get parts for chat name generation.
     *
     * @param int $chatId
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getPartsGenerateName(int $chatId): array
    {
        try {
            $chatDataQuery = app(GetChat::class)->execute($chatId);
            if (!$chatDataQuery->ok) {
                throw new \Exception('ChatData not found');
            }

            $chatData = $chatDataQuery->rawData['result'];
            if (empty($chatData)) {
                throw new \Exception('ChatData not found');
            }

            $neededKeys = [
                'id',
                'email',
                'first_name',
                'last_name',
                'username',
            ];
            return array_intersect_key($chatData, array_flip($neededKeys));
        } catch (Exception $e) {
            return [];
        }
    }
}
