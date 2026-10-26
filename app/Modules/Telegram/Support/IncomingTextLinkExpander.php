<?php

namespace App\Modules\Telegram\Support;

/** Preserve hidden Telegram links when incoming content is stored as plain text. */
class IncomingTextLinkExpander
{
    /**
     * Expand non-overlapping text_link entities using Telegram's UTF-16 offsets.
     *
     * @param array<array-key, mixed>|null $entities Telegram text or caption entities
     *
     * @return string|null Original text with hidden URLs appended to their anchors
     */
    public static function expand(?string $text, ?array $entities): ?string
    {
        if ($text === null || $text === '' || empty($entities)) {
            return $text;
        }

        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);
        $links = array_filter($entities, static fn (mixed $entity): bool => is_array($entity)
            && ($entity['type'] ?? null) === 'text_link'
            && is_string($entity['url'] ?? null)
            && trim($entity['url']) !== ''
            && is_int($entity['offset'] ?? null)
            && is_int($entity['length'] ?? null)
            && $entity['offset'] >= 0
            && $entity['length'] > 0
            && $entity['offset'] <= $units
            && $entity['length'] <= $units - $entity['offset']);
        /** @var list<array{offset: int, length: int, url: string}> $links */
        $links = array_values($links);
        usort($links, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $result = '';
        $cursor = 0;
        foreach ($links as $entity) {
            $offset = $entity['offset'];
            $length = $entity['length'];
            if ($offset < $cursor) {
                continue;
            }

            $anchorUtf16 = substr($utf16, $offset * 2, $length * 2);
            if (!mb_check_encoding($anchorUtf16, 'UTF-16LE')) {
                continue;
            }

            $anchor = mb_convert_encoding($anchorUtf16, 'UTF-8', 'UTF-16LE');
            $result .= mb_convert_encoding(substr($utf16, $cursor * 2, ($offset - $cursor) * 2), 'UTF-8', 'UTF-16LE');
            $result .= str_contains($anchor, $entity['url']) ? $anchor : $anchor . ' (' . $entity['url'] . ')';
            $cursor = $offset + $length;
        }

        return $result . mb_convert_encoding(substr($utf16, $cursor * 2), 'UTF-8', 'UTF-16LE');
    }
}
