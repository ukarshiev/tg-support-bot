<?php

namespace Tests\Feature\Admin;

use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TelegramAttachmentUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_attachment_component_uses_a_signed_file_url(): void
    {
        $attachment = new MessageAttachment([
            'file_id' => 'telegram-file-id',
            'file_type' => 'document',
            'file_name' => 'document.pdf',
        ]);

        $html = Blade::render(
            '<x-message-attachments :attachments="$attachments" platform="telegram" />',
            ['attachments' => new Collection([$attachment])],
        );

        $this->assertStringContainsString('/api/files/telegram-file-id?', $html);
        $this->assertStringContainsString('signature=', $html);
        $this->assertStringNotContainsString('href="http://localhost/api/files/telegram-file-id"', $html);
    }
}
