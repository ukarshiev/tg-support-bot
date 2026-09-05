<?php

namespace App\Modules\Telegram\Support;

/**
 * Applies Telegram text and caption limits before every outgoing API request.
 */
class TelegramOutgoingMessageLimiter
{
    private const MARKDOWN_CONTROL_EXPRESSION = '\\[(?:\\\\.|[^\\x5C\\x5D])*\\]\\((?:\\\\.|[^\\x29\\x5C])*\\)|\\\\.|```|__|\\|\\||[*_~`]';

    public const MARKDOWN_TOKEN_PATTERN = '#(' . self::MARKDOWN_CONTROL_EXPRESSION . ')#u';

    public const MARKDOWN_CONTROL_PATTERN = '#^(?:' . self::MARKDOWN_CONTROL_EXPRESSION . ')$#u';

    public const TEXT_LIMIT = 4096;

    public const CAPTION_LIMIT = 1024;

    public const CAPTION_TRUNCATION_MARKER = "\n\n… [подпись усечена; полный текст ниже]";

    /**
     * Build the ordered Telegram requests required to deliver the whole payload.
     *
     * @param string               $method
     * @param array<string, mixed> $data
     *
     * @return list<array{method: string, data: array<string, mixed>, primary: bool}>
     */
    public function prepare(string $method, array $data): array
    {
        if ($this->isTextMethod($method) && is_string($data['text'] ?? null)) {
            return $this->textRequests($method, $data);
        }

        if (is_string($data['caption'] ?? null)) {
            return $this->captionRequests($method, $data);
        }

        return [['method' => $method, 'data' => $data, 'primary' => true]];
    }

