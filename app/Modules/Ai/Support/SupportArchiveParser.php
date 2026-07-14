<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

class SupportArchiveParser
{
    private const OPERATOR_NAMES = ['Умид Каршиев', 'Ne0soul'];

    private const CLIENT_MARKERS = ['Relaxa.Club Support Bot', 'Relaxa - Connector Chat'];

    /**
     * @return array<int, array{source_file: string, telegram_message_id: string, message_datetime: string|null, sender_name: string, sender_role: string, text: string, is_noise: bool}>
     */
    public function parseDirectory(string $directory): array
    {
        $files = collect(File::glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'messages*.html'))
            ->sortBy(fn (string $path): string => basename($path))
            ->values();

        $messages = [];
        foreach ($files as $file) {
            foreach ($this->parseFile($file) as $message) {
                $messages[] = $message;
            }
        }

        usort($messages, function (array $a, array $b): int {
            return strcmp((string) ($a['message_datetime'] ?? ''), (string) ($b['message_datetime'] ?? ''))
                ?: strcmp($a['source_file'] . $a['telegram_message_id'], $b['source_file'] . $b['telegram_message_id']);
        });

        return $messages;
    }

    /**
     * @return array<int, array{source_file: string, telegram_message_id: string, message_datetime: string|null, sender_name: string, sender_role: string, text: string, is_noise: bool}>
     */
    public function parseFile(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $html = (string) File::get($path);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' message ') and contains(concat(' ', normalize-space(@class), ' '), ' default ')]");
        if ($nodes === false) {
            return [];
        }

        $rows = [];
        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $text = $this->extractText($xpath, $node);
            if ($text === '') {
                continue;
            }

            $sender = $this->extractSender($xpath, $node);
            $role = $this->detectRole($sender);
            $messageId = (string) $node->getAttribute('id');

            $rows[] = [
                'source_file' => basename($path),
                'telegram_message_id' => $messageId !== '' ? $messageId : sha1($text),
                'message_datetime' => $this->extractDate($xpath, $node),
                'sender_name' => $sender !== '' ? $sender : 'unknown',
                'sender_role' => $role,
                'text' => $text,
                'is_noise' => $this->isNoise($text, $role),
            ];
        }

        return $rows;
    }

    private function extractSender(\DOMXPath $xpath, \DOMElement $node): string
    {
        $names = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' from_name ')]", $node);
        if ($names === false || $names->length === 0) {
            return '';
        }

        $raw = $this->normalize($names->item(0)->textContent ?? '');
        $raw = preg_replace('/\s+\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2}$/u', '', $raw) ?? $raw;

        return trim($raw);
    }

    private function extractText(\DOMXPath $xpath, \DOMElement $node): string
    {
        $texts = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' text ')]", $node);
        if ($texts === false || $texts->length === 0) {
            return '';
        }

        $parts = [];
        foreach ($texts as $textNode) {
            $value = $this->normalize($textNode->textContent ?? '');
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return trim(implode("\n", array_unique($parts)));
    }

    private function extractDate(\DOMXPath $xpath, \DOMElement $node): ?string
    {
        $dates = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' date ') and contains(concat(' ', normalize-space(@class), ' '), ' details ')]", $node);
        if ($dates === false || $dates->length === 0) {
            return null;
        }

        $titleNode = $dates->item(0)->attributes?->getNamedItem('title');
        $title = trim($titleNode instanceof \DOMNode ? (string) $titleNode->nodeValue : '');
        if ($title === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('d.m.Y H:i:s \U\T\CO', str_replace('UTC+03:00', 'UTC+0300', $title))?->toDateTimeString();
        } catch (\Throwable) {
            try {
                return CarbonImmutable::parse($title)->toDateTimeString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function detectRole(string $sender): string
    {
        foreach (self::OPERATOR_NAMES as $operatorName) {
            if ($sender === $operatorName) {
                return 'operator';
            }
        }

        foreach (self::CLIENT_MARKERS as $marker) {
            if (str_contains($sender, $marker)) {
                return 'client';
            }
        }

        return 'client';
    }

    private function isNoise(string $text, string $role): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return true;
        }

        $noise = [
            '/close',
            '/start',
            'bot was blocked by the user.',
            'bot was blocked by the user',
            'in reply to a message in another chat',
        ];

        if (in_array($normalized, $noise, true)) {
            return true;
        }

        if ($role === 'operator' && str_starts_with($normalized, '/')) {
            return true;
        }

        return mb_strlen($normalized) < 2;
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\h+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\R{2,}/u', "\n", $value) ?? $value;

        return trim($value);
    }
}
