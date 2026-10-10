# Observability Rules

> **Purpose:** Guarantee that every feature is observable in production. Prevent "black box" systems that cannot be debugged after deployment.
> **Context:** Read this file before implementing any endpoint, background job, integration, or infrastructure change.
> **Version:** 1.1

---

## 1. Core Principle

If you cannot observe it, you cannot operate it.

- Every flow must produce logs
- Every service must expose health signals
- Every critical action must be measurable
- Every failure must be traceable
- Silent failures are forbidden

---

## 2. Monitoring Stack

This project uses the following observability tools:

| Tool | Purpose | Access |
|---|---|---|
| **Rotating log files** | Application logs on disk (`storage/logs/laravel-*.log`, `storage/logs/app-*.log`) | `php artisan pail` / `tail` |
| **Laravel Telescope** | Debug/inspection dashboard: requests, exceptions, **logs**, queries, jobs, cache, events | `GET /telescope` — session-based admin auth (guests → `/admin/login`); works with `APP_DEBUG=false` |
| **TG Logger** (`prog-time/tg-logger`) | Send critical alerts to Telegram channel | `TG_LOGGER_TOKEN`, `TG_LOGGER_CHAT_ID` |

> **Loki + Grafana were removed.** Centralized log aggregation is no longer used — logs live in rotating files (view with `php artisan pail`) and in the **Telescope** Logs tab at `/telescope`. The former `Log::channel('loki')` calls were renamed to `Log::channel('app')`, a daily rotating-file channel (`storage/logs/app-YYYY-MM-DD.log`); the `App\Logging\LokiHandler` class was deleted.
>
> **Telescope notes:** entries are stored in the `telescope_entries` tables and pruned daily by the scheduled `telescope:prune --hours=48` (needs a cron running `schedule:run`). In `local` everything is recorded; in non-local only failures/exceptions/scheduled/monitored entries are kept (`TelescopeServiceProvider::register()`). Dashboard access is gated by the middleware stack `['web', 'auth', App\Http\Middleware\TelescopeAccess::class]` (in `config/telescope.php` `middleware`): the `auth` middleware redirects guests to `/admin/login` (**302**), `TelescopeAccess` aborts **403** for authenticated non-admins, and admins reach the dashboard. This is **independent of `APP_DEBUG`** (keep it `false` in prod); set `TELESCOPE_ENABLED=false` to disable Telescope entirely. `TelescopeServiceProvider::authorization()` mirrors the same admin-only rule for the package's `Telescope::auth()` gate (defence in depth). **HTTP Basic auth was removed**: its `401` `WWW-Authenticate` challenge is intercepted/stripped by the upstream edge proxy in front of the production domain (it rewrites `4xx` responses), so the browser never showed the login prompt — session auth (`2xx`/`3xx`) passes the edge cleanly.

---

## 3. Structured Logging Rules

Logs must be structured and machine-readable.

**Mandatory rules:**
- Use Laravel's `Log` facade (backed by Monolog)
- Use channel-specific loggers configured in `config/logging.php`
- Include context array with relevant identifiers
- Include severity level appropriate to the event
- Never log sensitive data (tokens, passwords, secrets)

```php
// ✅ Correct — structured log with context
Log::info('Message sent to Telegram', [
    'bot_user_id' => $botUser->id,
    'chat_id' => $botUser->chat_id,
    'message_type' => $dto->typeQuery,
]);
```

```php
// ❌ Incorrect — unstructured and untrackable
Log::info('Message sent');
echo 'Message sent';
```

---

## 4. Log Level Conventions

| Level | When to Use | Example |
|---|---|---|
| `debug` | Development diagnostics only | DTO fields dump |
| `info` | Business events (created, sent, received) | "Topic created for user" |
| `warning` | Recoverable issues, retries | "Telegram API rate limit, retrying" |
| `error` | Failures that affect functionality | "Failed to send message after 5 retries" |
| `critical` | System cannot continue, data loss risk | "Job queue exhausted" |

**Rules:**
- Do not log everything as `error`
- Do not log business events as `debug`
- Production must not rely on `debug` logs

---

## 5. Telegram API Error Logging

All `TelegramError` cases must be logged explicitly.

```php
// ✅ Correct — log Telegram errors with context
$error = TelegramError::fromResponse($answer->description);
Log::error('Telegram API error', [
    'error' => $error?->value,
    'description' => $answer->description,
    'bot_user_id' => $botUser->id,
    'method' => $dto->methodQuery,
]);
```

```php
// ❌ Incorrect — swallow error silently
$error = TelegramError::fromResponse($answer->description);
// do nothing
```

---

## 6. Job Observability Rules

Every queue Job must include:

- Log at job start (INFO level, with job data context)
- Log at job completion (INFO level)
- Log on failure (ERROR level, with exception message)
- Exception must be re-thrown after logging (not swallowed)

