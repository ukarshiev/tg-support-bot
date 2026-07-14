<?php

namespace App\Modules\Translation\DTOs;

class TranslationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $text = null,
        public readonly ?string $provider = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $fromCache = false,
    ) {
    }

    public static function success(string $text, string $provider, bool $fromCache = false): self
    {
        return new self(true, $text, $provider, null, null, $fromCache);
    }

    public static function failure(string $code, string $message, ?string $provider = null): self
    {
        return new self(false, null, $provider, $code, $message);
    }
}
