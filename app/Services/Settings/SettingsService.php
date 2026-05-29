<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Unified settings access layer.
 *
 * Reading priority: DB row → config()/env default → null.
 *
 * Secret keys (is_secret = true in SettingKeyRegistry) are encrypted with
 * Laravel's Crypt facade before being written to the DB and decrypted
 * transparently on every get(). Encryption lives here, NOT in the model,
 * to avoid the attribute-fill ordering problem with dynamic Eloquent casts.
 *
 * Type coercion uses the DB row's stored `type` column when a row exists,
 * otherwise falls back to the SettingKeyRegistry declaration.
 *
 * Cache: each key is stored under "settings.{key}:{type}" forever and
 * invalidated on set() / forget(). The sentinel CACHE_NULL is cached when
 * there is no DB row (to avoid repeated DB hits on hot paths).
 */
class SettingsService
{
    private const CACHE_PREFIX = 'settings.';

    /**
     * Sentinel stored in the cache when there is no DB row for a key.
     * This lets us distinguish "not in cache" from "DB row is absent".
     */
    private const CACHE_NULL = '__settings_null__';

    /**
     * Retrieve a setting value.
     *
     * Priority: DB → config()/env default → null.
     * The returned value is coerced to the type declared in SettingKeyRegistry
     * (or the type stored in the DB row if available).
     *
     * @param mixed $default Override the registry's config() fallback with a caller-supplied default.
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheEntry = Cache::get($this->cacheKey($key));

        if ($cacheEntry !== null) {
            // Sentinel means we previously confirmed there is no DB row.
            if ($cacheEntry === self::CACHE_NULL) {
                return $this->resolveDefault($key, $default);
            }

            // Cache entry is stored as "type:value" to preserve the type.
            [$type, $plain] = $this->unpackCacheEntry($cacheEntry);

            return $this->coerceByType($plain, $type);
        }

        $setting = Setting::where('key', $key)->first();

        if ($setting !== null) {
            // Decrypt if secret, then cache as "type:value".
            $plain = $this->decryptIfSecret($key, $setting->value);
            Cache::forever($this->cacheKey($key), $this->packCacheEntry($setting->type, $plain));

            return $this->coerceByType($plain, $setting->type);
        }

        // No DB row — cache the sentinel so we skip DB on next read.
        Cache::forever($this->cacheKey($key), self::CACHE_NULL);

        return $this->resolveDefault($key, $default);
    }

    /**
     * Persist a setting value to the DB and invalidate the cache entry.
     *
     * The value is encoded to a string representation for storage.
     * Secret keys are encrypted with Crypt::encrypt() before writing.
     *
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        $meta = SettingKeyRegistry::meta($key);
        $raw = $this->encode($value, $meta['type']);

        if ($meta['is_secret']) {
            $raw = Crypt::encrypt($raw);
        }

        $setting = Setting::firstOrNew(['key' => $key]);
        $setting->type = $meta['type'];
        $setting->is_secret = $meta['is_secret'];
        $setting->value = $raw;
        $setting->save();

        Cache::forget($this->cacheKey($key));
    }

    /**
     * Determine whether a DB row exists for the given key.
     */
    public function has(string $key): bool
    {
        return Setting::where('key', $key)->exists();
    }

    /**
     * Delete a DB row for the given key and invalidate the cache entry.
     */
    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        Cache::forget($this->cacheKey($key));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the fallback value: caller-supplied default → registry config() path → null.
     *
     * @return mixed
     */
    private function resolveDefault(string $key, mixed $callerDefault): mixed
    {
        if ($callerDefault !== null) {
            return $callerDefault;
        }

        $meta = SettingKeyRegistry::meta($key);

        if ($meta['config'] !== null) {
            return config($meta['config']);
        }

        return null;
    }

    /**
     * Decrypt the stored value when the key is marked as a secret.
     */
    private function decryptIfSecret(string $key, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (SettingKeyRegistry::meta($key)['is_secret']) {
            return Crypt::decrypt($raw);
        }

        return $raw;
    }

    /**
     * Coerce a raw string to a PHP type.
     *
     * @return mixed
     */
    private function coerceByType(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * Encode a PHP value to a string for DB storage.
     *
     * @param mixed $value
     */
    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Pack a type+value pair into a single cache string.
     * Format: "{type}\x00{value}"
     */
    private function packCacheEntry(string $type, ?string $value): string
    {
        return $type . "\x00" . ($value ?? '');
    }

    /**
     * Unpack a cache entry created by packCacheEntry().
     *
     * @return array{string, string|null}
     */
    private function unpackCacheEntry(string $entry): array
    {
        $pos = strpos($entry, "\x00");

        if ($pos === false) {
            // Fallback: treat as plain string (legacy / corrupted entry).
            return ['string', $entry];
        }

        return [substr($entry, 0, $pos), substr($entry, $pos + 1)];
    }

    /**
     * Return the cache key for a setting key.
     */
    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX . $key;
    }
}
