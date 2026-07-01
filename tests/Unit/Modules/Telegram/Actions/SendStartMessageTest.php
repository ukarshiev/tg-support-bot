<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\SelectLanguage;
use App\Modules\Telegram\Actions\SendStartMessage;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendStartMessageTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_send_start_message_shows_language_keyboard(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['message']['text'] = '/start';

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);

        app(SendStartMessage::class)->execute($dto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];

        $this->assertEquals($botUser->id, $job->botUserId);
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertEquals('Выберите язык / Choose your language:', $job->queryParams->text);
        $this->assertNotNull($job->queryParams->reply_markup);
        $this->assertEquals('select_language:ru', $job->queryParams->reply_markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertEquals('select_language:en', $job->queryParams->reply_markup['inline_keyboard'][0][1]['callback_data']);
    }

    public function test_select_language_saves_choice_and_sends_greeting(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 123,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'ru',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language:en',
        ];
        unset($dtoUpdateParams['message']);

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($dto->chatId, 'telegram');

        app(SelectLanguage::class)->execute($botUser, $dto);
        $botUser->refresh();

        $this->assertEquals('en', $botUser->preferred_language_code);
        $this->assertEquals('English', $botUser->preferred_language_name);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);
        $this->assertEquals('Good day! How can I help you?', $pushed[0]['job']->queryParams->text);
    }
}
