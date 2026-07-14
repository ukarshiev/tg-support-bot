<?php

namespace App\Modules\Translation\Providers;

use App\Modules\Translation\Contracts\TranslationProvider;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;

class FakeTranslationProvider implements TranslationProvider
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
}
