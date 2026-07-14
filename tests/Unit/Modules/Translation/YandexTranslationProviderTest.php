<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Translation;

use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Providers\YandexTranslationProvider;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexTranslationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_source_locale_is_not_sent_to_yandex(): void
    {
        app(SettingsService::class)->set('translation.yandex_api_key', 'test-key');
        app(SettingsService::class)->set('translation.yandex_folder_id', 'test-folder');

        Http::fake([
            'translate.api.cloud.yandex.net/*' => Http::response([
                'translations' => [
                    ['text' => 'Привет'],
                ],
            ]),
        ]);

        $result = app(YandexTranslationProvider::class)->translate(new TranslationRequest(
            sourceLocale: 'auto',
            targetLocale: 'ru',
            text: 'Hello',
            purpose: 'test',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('Привет', $result->text);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $payload['targetLanguageCode'] === 'ru'
                && ! array_key_exists('sourceLanguageCode', $payload);
        });
    }
}
