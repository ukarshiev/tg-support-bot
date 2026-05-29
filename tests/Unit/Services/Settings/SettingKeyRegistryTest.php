<?php

namespace Tests\Unit\Services\Settings;

use App\Services\Settings\SettingKeyRegistry;
use Tests\TestCase;

class SettingKeyRegistryTest extends TestCase
{
    // ── meta() for known keys ────────────────────────────────────────────────

    public function test_meta_returns_metadata_for_a_known_key(): void
    {
        $meta = SettingKeyRegistry::meta('app.manager_interface');

        $this->assertSame('string', $meta['type']);
        $this->assertSame('app.manager_interface', $meta['config']);
        $this->assertFalse($meta['is_secret']);
    }

    public function test_meta_marks_secret_keys_as_secret(): void
    {
        $this->assertTrue(SettingKeyRegistry::meta('telegram.token')['is_secret']);
        $this->assertTrue(SettingKeyRegistry::meta('vk.token')['is_secret']);
        $this->assertTrue(SettingKeyRegistry::meta('ai.openai_api_key')['is_secret']);
        $this->assertTrue(SettingKeyRegistry::meta('ai.gigachat_client_secret')['is_secret']);
    }

    public function test_meta_does_not_mark_non_secret_keys_as_secret(): void
    {
        $this->assertFalse(SettingKeyRegistry::meta('telegram.group_id')['is_secret']);
        $this->assertFalse(SettingKeyRegistry::meta('ai.enabled')['is_secret']);
        $this->assertFalse(SettingKeyRegistry::meta('ai.default_provider')['is_secret']);
    }

    public function test_meta_carries_the_typed_keys(): void
    {
        $this->assertSame('bool', SettingKeyRegistry::meta('ai.enabled')['type']);
        $this->assertSame('int', SettingKeyRegistry::meta('ai.max_context_tokens')['type']);
        $this->assertSame('int', SettingKeyRegistry::meta('telegram.bot_id')['type']);
        $this->assertSame('string', SettingKeyRegistry::meta('max.token')['type']);
    }

    public function test_meta_points_each_key_to_its_config_fallback_path(): void
    {
        $this->assertSame(
            'traffic_source.settings.telegram.token',
            SettingKeyRegistry::meta('telegram.token')['config']
        );
        $this->assertSame(
            'ai.providers.openai.api_key',
            SettingKeyRegistry::meta('ai.openai_api_key')['config']
        );
    }

    // ── meta() for unknown keys ──────────────────────────────────────────────

    public function test_meta_returns_safe_defaults_for_an_unknown_key(): void
    {
        $meta = SettingKeyRegistry::meta('totally.unknown.key');

        $this->assertSame('string', $meta['type']);
        $this->assertNull($meta['config']);
        $this->assertFalse($meta['is_secret']);
    }

    // ── keys() ───────────────────────────────────────────────────────────────

    public function test_keys_returns_all_registered_keys(): void
    {
        $keys = SettingKeyRegistry::keys();

        $this->assertContains('app.manager_interface', $keys);
        $this->assertContains('telegram.token', $keys);
        $this->assertContains('vk.confirm_code', $keys);
        $this->assertContains('max.secret_key', $keys);
        $this->assertContains('ai.default_provider', $keys);
    }

    public function test_keys_returns_a_list_of_unique_strings(): void
    {
        $keys = SettingKeyRegistry::keys();

        $this->assertSame(array_values($keys), $keys);
        $this->assertSame(array_unique($keys), $keys);
        $this->assertContainsOnly('string', $keys);
    }

    // ── registered() ──────────────────────────────────────────────────────────

    public function test_registered_is_true_for_a_known_key(): void
    {
        $this->assertTrue(SettingKeyRegistry::registered('app.manager_interface'));
    }

    public function test_registered_is_false_for_an_unknown_key(): void
    {
        $this->assertFalse(SettingKeyRegistry::registered('totally.unknown.key'));
    }

    public function test_every_registered_key_has_consistent_metadata_shape(): void
    {
        foreach (SettingKeyRegistry::keys() as $key) {
            $meta = SettingKeyRegistry::meta($key);

            $this->assertArrayHasKey('type', $meta);
            $this->assertArrayHasKey('config', $meta);
            $this->assertArrayHasKey('is_secret', $meta);
            $this->assertContains($meta['type'], ['string', 'bool', 'int', 'json']);
            $this->assertTrue(SettingKeyRegistry::registered($key));
        }
    }
}
