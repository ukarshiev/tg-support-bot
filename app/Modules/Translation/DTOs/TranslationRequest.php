<?php

namespace App\Modules\Translation\DTOs;

class TranslationRequest
{
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly string $text,
        public readonly string $purpose = 'generic',
        public readonly bool $allowExternal = true,
    ) {
    }
}
