<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\SendContactMessage;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendContactMessageTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'username' => 'client_user',
                    'first_name' => 'Client',
                    'last_name' => 'User',
                    'language_code' => 'ru',
                ],
            ], 200),
        ]);

        $this->botUser = BotUser::getUserByChatId(time(), 'telegram');
        $this->botUser->update([
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
        ]);
    }

    public function test_send_contact_message(): void
    {
        app(SendContactMessage::class)->execute($this->botUser);

        Queue::assertPushed(SendContactMessageJob::class, fn (SendContactMessageJob $job): bool =>
            $job->botUserId === $this->botUser->id
            && $job->queue === 'telegram-mirror');
    }

    public function test_contact_card_always_contains_topic_id(): void
    {
        $this->botUser->update(['topic_id' => 777]);

        $dto = app(SendContactMessage::class)->getQueryParams($this->botUser);

        $this->assertEquals('-100000000000', $dto->chat_id);
        $this->assertSame(777, $dto->message_thread_id);
        $this->assertEquals('sendMessage', $dto->methodQuery);
        $this->assertStringContainsString('Выбранный язык: English', $dto->text);
        $this->assertStringContainsString('Телефон: не передан', $dto->text);
        $this->assertStringContainsString('Регион: не определён', $dto->text);
    }

    public function test_contact_card_cannot_be_built_for_general_topic(): void
    {
        $this->botUser->update(['topic_id' => null]);

        $this->expectException(\RuntimeException::class);
        app(SendContactMessage::class)->getQueryParams($this->botUser);
    }
}
