<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Actions\BanMessage;
use App\Modules\Telegram\Jobs\SendTelegramTopicMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\TelegramUpdateDto_GroupMock;
use Tests\TestCase;

class BanMessageTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->botUser = BotUser::getUserByChatId(time(), 'telegram');
    }

    public function test_send_ban_message_with_correct_text(): void
    {
        $dto = TelegramUpdateDto_GroupMock::getDto();

        app(BanMessage::class)->execute($this->botUser->id, $dto);

        Queue::assertPushed(SendTelegramTopicMessageJob::class, fn (SendTelegramTopicMessageJob $job): bool =>
            $job->botUserId === $this->botUser->id
            && $job->text === __('messages.ban_bot')
            && $job->queue === 'telegram-mirror');
    }
}
