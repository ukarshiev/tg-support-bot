<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Actions\DeleteForumTopic;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class TopicCreateJobTest extends TestCase
{
    use RefreshDatabase;

    private TelegramUpdateDto $dto;

    private ?BotUser $botUser;

    public function setUp(): void
    {
        parent::setUp();

        Message::truncate();
        Queue::fake();

        $this->dto = TelegramUpdateDtoMock::getDto();
        $this->botUser = BotUser::getOrCreateByTelegramUpdate($this->dto);
    }

    protected function tearDown(): void
    {
        $botUser = BotUser::where([
            'chat_id' => $this->botUser->chat_id,
        ])->first();
        if (isset($botUser->topic_id)) {
            app(DeleteForumTopic::class)->execute($this->botUser);
        }

        parent::tearDown();
    }

    public function test_topic_name_template_allows_missing_optional_parts(): void
    {
        app(SettingsService::class)->set('telegram.template_topic_name', '{first_name} {last_name} ({username})');

        Http::fake([
            'https://api.telegram.org/bot*/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => $this->botUser->chat_id,
                    'first_name' => 'Test',
                    'username' => 'testuser',
                ],
            ], 200),
            'https://api.telegram.org/bot*/createForumTopic*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_thread_id' => 77,
                ],
            ], 200),
            'https://api.telegram.org/bot*/sendContact*' => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $job = new TopicCreateJob($this->botUser->id);
        $job->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/createForumTopic')
            && $request['name'] === 'Test (testuser)');
        $this->assertEquals(77, $this->botUser->fresh()->topic_id);
    }

    public function test_success_send_creates_message_record(): void
    {
        $topicId = 42;

        Http::fake([
            'https://api.telegram.org/bot*/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => $this->botUser->chat_id,
                    'first_name' => 'Test',
                    'username' => 'testuser',
                ],
            ], 200),
            'https://api.telegram.org/bot*/createForumTopic*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_thread_id' => $topicId,
                ],
            ], 200),
            'https://api.telegram.org/bot*/sendContact*' => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $job = new TopicCreateJob($this->botUser->id);
        $job->handle();

        $this->assertEquals($topicId, $this->botUser->fresh()->topic_id);
    }
}
