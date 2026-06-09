<?php

namespace Tests\Unit\Modules\Admin\Actions;

use App\Models\AiCondition;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\ExternalMessage;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Modules\Admin\Actions\DeleteBotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteBotUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_bot_user_and_all_related_records(): void
    {
        $botUser = BotUser::create(['chat_id' => 4001, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'hi',
        ]);
        $attachment = MessageAttachment::create([
            'message_id' => $message->id,
            'file_id' => 'file_1',
            'file_type' => 'photo',
        ]);
        $external = ExternalMessage::create([
            'message_id' => $message->id,
            'text' => 'ext',
        ]);
        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'message_id' => 'mid-1',
            'text_ai' => 'draft',
        ]);
        $aiCondition = AiCondition::create([
            'bot_user_id' => $botUser->id,
            'active' => true,
        ]);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
        ]);

        (new DeleteBotUser())->execute($botUser);

        $this->assertDatabaseMissing('bot_users', ['id' => $botUser->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('message_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('external_messages', ['id' => $external->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $aiMessage->id]);
        $this->assertDatabaseMissing('ai_conditions', ['id' => $aiCondition->id]);
        $this->assertDatabaseMissing('feedbacks', ['id' => $feedback->id]);
    }

    public function test_does_not_touch_other_conversations(): void
    {
        $target = BotUser::create(['chat_id' => 4002, 'platform' => 'telegram']);
        $other = BotUser::create(['chat_id' => 4003, 'platform' => 'telegram']);

        Message::create([
            'bot_user_id' => $target->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'target',
        ]);
        $otherMessage = Message::create([
            'bot_user_id' => $other->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'other',
        ]);

        (new DeleteBotUser())->execute($target);

        $this->assertDatabaseHas('bot_users', ['id' => $other->id]);
        $this->assertDatabaseHas('messages', ['id' => $otherMessage->id]);
        $this->assertSame(0, Message::where('bot_user_id', $target->id)->count());
    }
}
