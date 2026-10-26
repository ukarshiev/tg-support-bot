<?php

namespace Tests\Unit\Support\Html;

use App\Support\Html\LinkifiedText;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\TestCase;

class LinkifiedTextTest extends TestCase
{
    /** Escape markup and attribute injection while linking only the URL. */
    public function test_escapes_xss_in_text_and_around_urls(): void
    {
        $html = (new LinkifiedText())->toHtml('<script>alert(1)</script> https://x.test/"onmouseover="alert(1)')->toHtml();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('href="https://x.test/"', $html);
        $this->assertStringContainsString('&quot;onmouseover=&quot;alert(1)', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString(' onmouseover="', $html);
    }

    /** Query separators are escaped once in both the href and visible URL. */
    public function test_preserves_query_and_whitespace_and_supports_white_links(): void
    {
        $text = "  https://x.test/?a=1&utm_source=x\nnext";
        $result = (new LinkifiedText())->toHtml($text, 'text-white');

        $this->assertInstanceOf(HtmlString::class, $result);
        $this->assertSame("  <a href=\"https://x.test/?a=1&amp;utm_source=x\" target=\"_blank\" rel=\"noopener noreferrer nofollow\" class=\"underline break-all text-white\">https://x.test/?a=1&amp;utm_source=x</a>\nnext", $result->toHtml());
    }

    /** Sentence punctuation belongs outside the clickable URL. */
    public function test_keeps_trailing_punctuation_outside_links(): void
    {
        foreach (['.', ',', ';', ':', '!', '?', ')', '»', ').,'] as $punctuation) {
            $html = (new LinkifiedText())->toHtml('https://x.test/' . $punctuation)->toHtml();
            $this->assertStringContainsString('>https://x.test/</a>' . $punctuation, $html);
        }
    }

    /** Escaped markup before several links must not shift replacement positions. */
    public function test_links_multiple_urls_after_escaped_text(): void
    {
        $html = (new LinkifiedText())->toHtml('<b> & https://one.test/?a=1&b=2, https://two.test/.')->toHtml();

        $this->assertStringStartsWith('&lt;b&gt; &amp; <a href="https://one.test/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('>https://one.test/?a=1&amp;b=2</a>, <a href="https://two.test/"', $html);
        $this->assertStringEndsWith('>https://two.test/</a>.', $html);
    }

    /** Only HTTP and HTTPS are linkified; plain text is escaped verbatim. */
    public function test_returns_escaped_plain_text_and_empty_html_for_null(): void
    {
        foreach (['javascript:alert(1)', 'Text <b> & "quoted"', 'https is a word'] as $text) {
            $this->assertSame(e($text), (new LinkifiedText())->toHtml($text)->toHtml());
        }
        $this->assertSame('', (new LinkifiedText())->toHtml(null)->toHtml());
        $this->assertStringContainsString('href="http://x.test"', (new LinkifiedText())->toHtml('http://x.test')->toHtml());
    }
}
