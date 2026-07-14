<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Actions\BannedContactMessage;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BannedContactMessageTest extends TestCase
{
    use RefreshDatabase;

    private ?BotUser $botUser;

    public function setUp(): void
    {
        parent::setUp();

        Message::truncate();
        Queue::fake();

        $chatId = time();
        $this->botUser = BotUser::getUserByChatId($chatId, 'tg');
    }

    public function test_ban_status_true(): void
    {
        app(BannedContactMessage::class)->execute($this->botUser, true);

        Queue::assertPushed(SendContactMessageJob::class, fn (SendContactMessageJob $job): bool =>
            $job->botUserId === $this->botUser->id);
    }
}
