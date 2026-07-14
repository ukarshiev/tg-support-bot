<?php

namespace App\Modules\Translation\Contracts;

use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;

interface TranslationProvider
{
    public function key(): string;

    public function isExternal(): bool;

    public function translate(TranslationRequest $request): TranslationResult;
}
