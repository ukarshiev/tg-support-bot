<?php

namespace Tests\Unit\Modules\Ai\Services;

use App\Modules\Ai\Services\RussianOperatorTextService;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Services\TranslationService;
use RuntimeException;
use Tests\TestCase;

class RussianOperatorTextServiceTest extends TestCase
{
    public function test_keeps_russian_text_without_translation(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->never())->method('translate');

        $service = new RussianOperatorTextService($translation);

        $this->assertSame('Привет! Чем могу помочь?', $service->normalize('Привет! Чем могу помочь?'));
    }

    public function test_translates_foreign_provider_response_to_russian(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->once())
            ->method('translate')
            ->with($this->callback(fn (TranslationRequest $request): bool => $request->sourceLocale === 'auto'
                && $request->targetLocale === 'ru'
                && $request->purpose === 'ai_operator_source'))
            ->willReturn(TranslationResult::success('Русский ответ', 'fake'));

        $service = new RussianOperatorTextService($translation);

        $this->assertSame('Русский ответ', $service->normalize('Réponse française'));
    }

    public function test_rejects_foreign_text_when_russian_translation_failed(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('translate')->willReturn(TranslationResult::failure('provider_error', 'failed'));

        $this->expectException(RuntimeException::class);

        (new RussianOperatorTextService($translation))->normalize('Réponse française');
    }
}
