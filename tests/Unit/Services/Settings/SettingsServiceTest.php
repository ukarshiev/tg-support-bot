<?php

namespace Tests\Unit\Services\Settings;

use App\Models\Setting;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Wipe all rows seeded by TestCase::setUp() so each test starts with
        // a clean settings table under full test control.
        Setting::truncate();
        Cache::flush();
        $this->service = new SettingsService();
    }

    // ── Fallback to config() ─────────────────────────────────────────────────

    public function test_get_returns_null_when_no_db_row_and_no_config_fallback(): void
    {
        // 'app.bot_name' has config => null — returns null when no DB row.
        $value = $this->service->get('app.bot_name');

        $this->assertNull($value);
    }

    public function test_get_returns_null_for_unknown_key_with_no_db_row(): void
    {
        $value = $this->service->get('unknown.key.xyz');

        $this->assertNull($value);
    }

    public function test_get_returns_caller_default_when_no_db_row_and_no_config(): void
    {
        $value = $this->service->get('unknown.key.xyz', 'my-default');

        $this->assertSame('my-default', $value);
    }

    // ── DB override ──────────────────────────────────────────────────────────

    public function test_get_returns_db_value_when_row_exists(): void
    {
        Setting::create([
            'key' => 'app.bot_name',
            'value' => 'My Support Bot',
            'type' => 'string',
            'is_secret' => false,
        ]);

        $value = $this->service->get('app.bot_name');

        $this->assertSame('My Support Bot', $value);
    }

    // ── set() + cache invalidation ───────────────────────────────────────────

    public function test_set_creates_db_row_and_get_returns_new_value(): void
    {
        $this->service->set('app.bot_name', 'Support Bot');

        $value = $this->service->get('app.bot_name');

        $this->assertSame('Support Bot', $value);
    }

    public function test_set_invalidates_cache_so_next_get_reads_from_db(): void
    {
        // Prime the cache with the old value by calling get() first.
        Setting::create([
            'key' => 'app.bot_name',
            'value' => 'Old Name',
            'type' => 'string',
            'is_secret' => false,
        ]);
        $this->service->get('app.bot_name'); // warms the cache

        // Now overwrite via set().
        $this->service->set('app.bot_name', 'New Name');

        // After set() the cache entry must be gone; next get() reads from DB.
        $value = $this->service->get('app.bot_name');
        $this->assertSame('New Name', $value);
    }

    public function test_set_updates_existing_db_row(): void
    {
        $this->service->set('app.bot_name', 'First Name');
        $this->service->set('app.bot_name', 'Second Name');

        $count = Setting::where('key', 'app.bot_name')->count();
        $this->assertSame(1, $count);

        $value = $this->service->get('app.bot_name');
        $this->assertSame('Second Name', $value);
    }

    // ── Secret encryption ────────────────────────────────────────────────────

    public function test_set_stores_secret_value_encrypted_in_db(): void
    {
        $this->service->set('telegram.token', 'super-secret-token');

        $rawRow = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'telegram.token')
            ->value('value');

        $this->assertNotSame('super-secret-token', $rawRow, 'Secret value must be encrypted in DB');
        $this->assertNotEmpty($rawRow);
    }

    public function test_get_returns_decrypted_value_for_secret_key(): void
    {
        $this->service->set('telegram.token', 'super-secret-token');
        Cache::flush(); // force a fresh DB read

        $value = $this->service->get('telegram.token');

        $this->assertSame('super-secret-token', $value);
    }

    public function test_get_returns_null_when_secret_cannot_be_decrypted(): void
    {
        // Simulate a secret encrypted with a previous APP_KEY: store ciphertext
        // that the current key cannot decrypt (garbage that is not valid payload).
        Setting::create([
            'key' => 'telegram.token',
            'value' => 'eyJpdiI6Im5vdC1hLXZhbGlkLW1hYyIsInZhbHVlIjoieHh4IiwibWFjIjoiMDAwMCJ9',
            'type' => 'string',
            'is_secret' => true,
        ]);
        Cache::flush();

        // Must NOT throw DecryptException — undecryptable secret reads as null.
        $value = $this->service->get('telegram.token');

        $this->assertNull($value);
    }

    public function test_non_secret_key_is_stored_as_plain_text(): void
    {
        $this->service->set('app.bot_name', 'My Bot');

        $rawRow = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'app.bot_name')
            ->value('value');

        $this->assertSame('My Bot', $rawRow, 'Non-secret value must be stored as plain text');
    }

    // ── Type coercion ────────────────────────────────────────────────────────

    public function test_get_coerces_bool_type_to_php_bool(): void
    {
        Setting::create(['key' => 'ai.enabled', 'value' => '1', 'type' => 'bool', 'is_secret' => false]);

        $value = $this->service->get('ai.enabled');

        $this->assertIsBool($value);
        $this->assertTrue($value);
    }

    public function test_get_coerces_bool_false_correctly(): void
    {
        Setting::create(['key' => 'ai.enabled', 'value' => '0', 'type' => 'bool', 'is_secret' => false]);

        $value = $this->service->get('ai.enabled');

        $this->assertIsBool($value);
        $this->assertFalse($value);
    }

    public function test_get_coerces_int_type_to_php_int(): void
    {
        Setting::create(['key' => 'ai.max_context_tokens', 'value' => '5000', 'type' => 'int', 'is_secret' => false]);

        $value = $this->service->get('ai.max_context_tokens');

        $this->assertIsInt($value);
        $this->assertSame(5000, $value);
    }

    public function test_get_coerces_json_type_to_php_array(): void
    {
        // Use the DB row's `type` column directly — unregistered key, but type='json' in DB.
        Setting::create([
            'key' => 'test.json_setting',
            'value' => '{"foo":"bar","num":42}',
            'type' => 'json',
            'is_secret' => false,
        ]);

        $value = $this->service->get('test.json_setting');

        $this->assertIsArray($value);
        $this->assertSame('bar', $value['foo']);
        $this->assertSame(42, $value['num']);
    }

    public function test_set_encodes_bool_value_to_string_for_storage(): void
    {
        $this->service->set('ai.enabled', true);

        $rawRow = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'ai.enabled')
            ->value('value');

        $this->assertSame('1', $rawRow);
    }

    public function test_set_encodes_int_value_to_string_for_storage(): void
    {
        $this->service->set('ai.max_context_tokens', 9999);

        $rawRow = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'ai.max_context_tokens')
            ->value('value');

        $this->assertSame('9999', $rawRow);
    }

    // ── has() ────────────────────────────────────────────────────────────────

    public function test_has_returns_false_when_no_db_row(): void
    {
        $this->assertFalse($this->service->has('app.bot_name'));
    }

    public function test_has_returns_true_when_db_row_exists(): void
    {
        $this->service->set('app.bot_name', 'My Bot');

        $this->assertTrue($this->service->has('app.bot_name'));
    }

    // ── forget() ────────────────────────────────────────────────────────────

    public function test_forget_deletes_db_row(): void
    {
        $this->service->set('app.bot_name', 'My Bot');
        $this->service->forget('app.bot_name');

        $this->assertFalse($this->service->has('app.bot_name'));
    }

    public function test_forget_invalidates_cache_so_get_returns_null_when_no_config_fallback(): void
    {
        $this->service->set('app.bot_name', 'My Bot');
        $this->service->get('app.bot_name'); // warms cache

        $this->service->forget('app.bot_name');

        // After forget, get() must return null (no config fallback for app.bot_name).
        $value = $this->service->get('app.bot_name');
        $this->assertNull($value);
    }

    public function test_forget_does_not_throw_when_key_does_not_exist(): void
    {
        // Must not throw.
        $this->service->forget('non.existent.key');
        $this->assertFalse($this->service->has('non.existent.key'));
    }

    // ── Caching ──────────────────────────────────────────────────────────────

    public function test_get_serves_value_from_cache_on_second_call(): void
    {
        Setting::create([
            'key' => 'app.bot_name',
            'value' => 'Cached Bot',
            'type' => 'string',
            'is_secret' => false,
        ]);

        $first = $this->service->get('app.bot_name'); // warms cache
        // Delete the DB row to prove the next call uses cache, not DB.
        Setting::where('key', 'app.bot_name')->delete();

        $second = $this->service->get('app.bot_name');

        $this->assertSame('Cached Bot', $first);
        $this->assertSame('Cached Bot', $second);
    }

    public function test_get_caches_null_sentinel_when_no_db_row(): void
    {
        // First call: no DB row — should cache the sentinel (null).
        $first = $this->service->get('app.bot_name');

        // Insert a DB row now — a second get() must still return null
        // because the sentinel is cached and we haven't called set().
        Setting::create([
            'key' => 'app.bot_name',
            'value' => 'Late Insert',
            'type' => 'string',
            'is_secret' => false,
        ]);
        $second = $this->service->get('app.bot_name');

        $this->assertNull($first);
        $this->assertNull($second);
    }
}
