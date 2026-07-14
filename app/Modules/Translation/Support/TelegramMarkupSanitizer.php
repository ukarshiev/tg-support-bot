<?php

namespace App\Modules\Translation\Support;

class TelegramMarkupSanitizer
{
    /**
     * Безопасный plain text для отправки, если переводчик повредил HTML/Markdown.
     */
    public function toPlainText(string $text): string
    {
        $text = preg_replace('/<\s*\/\s*x\s*>/iu', '', $text) ?? $text;
        $text = preg_replace('/<\s*x\b[^>]*>/iu', '', $text) ?? $text;

        $plain = strip_tags($text);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($plain);
    }
}
