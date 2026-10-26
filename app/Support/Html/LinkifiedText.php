<?php

namespace App\Support\Html;

use Illuminate\Support\HtmlString;

/** Render untrusted plain text with safe, clickable HTTP links. */
class LinkifiedText
{
    /**
     * Escape the entire text and link HTTP(S) URLs without changing whitespace.
     *
     * @param string|null $text  Untrusted message text
     * @param string      $class Additional CSS classes for links
     *
     * @return HtmlString Escaped text with safe link markup
     */
    public function toHtml(?string $text, string $class = ''): HtmlString
    {
        $text ??= '';
        $html = e($text);
        preg_match_all('~https?://[^\s<>"\']+~u', $text, $matches, PREG_OFFSET_CAPTURE);

        // Find URLs in the original text so escaped quotes cannot become URL content.
        // Replace from the end to keep offsets in the escaped text stable.
        foreach (array_reverse($matches[0]) as [$match, $offset]) {
            $url = preg_replace('/[.,;:!?)»]+$/u', '', $match) ?? $match;
            $escapedUrl = e($url);
            $link = '<a href="' . $escapedUrl . '" target="_blank" rel="noopener noreferrer nofollow" class="'
                . e(trim('underline break-all ' . $class)) . '">' . $escapedUrl . '</a>';
            $escapedOffset = strlen(e(substr($text, 0, $offset)));
            $html = substr_replace($html, $link, $escapedOffset, strlen($escapedUrl));
        }

        return new HtmlString($html);
    }
}
