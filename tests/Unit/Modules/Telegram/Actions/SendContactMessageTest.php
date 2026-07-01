<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\SendContactMessage;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
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

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];

        // Assert
        $this->assertEquals('-100000000000', $job->queryParams->chat_id);
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertStringContainsString('Выбранный язык: English', $job->queryParams->text);
        $this->assertStringContainsString('Телефон: не передан', $job->queryParams->text);
        $this->assertStringContainsString('Регион: не определён', $job->queryParams->text);
    }
}
