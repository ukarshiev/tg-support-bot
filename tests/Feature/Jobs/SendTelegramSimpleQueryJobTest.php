<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\DeleteForumTopic;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendTelegramSimpleQueryJobTest extends TestCase
{
    use RefreshDatabase;

    private TelegramUpdateDto $dto;

    private ?BotUser $botUser;

    private int $groupId;

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->dto = TelegramUpdateDtoMock::getDto();
        $this->botUser = BotUser::getOrCreateByTelegramUpdate($this->dto);

        $this->groupId = time();
        $this->botUser->update(['topic_id' => 42]);
    }

    public function test_edit_forum_topic_outgoing(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/editForumTopic' => Http::response([
                'ok' => true,
                'result' => true,
            ], 200),
        ]);

        $job = new SendTelegramSimpleQueryJob(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => $this->groupId,
            'message_thread_id' => $this->botUser->topic_id,
            'icon_custom_emoji_id' => __('icons.outgoing'),
        ]));

        $job->handle();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'editForumTopic')) {
                return false;
            }
            $data = $request->data();

            return ($data['chat_id'] ?? null) == $this->groupId
                && ($data['message_thread_id'] ?? null) == $this->botUser->topic_id
                && ($data['icon_custom_emoji_id'] ?? null) == __('icons.outgoing');
        });

        $job = new SendTelegramSimpleQueryJob(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => '-100000000000',
            'message_thread_id' => $this->botUser->topic_id,
            'icon_custom_emoji_id' => __('icons.incoming'),
        ]));

        $job->handle();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'editForumTopic')) {
                return false;
            }
            $data = $request->data();

            return ($data['chat_id'] ?? null) == '-100000000000'
                && ($data['message_thread_id'] ?? null) == $this->botUser->topic_id
                && ($data['icon_custom_emoji_id'] ?? null) == __('icons.incoming');
        });

        $botUser = BotUser::where([
            'chat_id' => time(),
        ])->first();
        if (isset($botUser->topic_id)) {
            app(DeleteForumTopic::class)->execute($this->botUser);
        }
    }

    public function test_permanent_client_delivery_error_throws_and_stops_job_chain(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $job = new SendTelegramSimpleQueryJob(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Проверка доставки',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram query rejected');

        $job->handle();
    }
}
