<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Actions\SelectLanguage;
use App\Modules\Telegram\Actions\SendStartMessage;
use App\Modules\Telegram\Actions\ShowLanguageSelectionPage;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendStartMessageTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_send_start_message_shows_language_keyboard(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['message']['text'] = '/start';

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);

        app(SendStartMessage::class)->execute($dto);

        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            return $job->queryParams->methodQuery === 'deleteMessage';
        });

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];

        $this->assertEquals($botUser->id, $job->botUserId);
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertEquals('Choose language', $job->queryParams->text);
        $this->assertNotNull($job->queryParams->reply_markup);
        $this->assertEquals('select_language:ru', $job->queryParams->reply_markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertEquals('select_language:en', $job->queryParams->reply_markup['inline_keyboard'][0][1]['callback_data']);
        $this->assertEquals('select_language_page:2', $job->queryParams->reply_markup['inline_keyboard'][7][1]['callback_data']);
        $this->assertCount(8, $job->queryParams->reply_markup['inline_keyboard']);
    }

    public function test_send_start_message_sends_selector_even_when_selector_already_exists(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['message']['text'] = '/start';

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 1,
            'to_id' => 2,
            'text' => "Выберите язык / Choose your language:\nСтраница 1/2",
        ]);

        app(SendStartMessage::class)->execute($dto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);
        $this->assertEquals('Choose language', $pushed[0]['job']->queryParams->text);
        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            return $job->queryParams->methodQuery === 'deleteMessage';
        });
    }

    public function test_force_language_selector_sends_even_when_language_already_selected(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['message']['text'] = '/lang';

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);
        $botUser->update([
            'preferred_language_code' => 'pl',
            'preferred_language_name' => 'Polski',
            'preferred_language_selected_at' => now(),
        ]);

        app(SendStartMessage::class)->force($dto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);
        $this->assertEquals('Wybierz język', $pushed[0]['job']->queryParams->text);
    }

    public function test_language_page_callback_edits_same_message(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 'callback-123',
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'ru',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language_page:2',
        ];
        unset($dtoUpdateParams['message']);

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($dto->chatId, 'telegram');

        app(ShowLanguageSelectionPage::class)->execute($botUser, $dto);

        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            return $job->queryParams->methodQuery === 'answerCallbackQuery';
        });
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'callback-123'
            && !isset($request['text']));

        /** @phpstan-ignore-next-line */
        $editJobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $editJobs);
        $job = $editJobs[0]['job'];

        $this->assertEquals('editMessageText', $job->queryParams->methodQuery);
        $this->assertEquals($dto->messageId, $job->queryParams->message_id);
        $this->assertEquals('Choose language', $job->queryParams->text);
        $this->assertEquals('select_language:fa', $job->queryParams->reply_markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertEquals('select_language_page:1', $job->queryParams->reply_markup['inline_keyboard'][5][0]['callback_data']);
    }

    public function test_select_language_saves_choice_and_sends_greeting(): void
    {
        $welcome = AutoReply::query()
            ->where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', '__system_welcome__')
            ->first();

        if ($welcome === null) {
            $welcome = AutoReply::create([
                'type' => AutoReply::TYPE_WELCOME,
                'trigger' => '__system_welcome__',
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        } else {
            $welcome->update([
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        }

        AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'start',
            'response' => 'Короткий fallback, который не должен победить',
            'enabled' => true,
        ]);

        AutoReplyTranslation::updateOrCreate(
            [
                'auto_reply_id' => $welcome->id,
                'locale' => 'en',
            ],
            [
                'text' => 'Full welcome text with CONTACT RULES and <x id= "tgph0" > https://t.me/test< / x>',
                'status' => AutoReplyTranslation::STATUS_STALE,
                'source' => AutoReplyTranslation::SOURCE_AUTO,
            ],
        );

        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 123,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'en',
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
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '123'
            && !isset($request['text']));

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);
        $this->assertEquals('Hello! How can I help you?', $pushed[0]['job']->queryParams->text);
        $this->assertNull($pushed[0]['job']->queryParams->parse_mode);

        Queue::assertPushed(SendContactMessageJob::class, fn (SendContactMessageJob $job): bool =>
            $job->botUserId === $botUser->id
            && $job->telegramLanguageCode === 'en');
    }

    public function test_new_language_callback_sends_welcome_even_if_same_greeting_exists_in_history(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 123,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'en',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language:en',
        ];
        unset($dtoUpdateParams['message']);

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($dto->chatId, 'telegram');
        $greeting = app(\App\Modules\Telegram\Services\SupportLanguageService::class)->greeting('en');
        $botUser->update([
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
            'preferred_language_selected_at' => now()->subMinute(),
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 123,
            'to_id' => 456,
            'text' => $greeting,
        ]);

        app(SelectLanguage::class)->execute($botUser, $dto);

        Queue::assertPushed(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job) use ($greeting): bool {
            return $job->queryParams->text === $greeting;
        });
        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            return str_contains((string) ($job->queryParams->text ?? ''), 'КОНТАКТНАЯ ИНФОРМАЦИЯ');
        });
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '123'
            && !isset($request['text']));
    }

    public function test_repeated_language_change_does_not_send_contact_summary_again(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 456,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'pl',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language:pl',
        ];
        unset($dtoUpdateParams['message']);

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($dto->chatId, 'telegram');
        $botUser->update([
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
            'preferred_language_selected_at' => now()->subDay(),
        ]);

        app(SelectLanguage::class)->execute($botUser, $dto);
        $botUser->refresh();

        $this->assertEquals('pl', $botUser->preferred_language_code);
        $this->assertEquals('Polski', $botUser->preferred_language_name);

        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class, function (SendTelegramSimpleQueryJob $job): bool {
            return str_contains((string) ($job->queryParams->text ?? ''), 'КОНТАКТНАЯ ИНФОРМАЦИЯ');
        });
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '456'
            && !isset($request['text']));
    }

    public function test_undelivered_existing_welcome_does_not_block_new_welcome_message(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 789,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'pl',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language:pl',
        ];
        unset($dtoUpdateParams['message']);

        $dto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($dto->chatId, 'telegram');
        $greeting = app(\App\Modules\Telegram\Services\SupportLanguageService::class)->greeting('pl');

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
            'text' => $greeting,
        ]);

        app(SelectLanguage::class)->execute($botUser, $dto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $welcomeJobs = collect($pushed)->filter(fn (array $payload): bool => $payload['job']->queryParams->text === $greeting);

        $this->assertCount(1, $welcomeJobs);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '789'
            && !isset($request['text']));
    }

    public function test_two_distinct_language_callbacks_each_queue_a_welcome(): void
    {
        $dtoUpdateParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoUpdateParams['callback_query'] = [
            'id' => 901,
            'from' => [
                'id' => $dtoUpdateParams['message']['from']['id'],
                'is_bot' => false,
                'first_name' => 'Test',
                'language_code' => 'pl',
            ],
            'message' => $dtoUpdateParams['message'],
            'data' => 'select_language:pl',
        ];
        unset($dtoUpdateParams['message']);

        $firstDto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        $botUser = BotUser::getUserByChatId($firstDto->chatId, 'telegram');
        $greeting = app(\App\Modules\Telegram\Services\SupportLanguageService::class)->greeting('pl');

        app(SelectLanguage::class)->execute($botUser, $firstDto);

        $dtoUpdateParams['callback_query']['id'] = 902;
        $secondDto = TelegramUpdateDtoMock::getDto($dtoUpdateParams);
        app(SelectLanguage::class)->execute($botUser->refresh(), $secondDto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $welcomeJobs = collect($pushed)->filter(fn (array $payload): bool => $payload['job']->queryParams->text === $greeting);

        $this->assertCount(2, $welcomeJobs);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '901'
            && !isset($request['text']));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) $request['callback_query_id'] === '902'
            && !isset($request['text']));
    }
}
