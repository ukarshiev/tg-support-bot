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

    public function test_source_event_is_saved_idempotently_and_scoped_by_platform(): void
    {
        $telegramUser = BotUser::create(['chat_id' => 200, 'platform' => 'telegram']);
        $secondTelegramUser = BotUser::create(['chat_id' => 201, 'platform' => 'telegram']);
        $vkUser = BotUser::create(['chat_id' => 200, 'platform' => 'vk']);

        $first = Message::firstOrCreateForSourceEvent('telegram', 12345, [
            'bot_user_id' => $telegramUser->id,
            'message_type' => 'incoming',
            'from_id' => 12345,
            'to_id' => 0,
            'text' => 'Первое содержимое',
        ]);
        $duplicate = Message::firstOrCreateForSourceEvent('telegram', 12345, [
            'bot_user_id' => $telegramUser->id,
            'message_type' => 'incoming',
            'from_id' => 12345,
            'to_id' => 0,
            'text' => 'Повтор не должен перезаписать сообщение',
        ]);
        $sameIdOnVk = Message::firstOrCreateForSourceEvent('vk', 12345, [
            'bot_user_id' => $vkUser->id,
            'message_type' => 'incoming',
            'from_id' => 12345,
            'to_id' => 0,
        ]);
        $sameIdInOtherTelegramChat = Message::firstOrCreateForSourceEvent('telegram', 12345, [
            'bot_user_id' => $secondTelegramUser->id,
            'message_type' => 'incoming',
            'from_id' => 12345,
            'to_id' => 0,
        ]);

        $this->assertTrue($first->is($duplicate));
        $this->assertFalse($first->is($sameIdOnVk));
        $this->assertFalse($first->is($sameIdInOtherTelegramChat));
        $this->assertSame('Первое содержимое', $duplicate->text);
        $this->assertSame(Message::KIND_CHAT, $first->message_kind);
        $this->assertNull($first->delivery_status);
        $this->assertDatabaseCount('messages', 3);
    }

    public function test_structural_kind_and_delivery_status_are_fillable(): void
    {
        $botUser = BotUser::create(['chat_id' => 201, 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'message_kind' => Message::KIND_SYSTEM,
            'delivery_status' => Message::DELIVERY_PENDING,
            'from_id' => 0,
            'to_id' => 201,
        ]);

        $this->assertSame(Message::KIND_SYSTEM, $message->message_kind);
        $this->assertSame(Message::DELIVERY_PENDING, $message->delivery_status);
    }

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
