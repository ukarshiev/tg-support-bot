<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Modules\Telegram\Jobs\SendTelegramTopicMessageJob;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendTelegramTopicMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_message_without_topic_waits_for_creation(): void
    {
        Queue::fake();
        $botUser = BotUser::create(['chat_id' => 501, 'platform' => 'telegram', 'topic_id' => null]);
        Http::fake();

        (new SendTelegramTopicMessageJob($botUser->id, 'Служебное сообщение'))->handle();

        Http::assertNothingSent();
        Queue::assertPushed(TopicCreateJob::class);
    }

    public function test_service_message_is_never_sent_without_message_thread_id(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.token', 'test-token');
        $botUser = BotUser::create(['chat_id' => 502, 'platform' => 'telegram', 'topic_id' => 765]);
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 12, 'chat' => ['id' => -100123456789]],
        ])]);

        (new SendTelegramTopicMessageJob($botUser->id, 'Служебное сообщение'))->handle();

        Http::assertSent(fn ($request): bool =>
            str_ends_with($request->url(), '/sendMessage')
            && (int) $request['message_thread_id'] === 765);
    }
}
