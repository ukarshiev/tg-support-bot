<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\BatchTranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;

class FakeTranslationProvider implements BatchTranslationProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        return TranslationResult::success(
            '[' . $request->targetLocale . '] ' . $request->text,
            $this->key()
        );
    }

    public function translateBatch(TranslationRequest $request, array $texts): array
    {
        return array_map(
            fn (string $text): TranslationResult => TranslationResult::success(
                '[' . $request->targetLocale . '] ' . $text,
                $this->key(),
            ),
            $texts,
        );
    }
}
