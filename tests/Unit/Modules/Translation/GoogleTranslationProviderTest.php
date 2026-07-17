<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Translation;

use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Providers\GoogleTranslationProvider;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTranslationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_source_locale_is_not_sent_to_google(): void
    {
        app(SettingsService::class)->set('translation.google_api_key', 'test-key');

        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Привет'],
                    ],
                ],
            ]),
        ]);

        $result = app(GoogleTranslationProvider::class)->translate(new TranslationRequest(
            sourceLocale: 'auto',
            targetLocale: 'ru',
            text: 'Hello',
            purpose: 'test',
        ));

        $this->assertTrue($result->success);
        $this->assertSame('Привет', $result->text);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $payload['target'] === 'ru'
                && ! array_key_exists('source', $payload);
        });
    }
}