```php
// ✅ Correct — job with proper logging
class SendTelegramMessageJob implements ShouldQueue
{
    public function handle(): void
    {
        Log::info('SendTelegramMessageJob started', ['bot_user_id' => $this->botUser->id]);

        try {
            // ... send logic
            Log::info('SendTelegramMessageJob completed', ['bot_user_id' => $this->botUser->id]);
        } catch (\Exception $e) {
            Log::error('SendTelegramMessageJob failed', [
                'bot_user_id' => $this->botUser->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

```php
// ❌ Incorrect — silent catch
public function handle(): void
{
    try {
        // ... send logic
    } catch (\Exception $e) {
        // nothing logged, exception swallowed
    }
}
```

---

## 7. Health Check Rules

The application must remain operable when checked by Docker/orchestration.

- PHP-FPM health is managed by Docker (`docker/php-fpm/` config)
- Nginx health is managed by Docker (`docker/nginx/` config)
- PostgreSQL connectivity is verified on app startup
- Do not add heavy database queries to health check endpoints

### Изоляция и насыщение PHP-FPM (TGSUPBOT-88)

- Оба точных webhook-пути обслуживать пулом `webhook` на 9001: 6 постоянных процессов, предел 30 секунд. Остальные запросы — `www` на 9000, максимум 14 процессов, прежний предел 120 секунд.
- Проверять оба пула каждую минуту командой `php-fpm:pool-health`: сводный JSON через независимые `pm.status_listen` на `0.0.0.0:9101/9102` внутри контейнера. Узел и порты читать через `config()` из `config/php-fpm.php` (по умолчанию `app`, 9101/9102). Scheduler сохраняет собственную сеть, DNS и `extra_hosts`; status-порты на хост и nginx-маршруты статуса не публиковать.
- При `webhook idle < 2`, `webhook listen queue > 0` или недоступности любого статуса возвращать код 1 и warning `php_fpm_pool_unhealthy` в канал `app` с пулом, причиной и доступными счётчиками.
- Slowlog писать отдельно для каждого пула в `storage/logs/php-fpm/php-fpm-{www,webhook}.slow.log`, порог 5 секунд, глубина 60. До запуска FPM создавать подкаталог от `www-data`, без блокирующей проверки `test -w`; `logs:prune` не должен удалять открытые файлы slowlog. Full-статус и URI активных запросов не журналировать.
- ✅ Правильно: зарезервировать webhook-процессы и читать статус через отдельные внутренние listeners.
- ❌ Неправильно: отдавать файлы через webhook-пул или читать статус через занятый основной listener.

Изменение правил 1.1: добавлены изоляция пулов, закрытый статус и минутный сигнал насыщения.

---

## 8. Sensitive Data Rules

Never expose private information in logs or error messages.

Pass every HTTP-client exception message through `App\Support\SecretMasker::mask()` before logging it. Mask Telegram API/file URL tokens (including URL-encoded tokens), prefixed and bare bot tokens, and proxy credentials.

The `App\Logging\MaskSecretsInLogs` tap on `single`, `daily`, `app`, `telegram`, and `stderr` wraps each supported handler's formatter and masks its final output, including formatted exceptions and nested string values. This safety net protects only Monolog output: Telescope and the `failed_jobs` table receive the original text. Explicit masking in application code remains mandatory.

```php
// ✅ Correct — sanitize before passing the exception message to the logger.
Log::channel('app')->error('HTTP request failed', [
    'error' => \App\Support\SecretMasker::mask($exception->getMessage()),
]);

// ❌ Incorrect — the output tap does not sanitize Telescope or failed_jobs.
Log::channel('app')->error('HTTP request failed', ['error' => $exception->getMessage()]);
```

Forbidden in logs:
- Channel access secrets from the DB `settings` table (`telegram.token`, `telegram.secret_key`, `telegram_ai.token`, `telegram_ai.secret`, `vk.token`, `vk.secret_key`, `max.token`, `max.secret_key`) — i.e. any key with `is_secret = true` in `SettingKeyRegistry`
- AI provider credentials from settings (`ai.openai_api_key`, `ai.gigachat_client_secret`, `ai.deepseek_client_secret`, etc.)
- Bearer tokens from `external_source_access_tokens`
- User passwords
- Infrastructure secrets (`DB_PASSWORD`, `APP_KEY`, `TG_LOGGER_TOKEN`)
- Full webhook payloads (may contain PII)

Mask when necessary:

```php
// ✅ Correct — mask token in log
Log::info('Webhook sent', ['url' => $webhookUrl, 'token' => '***']);
```

---

## 9. Definition of Done for Observability

A feature is not complete unless:

- Logs added for main flows (start, success, failure)
- Errors logged at correct level with context
- No silent catch blocks
- Sensitive data is not present in any log output

Observability is part of implementation, not an afterthought.

---

## Forbidden Behaviors

- ❌ `echo`, `var_dump`, `print_r` in production code
- ❌ Silent catch blocks (`catch (\Exception $e) {}`)
- ❌ Logging entire request/response objects without filtering
- ❌ Logging tokens or passwords
- ❌ Using `debug` level for production-critical events
- ❌ Relying only on manual debugging

---

## Checklist

- [ ] Structured logs used with context arrays
- [ ] Proper log levels applied
- [ ] Job start/success/failure logged
- [ ] Telegram API errors logged with context
- [ ] No sensitive data in logs
- [ ] No silent failures
- [ ] New flows observable in production
