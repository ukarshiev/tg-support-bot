<?php

namespace Tests\Feature\Admin;

use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAttachment(string $fileId, string $name = 'photo.jpg'): MessageAttachment
    {
        $botUser = BotUser::create(['chat_id' => 7000, 'platform' => 'max']);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'max',
            'message_type' => 'outgoing',
            'from_id' => 0, 'to_id' => 0, 'text' => null,
        ]);

        return MessageAttachment::create([
            'message_id' => $message->id,
            'file_id' => $fileId,
            'file_type' => 'photo',
            'file_name' => $name,
        ]);
    }

    public function test_streams_a_locally_stored_attachment_to_an_authed_admin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('chat-attachments/abc.jpg', 'IMAGE-BYTES');

        $attachment = $this->makeAttachment('chat-attachments/abc.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.chat-attachment', $attachment->id, false))
            ->assertOk()
            ->assertStreamedContent('IMAGE-BYTES');
    }

    public function test_guests_are_redirected(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('chat-attachments/abc.jpg', 'X');
        $attachment = $this->makeAttachment('chat-attachments/abc.jpg');

        $this->get(route('admin.chat-attachment', $attachment->id, false))
            ->assertRedirect();
    }

    public function test_404_when_file_id_is_not_a_local_chat_attachment(): void
    {
        $attachment = $this->makeAttachment('https://cdn.max.ru/external/photo.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.chat-attachment', $attachment->id, false))
            ->assertNotFound();
    }

    public function test_404_when_file_missing_on_disk(): void
    {
        Storage::fake('local');
        $attachment = $this->makeAttachment('chat-attachments/missing.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.chat-attachment', $attachment->id, false))
            ->assertNotFound();
    }
}
