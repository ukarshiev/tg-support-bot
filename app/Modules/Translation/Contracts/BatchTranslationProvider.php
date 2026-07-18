<?php

namespace App\Modules\Translation\Contracts;

use App\Modules\Translation\DTOs\TranslationRequest;

interface BatchTranslationProvider extends TranslationProvider
{
    /**
     * @param array<int, string> $texts
     *
     * @return array<int, \App\Modules\Translation\DTOs\TranslationResult>
     */
    public function translateBatch(TranslationRequest $request, array $texts): array;
}
