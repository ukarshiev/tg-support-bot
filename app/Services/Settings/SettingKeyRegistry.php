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
            'config' => null,
            'is_secret' => true,
        ],
        'telegram.secret_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'telegram.group_id' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'telegram.template_topic_name' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'telegram.health_check_enabled' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'telegram.health_check_chat_id' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'telegram.health_check_languages' => [
            'type' => 'json',
            'config' => null,
            'is_secret' => false,
        ],

        // ── Telegram AI bot ──────────────────────────────────────────────────
        'telegram_ai.token' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'telegram_ai.secret' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'telegram_ai.username' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        // Numeric id + @username are captured automatically from getMe on save
        // (no manual entry). Informational — not compared at runtime.
        'telegram_ai.id' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],

        // ── VK ───────────────────────────────────────────────────────────────
        'vk.token' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'vk.secret_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'vk.confirm_code' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'vk.group_id' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],

        // ── Max ──────────────────────────────────────────────────────────────
        'max.token' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'max.secret_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],

        // ── AI assistant ─────────────────────────────────────────────────────
        'ai.enabled' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.auto_reply' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.default_provider' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.max_context_tokens' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.confidence_threshold' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.auto_escalation' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.enable_logging' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.openai_api_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'ai.openai_model' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.deepseek_client_id' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.deepseek_client_secret' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'ai.gigachat_client_id' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.gigachat_client_secret' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],

        // ── AI system prompt ─────────────────────────────────────────────────
        // Stored only in the DB (no .env/config fallback, no file, no default).
        // Empty until an admin saves one — see AiSystemPromptLoader.
        'ai.system_prompt' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── OpenAI extended fields ────────────────────────────────────────────
        'ai.openai_base_url' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.openai_max_tokens' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.openai_temperature' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── DeepSeek extended fields ──────────────────────────────────────────
        'ai.deepseek_base_url' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.deepseek_model' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.deepseek_max_tokens' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.deepseek_temperature' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── AI support moderation ───────────────────────────────────────────
        'ai.support_moderator_provider' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.support_moderator_model' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.support_archive_path' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── GigaChat extended fields ──────────────────────────────────────────
        'ai.gigachat_base_url' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.gigachat_model' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.gigachat_max_tokens' => [
            'type' => 'int',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.gigachat_temperature' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'ai.gigachat_path_cert' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        // OAuth scope: GIGACHAT_API_PERS (individuals) | GIGACHAT_API_B2B |
        // GIGACHAT_API_CORP (legal entities). Defaults to PERS in code when unset.
        'ai.gigachat_scope' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],

        // ── Support languages / translation ─────────────────────────────────
        'support.languages' => [
            'type' => 'json',
            'config' => null,
            'is_secret' => false,
        ],
        'translation.provider_order' => [
            'type' => 'json',
            'config' => null,
            'is_secret' => false,
        ],
        'translation.allow_external' => [
            'type' => 'bool',
            'config' => null,
            'is_secret' => false,
        ],
        'translation.yandex_api_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'translation.yandex_folder_id' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => false,
        ],
        'translation.google_api_key' => [
            'type' => 'string',
            'config' => null,
            'is_secret' => true,
        ],
        'translation.offline_endpoint' => [
            'type' => 'string',
            'config' => null,
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
