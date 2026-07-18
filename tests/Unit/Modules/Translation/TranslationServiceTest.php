<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Translation;

use App\Models\TranslationCacheEntry;
use App\Models\TranslationUsageLog;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_provider_translates_and_preserves_links_mentions_and_placeholders(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $result = app(TranslationService::class)->translate(new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: 'en',
            text: 'Привет, {first_name}! Напишите @relaxa_support или откройте https://example.com/pay. И переменная {{connector}}',
            purpose: 'test'
        ));

        $this->assertTrue($result->success);
        $this->assertSame('fake', $result->provider);
        $this->assertStringContainsString('[en]', (string) $result->text);
        $this->assertStringContainsString('{first_name}', (string) $result->text);
        $this->assertStringContainsString('@relaxa_support', (string) $result->text);
        $this->assertStringContainsString('https://example.com/pay.', (string) $result->text);
        $this->assertStringContainsString('{{connector}}', (string) $result->text);
        $this->assertStringNotContainsString('__TG_SUPPORT_PH_', (string) $result->text);
        $this->assertStringNotContainsString('<x id=', (string) $result->text);
        $this->assertDatabaseHas('translation_usage_logs', ['provider' => 'fake', 'success' => true]);
        $this->assertDatabaseHas('translation_cache_entries', ['provider' => 'fake', 'status' => 'ready']);
    }

    public function test_cache_is_used_on_repeated_translation_without_second_usage_log(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $request = new TranslationRequest('ru', 'en', 'Добрый день!', 'test');

        $first = app(TranslationService::class)->translate($request);
        $second = app(TranslationService::class)->translate($request);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertTrue($second->fromCache);
        $this->assertSame(1, TranslationUsageLog::query()->where('provider', 'fake')->count());
        $this->assertSame(1, TranslationCacheEntry::query()->count());
    }

    public function test_same_locale_returns_source_text_without_provider_call(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $result = app(TranslationService::class)->translate(new TranslationRequest('ru', 'ru', 'Текст', 'test'));

        $this->assertTrue($result->success);
        $this->assertSame('Текст', $result->text);
        $this->assertSame('same_locale', $result->provider);
        $this->assertSame(0, TranslationUsageLog::query()->count());
    }

    public function test_external_providers_are_skipped_when_external_translation_is_disabled(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['yandex', 'fake']);
        app(SettingsService::class)->set('translation.allow_external', false);

        $result = app(TranslationService::class)->translate(new TranslationRequest('ru', 'en', 'Добрый день!', 'test'));

        $this->assertTrue($result->success);
        $this->assertSame('fake', $result->provider);
        $this->assertDatabaseHas('translation_usage_logs', [
            'provider' => 'yandex',
            'success' => false,
            'error_code' => 'external_disabled',
        ]);
    }

    public function test_translate_many_uses_cache_and_translates_only_missing_texts(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        TranslationCacheEntry::create([
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_hash' => TranslationService::sourceHash('Hazır'),
            'source_text' => 'Hazır',
            'translated_text' => 'Готово',
            'provider' => 'fake',
            'status' => 'ready',
        ]);

        $results = app(TranslationService::class)->translateMany([
            new TranslationRequest('tr', 'ru', 'Hazır', 'chat_history'),
            new TranslationRequest('tr', 'ru', 'Merhaba', 'chat_history'),
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->fromCache);
        $this->assertSame('Готово', $results[0]->text);
        $this->assertSame('[ru] Merhaba', $results[1]->text);
        $this->assertSame(1, TranslationUsageLog::query()->where('provider', 'fake')->count());
    }

    public function test_translate_many_splits_large_batch_by_safe_limits(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $requests = [];
        for ($i = 1; $i <= 26; $i++) {
            $requests[] = new TranslationRequest('tr', 'ru', 'Metin ' . $i, 'chat_history');
        }

        $results = app(TranslationService::class)->translateMany($requests);

        $this->assertCount(26, $results);
        $this->assertSame('[ru] Metin 1', $results[0]->text);
        $this->assertSame('[ru] Metin 26', $results[25]->text);
        $this->assertSame(26, TranslationUsageLog::query()->where('provider', 'fake')->count());
        $this->assertSame(26, TranslationCacheEntry::query()->count());
    }
}
