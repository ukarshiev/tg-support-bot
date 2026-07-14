<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\GetChat;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramTopicCreateJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_jobs_create_exactly_one_topic(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        app(SettingsService::class)->set('telegram.template_topic_name', '{first_name} #{id}');
        $botUser = BotUser::create(['chat_id' => 321, 'platform' => 'telegram']);

        $getChat = Mockery::mock(GetChat::class);
        $getChat->shouldReceive('execute')->once()->andReturn(new TelegramAnswerDto(
            ok: true,
            rawData: ['result' => ['id' => 321, 'first_name' => 'Client']],
        ));
        $this->app->instance(GetChat::class, $getChat);

        $telegram = Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->once()->with(
            'createForumTopic',
            Mockery::on(fn (array $params): bool =>
                $params['chat_id'] === '-100123456789'
                && $params['name'] === 'Client #321'),
        )->andReturn(new TelegramAnswerDto(ok: true, message_thread_id: 654));

        (new TopicCreateJob($botUser->id, $telegram))->handle();
        (new TopicCreateJob($botUser->id, $telegram))->handle();

        $this->assertSame(654, $botUser->refresh()->topic_id);
    }

    public function test_topic_creation_api_failure_is_not_swallowed(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $botUser = BotUser::create(['chat_id' => 322, 'platform' => 'telegram']);

        $getChat = Mockery::mock(GetChat::class);
        $getChat->shouldReceive('execute')->once()->andThrow(new \RuntimeException('getChat unavailable'));
        $this->app->instance(GetChat::class, $getChat);

        $telegram = Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->once()->andReturn(new TelegramAnswerDto(
            ok: false,
            response_code: 500,
            type_error: 'INTERNAL_SERVER_ERROR',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TopicCreateJob failed: code=500');
        (new TopicCreateJob($botUser->id, $telegram))->handle();
    }
}
