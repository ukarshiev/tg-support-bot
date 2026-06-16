<?php

namespace Tests\Unit\Models;

use App\Models\BotUser;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    // ── sender() relation ──────────────────────────────────────────────────────

    public function test_sender_relation_resolves_to_user(): void
    {
        $user = User::factory()->create(['name' => 'Operator One']);
        $botUser = BotUser::create(['chat_id' => 100, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
            'sender_user_id' => $user->id,
            'sender_name' => $user->name,
        ]);

        $this->assertInstanceOf(User::class, $message->sender);
        $this->assertSame($user->id, $message->sender->id);
        $this->assertSame('Operator One', $message->sender->name);
    }

    public function test_sender_relation_is_null_when_no_sender_user_id(): void
    {
        $botUser = BotUser::create(['chat_id' => 101, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
        ]);

        $this->assertNull($message->sender);
        $this->assertNull($message->sender_user_id);
        $this->assertNull($message->sender_name);
    }

    // ── nullOnDelete behaviour ─────────────────────────────────────────────────

    public function test_deleting_user_nulls_sender_user_id_but_preserves_sender_name(): void
    {
        $user = User::factory()->create(['name' => 'Deletable Operator']);
        $botUser = BotUser::create(['chat_id' => 102, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
            'sender_user_id' => $user->id,
            'sender_name' => 'Deletable Operator',
        ]);

        $user->delete();

        $message->refresh();

        $this->assertNull($message->sender_user_id);
        $this->assertSame('Deletable Operator', $message->sender_name);
    }

    // ── $fillable ──────────────────────────────────────────────────────────────

    public function test_sender_user_id_and_sender_name_are_fillable(): void
    {
        $user = User::factory()->create(['name' => 'Fill Test']);
        $botUser = BotUser::create(['chat_id' => 103, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 0,
            'sender_user_id' => $user->id,
            'sender_name' => 'Fill Test',
        ]);

        $this->assertSame($user->id, $message->sender_user_id);
        $this->assertSame('Fill Test', $message->sender_name);
    }
}
