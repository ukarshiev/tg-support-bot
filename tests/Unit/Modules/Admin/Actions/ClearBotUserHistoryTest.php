<?php

namespace Tests\Unit\Modules\Admin\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\ExternalMessage;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\Admin\Actions\ClearBotUserHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearBotUserHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_messages_but_keeps_the_chat(): void
    {
        $botUser = BotUser::create(['chat_id' => 8101, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'hi',
        ]);
        $attachment = MessageAttachment::create([
            'message_id' => $message->id, 'file_id' => 'f1', 'file_type' => 'photo',
        ]);
        $external = ExternalMessage::create(['message_id' => $message->id, 'text' => 'ext']);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id, 'message_id' => 'mid-1', 'text_ai' => 'draft',
        ]);
        $feedback = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'awaiting_rating']);

        (new ClearBotUserHistory())->execute($botUser);

        // Message history is gone…
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('message_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('external_messages', ['id' => $external->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $aiMessage->id]);

        // …but the chat itself and its feedback history remain.
        $this->assertDatabaseHas('bot_users', ['id' => $botUser->id]);
        $this->assertDatabaseHas('feedbacks', ['id' => $feedback->id]);
    }

    public function test_does_not_touch_other_conversations(): void
    {
        $target = BotUser::create(['chat_id' => 8102, 'platform' => 'telegram']);
        $other = BotUser::create(['chat_id' => 8103, 'platform' => 'telegram']);

        Message::create([
            'bot_user_id' => $target->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'target',
        ]);
        $otherMessage = Message::create([
            'bot_user_id' => $other->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'other',
        ]);

        (new ClearBotUserHistory())->execute($target);

        $this->assertSame(0, Message::where('bot_user_id', $target->id)->count());
        $this->assertDatabaseHas('messages', ['id' => $otherMessage->id]);
    }
}
