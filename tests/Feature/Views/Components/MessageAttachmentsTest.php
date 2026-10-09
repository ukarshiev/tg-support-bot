<?php

namespace Tests\Feature\Views\Components;

use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Regression coverage for attachment previews in the chat workspace.
 */
class MessageAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_renders_a_wide_player_instead_of_a_file_link(): void
    {
        $html = $this->renderAttachment('video');

        $this->assertStringContainsString('<video controls', $html);
        $this->assertStringContainsString('max-w-[320px] max-h-[360px]', $html);
        $this->assertStringContainsString('preload="metadata"', $html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*video\s*<\/a>/', $html);
    }

    public function test_video_note_keeps_its_existing_player_dimensions(): void
    {
        $html = $this->renderAttachment('video_note');

        $this->assertStringContainsString('<video controls', $html);
        $this->assertStringContainsString('max-w-[240px] max-h-[240px] rounded-lg', $html);
        $this->assertStringNotContainsString('preload=', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    public function test_document_still_renders_a_file_link(): void
    {
        $html = $this->renderAttachment('document');

        $this->assertMatchesRegularExpression('/<a\s+href="/', $html);
        $this->assertMatchesRegularExpression('/>\s*document\s*<\/a>/', $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    private function renderAttachment(string $fileType): string
    {
        $attachment = new MessageAttachment([
            'file_id' => 'telegram-file-id',
            'file_type' => $fileType,
        ]);

        return Blade::render(
            '<x-message-attachments :attachments="$attachments" platform="telegram" />',
            ['attachments' => collect([$attachment])],
        );
    }
}