    /**
     * Split text without cutting HTML/MarkdownV2 control sequences.
     *
     * @param string      $text
     * @param string|null $parseMode
     * @param int         $limit
     *
     * @return list<string>
     */
    public function split(string $text, ?string $parseMode, int $limit = self::TEXT_LIMIT): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        return match (strtolower((string) $parseMode)) {
            'html' => $this->splitHtml($text, $limit),
            'markdownv2' => $this->splitMarkdownV2($text, $limit),
            default => $this->splitPlainText($text, $limit),
        };
    }

    private function isTextMethod(string $method): bool
    {
        return in_array($method, ['sendMessage', 'editMessageText'], true);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{method: string, data: array<string, mixed>, primary: bool}>
     */
    private function textRequests(string $method, array $data): array
    {
        $parts = $this->split($data['text'], $data['parse_mode'] ?? null);
        if (count($parts) === 1) {
            return [['method' => $method, 'data' => $data, 'primary' => true]];
        }

        $requests = [];
        $lastIndex = array_key_last($parts);
        foreach ($parts as $index => $part) {
            $partData = $method === 'editMessageText' && $index > 0
                ? $this->followUpMessageData($data)
                : $data;
            $partData['text'] = $part;

            if ($index !== $lastIndex) {
                unset($partData['reply_markup']);
            } elseif (array_key_exists('reply_markup', $data)) {
                $partData['reply_markup'] = $data['reply_markup'];
            }

            $requests[] = [
                'method' => $method === 'editMessageText' && $index === 0 ? $method : 'sendMessage',
                'data' => $partData,
                'primary' => $index === $lastIndex,
            ];
        }

        return $requests;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{method: string, data: array<string, mixed>, primary: bool}>
     */
    private function captionRequests(string $method, array $data): array
    {
        $caption = $data['caption'];
        if (mb_strlen($caption) <= self::CAPTION_LIMIT) {
            return [['method' => $method, 'data' => $data, 'primary' => true]];
        }

        $marker = $this->captionMarker($data['parse_mode'] ?? null);
        $captionBudget = self::CAPTION_LIMIT - mb_strlen($marker);
        $data['caption'] = rtrim($this->split($caption, $data['parse_mode'] ?? null, $captionBudget)[0]) . $marker;

        $requests = [['method' => $method, 'data' => $data, 'primary' => true]];
        $followUpData = $this->followUpMessageData($data);
        foreach ($this->split($caption, $data['parse_mode'] ?? null) as $part) {
            $requests[] = [
                'method' => 'sendMessage',
                'data' => $followUpData + ['text' => $part],
                'primary' => false,
            ];
        }

        return $requests;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function followUpMessageData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'business_connection_id',
            'chat_id',
            'message_thread_id',
            'parse_mode',
            'disable_notification',
            'protect_content',
            'allow_paid_broadcast',
            'message_effect_id',
            'link_preview_options',
        ]));
    }

    private function captionMarker(?string $parseMode): string
    {
        if (strtolower((string) $parseMode) === 'markdownv2') {
            return "\n\n… \\[подпись усечена; полный текст ниже\\]";
        }

        return self::CAPTION_TRUNCATION_MARKER;
    }

    /** @return list<string> */
    private function splitPlainText(string $text, int $limit): array
    {
        $units = preg_split('/(\R+|[^\S\r\n]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->packUnits($units, $limit);
    }

    /** @return list<string> */
    private function splitHtml(string $html, int $limit): array
    {
        $tokens = preg_split(
            '/(<\/?[a-zA-Z][a-zA-Z0-9_-]*\b[^>]*>|&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);)/u',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $units = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, '<') || str_starts_with($token, '&')) {
                $units[] = $token;
            } else {
                array_push($units, ...(preg_split('/(\R+|[^\S\r\n]+)/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        $parts = [];
        $current = '';
        /** @var list<array{name: string, opening: string}> $openTags */
        $openTags = [];

        foreach ($units as $unit) {
            $nextTags = $this->htmlTagsAfter($openTags, $unit);
            if ($current !== '' && mb_strlen($current . $unit . $this->htmlClosings($nextTags)) > $limit) {
                $parts[] = $current . $this->htmlClosings($openTags);
                $current = $this->htmlOpenings($openTags);
            }

            if (mb_strlen($current . $unit . $this->htmlClosings($nextTags)) > $limit && !str_starts_with($unit, '<') && !str_starts_with($unit, '&')) {
                foreach (preg_split('//u', $unit, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if (mb_strlen($current . $character . $this->htmlClosings($openTags)) > $limit) {
                        $parts[] = $current . $this->htmlClosings($openTags);
                        $current = $this->htmlOpenings($openTags);
                    }
                    $current .= $character;
                }
            } else {
                $current .= $unit;
            }

            $openTags = $nextTags;
        }

        if ($current !== '') {
            $parts[] = $current . $this->htmlClosings($openTags);
        }

        return $parts;
    }

    /** @return list<string> */
    private function splitMarkdownV2(string $markdown, int $limit): array
    {
        $tokens = preg_split(
            self::MARKDOWN_TOKEN_PATTERN,
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $units = [];
        foreach ($tokens as $token) {
            if ($this->isMarkdownControl($token)) {
                $units[] = $token;
            } else {
                array_push($units, ...(preg_split('/(\R+|[^\S\r\n]+)/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        $parts = [];
        $current = '';
        /** @var list<string> $open */
        $open = [];
        foreach ($units as $unit) {
            $nextOpen = $this->markdownOpenAfter($open, $unit);
            if ($current !== '' && mb_strlen($current . $unit . $this->markdownClosings($nextOpen)) > $limit) {
                $parts[] = $current . $this->markdownClosings($open);
                $current = implode('', $open);
            }

            if (mb_strlen($current . $unit . $this->markdownClosings($nextOpen)) > $limit && !$this->isMarkdownControl($unit)) {
                foreach (preg_split('//u', $unit, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if (mb_strlen($current . $character . $this->markdownClosings($open)) > $limit) {
                        $parts[] = $current . $this->markdownClosings($open);
                        $current = implode('', $open);
                    }
                    $current .= $character;
                }
            } else {
                $current .= $unit;
            }

            $open = $nextOpen;
        }

        if ($current !== '') {
            $parts[] = $current . $this->markdownClosings($open);
        }

        return $parts;
    }

    /** @param list<string> $units @return list<string> */
    private function packUnits(array $units, int $limit): array
    {
        $parts = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && mb_strlen($current . $unit) > $limit) {
                $parts[] = $current;
                $current = '';
            }

            while (mb_strlen($unit) > $limit) {
                $available = $current === '' ? $limit : $limit - mb_strlen($current);
                $current .= mb_substr($unit, 0, $available);
                $parts[] = $current;
                $current = '';
                $unit = mb_substr($unit, $available);
            }
            $current .= $unit;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /** @param list<array{name: string, opening: string}> $openTags @return list<array{name: string, opening: string}> */
    private function htmlTagsAfter(array $openTags, string $token): array
    {
        if (preg_match('/^<([a-zA-Z][a-zA-Z0-9_-]*)\b[^>]*>$/', $token, $matches) === 1 && !str_ends_with($token, '/>')) {
            $openTags[] = ['name' => strtolower($matches[1]), 'opening' => $token];
        } elseif (preg_match('/^<\/([a-zA-Z][a-zA-Z0-9_-]*)>$/', $token, $matches) === 1) {
            $name = strtolower($matches[1]);
            if ($openTags !== [] && $openTags[array_key_last($openTags)]['name'] === $name) {
                array_pop($openTags);
            }
        }

        return $openTags;
    }

    /** @param list<array{name: string, opening: string}> $openTags */
    private function htmlOpenings(array $openTags): string
    {
        return implode('', array_column($openTags, 'opening'));
    }

    /** @param list<array{name: string, opening: string}> $openTags */
    private function htmlClosings(array $openTags): string
    {
        return implode('', array_map(
            static fn (array $tag): string => '</' . $tag['name'] . '>',
            array_reverse($openTags),
        ));
    }

    private function isMarkdownControl(string $token): bool
    {
        return preg_match(self::MARKDOWN_CONTROL_PATTERN, $token) === 1;
    }

    /** @param list<string> $open @return list<string> */
    private function markdownOpenAfter(array $open, string $token): array
    {
        if (!$this->isMarkdownControl($token) || str_starts_with($token, '\\') || str_starts_with($token, '[')) {
            return $open;
        }

        $delimiter = str_starts_with($token, '```') ? '```' : $token;
        if ($open !== [] && $this->markdownDelimiter($open[array_key_last($open)]) === $delimiter) {
            array_pop($open);
        } else {
            $open[] = $token;
        }

        return $open;
    }

    private function markdownDelimiter(string $opening): string
    {
        return str_starts_with($opening, '```') ? '```' : $opening;
    }

    /** @param list<string> $open */
    private function markdownClosings(array $open): string
    {
        return implode('', array_map(
            fn (string $opening): string => $this->markdownDelimiter($opening),
            array_reverse($open),
        ));
    }
}
