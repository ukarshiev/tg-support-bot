<?php

namespace Tests\Unit\Modules\Telegram\Support;

use App\Modules\Telegram\Support\TelegramOutgoingMessageLimiter;
use PHPUnit\Framework\TestCase;

class TelegramOutgoingMessageLimiterTest extends TestCase
{
    private TelegramOutgoingMessageLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new TelegramOutgoingMessageLimiter();
    }

    public function test_text_at_exact_limit_is_not_split(): void
    {
        $requests = $this->limiter->prepare('sendMessage', [
            'chat_id' => 1,
            'text' => str_repeat('я', TelegramOutgoingMessageLimiter::TEXT_LIMIT),
        ]);

        $this->assertCount(1, $requests);
        $this->assertSame(TelegramOutgoingMessageLimiter::TEXT_LIMIT, mb_strlen($requests[0]['data']['text']));
    }

    public function test_text_above_limit_is_split_and_delivered_completely(): void
    {
        $text = str_repeat("строка со словами\n", 400);
        $requests = $this->limiter->prepare('sendMessage', ['chat_id' => 1, 'text' => $text]);

        $this->assertGreaterThan(1, count($requests));
        $this->assertSame($text, implode('', array_column(array_column($requests, 'data'), 'text')));
        foreach ($requests as $request) {
            $this->assertLessThanOrEqual(TelegramOutgoingMessageLimiter::TEXT_LIMIT, mb_strlen($request['data']['text']));
        }
    }

    public function test_each_html_part_is_balanced_and_preserves_visible_content(): void
    {
        $html = '<b>' . str_repeat('важное слово &amp; пояснение ', 300) . '</b>';
        $parts = $this->limiter->split($html, 'html');

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(TelegramOutgoingMessageLimiter::TEXT_LIMIT, mb_strlen($part));
            $this->assertSame(substr_count($part, '<b>'), substr_count($part, '</b>'));
            $this->assertSame(substr_count($part, '&amp;'), preg_match_all('/&amp;/', $part));
        }

        $expected = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $actual = html_entity_decode(strip_tags(implode('', $parts)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame($expected, $actual);
    }

    public function test_markdownv2_formatting_is_closed_and_reopened_for_every_part(): void
    {
        $markdown = '*' . str_repeat('важное слово ', 480) . '[ссылка](https://example.com/path)' . str_repeat(' продолжение', 40) . '*';
        $parts = $this->limiter->split($markdown, 'MarkdownV2');

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(TelegramOutgoingMessageLimiter::TEXT_LIMIT, mb_strlen($part));
            $this->assertStringStartsWith('*', $part);
            $this->assertStringEndsWith('*', $part);
            $this->assertSame(0, substr_count($part, '*') % 2);
            $this->assertSame(substr_count($part, '['), substr_count($part, ']('));
        }
    }

    public function test_markdown_patterns_compile(): void
    {
        $this->assertNotFalse(@preg_match(TelegramOutgoingMessageLimiter::MARKDOWN_TOKEN_PATTERN, ''));
        $this->assertNotFalse(@preg_match(TelegramOutgoingMessageLimiter::MARKDOWN_CONTROL_PATTERN, ''));
    }

    public function test_long_caption_is_truncated_with_marker_and_full_text_follows(): void
    {
        $caption = str_repeat('полная подпись со словами ', 70);
        $requests = $this->limiter->prepare('sendPhoto', [
            'chat_id' => 1,
            'message_thread_id' => 2,
            'photo' => 'file-id',
            'caption' => $caption,
            'parse_mode' => 'html',
        ]);

        $this->assertCount(2, $requests);
        $this->assertSame('sendPhoto', $requests[0]['method']);
        $this->assertTrue($requests[0]['primary']);
        $this->assertLessThanOrEqual(TelegramOutgoingMessageLimiter::CAPTION_LIMIT, mb_strlen($requests[0]['data']['caption']));
        $this->assertStringEndsWith(TelegramOutgoingMessageLimiter::CAPTION_TRUNCATION_MARKER, $requests[0]['data']['caption']);
        $this->assertSame('sendMessage', $requests[1]['method']);
        $this->assertFalse($requests[1]['primary']);
        $this->assertSame($caption, $requests[1]['data']['text']);
        $this->assertSame(2, $requests[1]['data']['message_thread_id']);
        $this->assertArrayNotHasKey('photo', $requests[1]['data']);
    }
}
