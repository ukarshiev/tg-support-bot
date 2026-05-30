<?php

namespace App\Services\Settings;

/**
 * Registry of all known application setting keys and their metadata.
 *
 * Each entry maps a dot-notation key to:
 *   - type        : PHP type for coercion ('string', 'bool', 'int', 'json')
 *   - config      : config() path used as the fallback when no DB row exists
 *   - is_secret   : whether the value must be stored encrypted in the DB
 *
 * Keys not listed here are still valid — unknown keys fall back to 'string'
 * type with no config fallback (they return null unless a DB row exists).
 *
 * To add a new key simply append an entry; no other file needs to change.
 */
class SettingKeyRegistry
{
    /**
     * @var array<string, array{type: string, config: string|null, is_secret: bool}>
     */
    private static array $keys = [
        // ── App ─────────────────────────────────────────────────────────────
        'app.manager_interface' => [
            'type' => 'string',
            'config' => 'app.manager_interface',
            'is_secret' => false,
        ],
        'app.bot_name' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'app.bot_description' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── Telegram (main bot) ──────────────────────────────────────────────
        'telegram.token' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram.token',
            'is_secret' => true,
        ],
        'telegram.secret_key' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram.secret_key',
            'is_secret' => true,
        ],
        'telegram.group_id' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram.group_id',
            'is_secret' => false,
        ],
        'telegram.bot_id' => [
            'type' => 'int',
            'config' => 'traffic_source.settings.telegram.bot_id',
            'is_secret' => false,
        ],
        'telegram.template_topic_name' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram.template_topic_name',
            'is_secret' => false,
        ],

        // ── Telegram AI bot ──────────────────────────────────────────────────
        'telegram_ai.token' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram_ai.token',
            'is_secret' => true,
        ],
        'telegram_ai.secret' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram_ai.secret',
            'is_secret' => true,
        ],
        'telegram_ai.id' => [
            'type' => 'int',
            'config' => 'traffic_source.settings.telegram_ai.id',
            'is_secret' => false,
        ],
        'telegram_ai.username' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.telegram_ai.username',
            'is_secret' => false,
        ],

        // ── VK ───────────────────────────────────────────────────────────────
        'vk.token' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.vk.token',
            'is_secret' => true,
        ],
        'vk.secret_key' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.vk.secret_key',
            'is_secret' => true,
        ],
        'vk.confirm_code' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.vk.confirm_code',
            'is_secret' => true,
        ],

        // ── Max ──────────────────────────────────────────────────────────────
        'max.token' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.max.token',
            'is_secret' => true,
        ],
        'max.secret_key' => [
            'type' => 'string',
            'config' => 'traffic_source.settings.max.secret_key',
            'is_secret' => true,
        ],

        // ── AI assistant ─────────────────────────────────────────────────────
        'ai.enabled' => [
            'type' => 'bool',
            'config' => 'ai.enabled',
            'is_secret' => false,
        ],
        'ai.auto_reply' => [
            'type' => 'bool',
            'config' => 'ai.auto_reply',
            'is_secret' => false,
        ],
        'ai.default_provider' => [
            'type' => 'string',
            'config' => 'ai.default_provider',
            'is_secret' => false,
        ],
        'ai.max_context_tokens' => [
            'type' => 'int',
            'config' => 'ai.max_context_tokens',
            'is_secret' => false,
        ],
        'ai.openai_api_key' => [
            'type' => 'string',
            'config' => 'ai.providers.openai.api_key',
            'is_secret' => true,
        ],
        'ai.openai_model' => [
            'type' => 'string',
            'config' => 'ai.providers.openai.model',
            'is_secret' => false,
        ],
        'ai.deepseek_client_id' => [
            'type' => 'string',
            'config' => 'ai.providers.deepseek.client_id',
            'is_secret' => false,
        ],
        'ai.deepseek_client_secret' => [
            'type' => 'string',
            'config' => 'ai.providers.deepseek.client_secret',
            'is_secret' => true,
        ],
        'ai.gigachat_client_id' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.client_id',
            'is_secret' => false,
        ],
        'ai.gigachat_client_secret' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.client_secret',
            'is_secret' => true,
        ],

        // ── AI system prompt ─────────────────────────────────────────────────
        'ai.system_prompt' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── OpenAI extended fields ────────────────────────────────────────────
        'ai.openai_base_url' => [
            'type' => 'string',
            'config' => 'ai.providers.openai.base_url',
            'is_secret' => false,
        ],
        'ai.openai_max_tokens' => [
            'type' => 'int',
            'config' => 'ai.providers.openai.max_tokens',
            'is_secret' => false,
        ],
        'ai.openai_temperature' => [
            'type' => 'string',
            'config' => 'ai.providers.openai.temperature',
            'is_secret' => false,
        ],

        // ── DeepSeek extended fields ──────────────────────────────────────────
        'ai.deepseek_base_url' => [
            'type' => 'string',
            'config' => 'ai.providers.deepseek.base_url',
            'is_secret' => false,
        ],
        'ai.deepseek_model' => [
            'type' => 'string',
            'config' => 'ai.providers.deepseek.model',
            'is_secret' => false,
        ],
        'ai.deepseek_max_tokens' => [
            'type' => 'int',
            'config' => 'ai.providers.deepseek.max_tokens',
            'is_secret' => false,
        ],
        'ai.deepseek_temperature' => [
            'type' => 'string',
            'config' => 'ai.providers.deepseek.temperature',
            'is_secret' => false,
        ],

        // ── GigaChat extended fields ──────────────────────────────────────────
        'ai.gigachat_base_url' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.base_url',
            'is_secret' => false,
        ],
        'ai.gigachat_model' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.model',
            'is_secret' => false,
        ],
        'ai.gigachat_max_tokens' => [
            'type' => 'int',
            'config' => 'ai.providers.gigachat.max_tokens',
            'is_secret' => false,
        ],
        'ai.gigachat_temperature' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.temperature',
            'is_secret' => false,
        ],
        'ai.gigachat_path_cert' => [
            'type' => 'string',
            'config' => 'ai.providers.gigachat.path_cert',
            'is_secret' => false,
        ],
    ];

    /**
     * Return metadata for a known key, or a sensible default for unknown keys.
     *
     * @return array{type: string, config: string|null, is_secret: bool}
     */
    public static function meta(string $key): array
    {
        return self::$keys[$key] ?? [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ];
    }

    /**
     * Return all registered keys.
     *
     * @return array<string>
     */
    public static function keys(): array
    {
        return array_keys(self::$keys);
    }

    /**
     * Check whether a key is registered in the registry.
     */
    public static function registered(string $key): bool
    {
        return isset(self::$keys[$key]);
    }
}
