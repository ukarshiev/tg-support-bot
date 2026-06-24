# Upgrade Guide

## Upgrading to 8.0.0 from 7.x

Release 8.0.0 is a **major release** with breaking changes to configuration and
the manager-interface model. Read this guide fully before deploying to a running
environment.

### Breaking changes

1. **Channel & AI credentials moved from `.env` to the database.**
   All `telegram.*`, `telegram_ai.*`, `vk.*`, `max.*` and AI provider
   credentials/behaviour keys are now stored exclusively in the `settings` table
   and read via `SettingsService` (registry entries declare `config => null`, so
   there is **no `.env`/`config()` fallback**). After upgrading, these values
   return `null` until they are re-entered through the admin panel — the bot will
   not send or receive messages and AI replies will fail until then.

2. **`MANAGER_INTERFACE` removed.** The manager-interface mode switch and the
   `ManagerInterfaceContract` abstraction were removed. The admin panel
   (`/admin/chats`) is now always-active; incoming and outgoing messages persist
   to the panel independently of the Telegram group. Remove `MANAGER_INTERFACE`
   from your `.env` (it is ignored).

3. **Filament removed.** The admin panel is now custom Livewire + standard
   Laravel `web`/`auth` guard. Routes are unchanged for end users
   (`/admin/login`, `/admin/chats`, `/admin/settings/*`).

4. **Telescope basic-auth removed.** The Telescope dashboard is now gated by
   session-based admin auth (logged-in admin), not HTTP Basic auth. Remove
   `TELESCOPE_AUTH_USER` / `TELESCOPE_AUTH_PASSWORD` from your `.env`.

5. **Sentry and the Loki/Grafana logging stack removed.** Application logs now
   go to rotating files (`storage/logs/`), viewable via `php artisan pail` or
   Telescope. Drop the corresponding `.env` keys and infrastructure.

### Deployment steps

```bash
# 1. Pull the release and install dependencies
composer install --no-dev --optimize-autoloader

# 2. Run the new migrations (all additive: nullable / default columns)
php artisan migrate

# 3. Clear and rebuild caches
php artisan config:clear
php artisan cache:clear

# 4. Re-enter all secrets via the admin panel (REQUIRED — no .env fallback):
#    /admin/settings/integrations  → Telegram, Telegram AI bot, VK, MAX tokens
#    /admin/settings/ai/{provider} → OpenAI / DeepSeek / GigaChat credentials
#    /admin/settings/general       → Telegram group_id, topic-name template

# 5. Re-register webhooks once credentials are saved
php artisan ai-bot:set-webhook
```

### Migration safety

All ten new migrations are additive — new columns are `nullable()` or carry a
`default()`, no data backfill is required, and each defines a `down()`.

> Rollback caveat: `add_status_to_ai_messages_table` restores
> `ai_messages.message_id` to `NOT NULL` in `down()`. If any AI draft rows have a
> `NULL message_id` at rollback time, the rollback will fail. This affects
> rollback only, never the forward migration.
