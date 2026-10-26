<?php

namespace Tests\Unit\Modules\Telegram\Support;

use App\Modules\Telegram\Support\IncomingTextLinkExpander;
use PHPUnit\Framework\TestCase;

class IncomingTextLinkExpanderTest extends TestCase
{
    /** Verify hidden tracking URLs survive unchanged. */
    public function test_expands_text_link_preserving_query_bytes(): void
    {
        $url = 'https://ashoo.cz/?a=1&utm_source=x&utm_content=%D0%90&b=two+words';

        $this->assertSame('Ashoo.cz (' . $url . ')', IncomingTextLinkExpander::expand('Ashoo.cz', [
            ['type' => 'text_link', 'offset' => 0, 'length' => 8, 'url' => $url],
        ]));
    }

    /** Telegram offsets count UTF-16 units, including surrogate pairs. */
    public function test_expands_after_emoji_at_the_original_utf16_offset(): void
    {
        $this->assertSame('❗️ 😀 Ashoo.cz (https://example.test) конец', IncomingTextLinkExpander::expand('❗️ 😀 Ashoo.cz конец', [
            ['type' => 'text_link', 'offset' => 6, 'length' => 8, 'url' => 'https://example.test'],
        ]));
    }

    /** Existing URLs in anchors must not be duplicated. */
    public function test_does_not_duplicate_a_url_already_inside_the_anchor(): void
    {
        foreach (['https://x.test', 'See https://x.test here'] as $text) {
            $this->assertSame($text, IncomingTextLinkExpander::expand($text, [
                ['type' => 'text_link', 'offset' => 0, 'length' => strlen($text), 'url' => 'https://x.test'],
            ]));
        }
    }

    /** Unsorted links expand in source order without shifting subsequent offsets. */
    public function test_expands_multiple_links_in_offset_order(): void
    {
        $this->assertSame('One (tg://one) Two (mailto:two@example.test)', IncomingTextLinkExpander::expand('One Two', [
            ['type' => 'text_link', 'offset' => 4, 'length' => 3, 'url' => 'mailto:two@example.test'],
            ['type' => 'bold', 'offset' => 0, 'length' => 7],
            ['type' => 'text_link', 'offset' => 0, 'length' => 3, 'url' => 'tg://one'],
        ]));
    }

    /** Ignore overlapping, nested, invalid and out-of-bounds ranges. */
    public function test_skips_overlapping_and_invalid_entities(): void
    {
        $this->assertSame('abcd (https://one.test)ef', IncomingTextLinkExpander::expand('abcdef', [
            ['type' => 'text_link', 'offset' => -1, 'length' => 1, 'url' => 'bad'],
            ['type' => 'text_link', 'offset' => 0, 'length' => 4, 'url' => 'https://one.test'],
            ['type' => 'text_link', 'offset' => 1, 'length' => 1, 'url' => 'nested'],
            ['type' => 'text_link', 'offset' => 3, 'length' => 3, 'url' => 'overlap'],
            ['type' => 'text_link', 'offset' => 4, 'length' => 3, 'url' => 'outside'],
            ['type' => 'text_link', 'offset' => 4, 'length' => 1, 'url' => ''],
            ['type' => 'text_link', 'offset' => 4, 'length' => 0, 'url' => 'empty'],
        ]));
    }

    /** Invalid offsets must never split an emoji's UTF-16 surrogate pair. */
    public function test_skips_entities_that_split_surrogate_pairs(): void
    {
        $this->assertSame('😀 OK (https://x.test)', IncomingTextLinkExpander::expand('😀 OK', [
            ['type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'bad'],
            ['type' => 'text_link', 'offset' => 1, 'length' => 1, 'url' => 'bad'],
            ['type' => 'text_link', 'offset' => 3, 'length' => 2, 'url' => 'https://x.test'],
        ]));
    }

    /** Empty inputs and ordinary raw URLs remain unchanged. */
    public function test_returns_original_text_without_usable_entities(): void
    {
        $this->assertNull(IncomingTextLinkExpander::expand(null, null));
        $this->assertSame('', IncomingTextLinkExpander::expand('', []));
        $this->assertSame('https://x.test?a=1&b=2', IncomingTextLinkExpander::expand('https://x.test?a=1&b=2', null));
        $this->assertSame('Text', IncomingTextLinkExpander::expand('Text', []));
        $this->assertNull(IncomingTextLinkExpander::expand(null, [['type' => 'text_link']]));
    }
}
