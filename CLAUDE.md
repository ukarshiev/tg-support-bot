# CLAUDE.md

Instructions for Claude Code when working with the TG Support Bot project.

> **IMPORTANT:** This project has a structured `rules/` documentation system. Before starting any non-trivial task, read `rules/README.md` and the relevant domain/process files listed below.

---

## Rules Directory

The `rules/` directory is the source of truth for all architectural decisions, business rules, and coding standards.

**Always read before working:**

| Task Type | Files to Read |
|---|---|
| Any task | `rules/README.md` |
| Messaging / sending | `rules/domain/messaging.md` |
| User management / banning | `rules/domain/bot-users.md` |
| AI assistant logic | `rules/domain/ai-assistant.md` |
| External API integration | `rules/domain/external-sources.md` |
| Admin panel / manager UI | `rules/domain/admin-panel.md` |
| Database / migrations | `rules/database/schema.md` |
| HTTP routes / endpoints | `rules/api/endpoints.md` |
| Architecture decisions | `rules/process/architecture-design.md` |
| Logging / monitoring | `rules/process/observability.md` |
| Security / auth | `rules/process/security.md` |
| Tests | `rules/process/testing-strategy.md` |
| CI/CD / git hooks | `rules/process/ci-cd.md` |

---

## Project Description

TG Support Bot is a Laravel 12 application for customer support via Telegram and VK. The support team works in a Telegram supergroup with forum topics — each user gets their own topic thread. The project also integrates with external third-party systems via REST API.

**Supported platforms:**
- **Telegram** — main support channel (forum topics in supergroup)
- **VK** — secondary support channel
- **Max** — additional support channel
- **External Sources** — third-party integrations via REST API + webhooks
- **Pluggable platform modules** — additional channels can be shipped as separate (incl. paid, private) Composer packages that implement `App\Contracts\PlatformChannel` and self-register in `App\Platform\PlatformChannelRegistry` — no core edits required (e.g. the paid Avito module)

**Key integrations:**
- AI providers: OpenAI, DeepSeek, GigaChat (draft responses for manager review)
- Monitoring: Grafana + Loki + Sentry
- Live chat: Node.js server (port 3001)

---

## Tech Stack

| Component | Technology |
|---|---|
| Language | PHP 8.2+ |
| Framework | Laravel 12 |
| Database | PostgreSQL |
| Cache / Queue | Redis + Laravel Queue |
| Containers | Docker |
| API Documentation | L5-Swagger (annotations-based) |
| Static Analysis | PHPStan level 6 (larastan) |
| Code Formatting | Laravel Pint (PSR-12 + Laravel) |
| Testing | PHPUnit 11 + Mockery |
| Admin Panel | Filament 3 |
| Error Tracking | Sentry |
| Log Aggregation | Loki + Grafana + Promtail |
| Telegram Logging | prog-time/tg-logger |

---

## Architecture

```
HTTP Layer          app/Http/Controllers/ + app/Modules/*/Controllers/
     ↓
DTO Layer           app/DTOs/ + app/Modules/*/DTOs/
     ↓
Business Logic      app/Services/ + app/Modules/*/Services/ + app/Actions/
     ↓              ↓
Integration         ManagerInterfaceContract
app/Modules/Telegram/Api/   /          \
app/Modules/Vk/Api/   TelegramGroupInterface   AdminPanelInterface
     ↓              (forum topics)         (Filament web panel)
Queue Layer         app/Modules/*/Jobs/
     ↓
Data Layer          app/Models/ + PostgreSQL
```

### Layer Responsibilities

| Layer | Directory | Rule |
|---|---|---|
| Controllers | `app/Http/Controllers/`, `app/Modules/*/Controllers/` | Thin — receive request, dispatch job or call service, return response |
| Middleware | `app/Modules/*/Middleware/` | Validate incoming webhooks (Telegram, VK, External API auth) |
| DTOs | `app/DTOs/`, `app/Modules/*/DTOs/` | Parse and type incoming data via static `fromRequest()` |
| Services | `app/Services/`, `app/Modules/*/Services/` | Reusable business logic |
| Actions | `app/Actions/`, `app/Modules/*/Actions/` | Single isolated operations (one action = one thing) |
| Telegram/VK API | `app/Modules/Telegram/Api/`, `app/Modules/Vk/Api/` | Direct API calls only |
| Admin | `app/Modules/Admin/` | Custom full-page Livewire screens (chat workspace + Settings), Filament panel for authentication only, SendReplyAction, admin design system |
| Jobs | `app/Modules/*/Jobs/` | All async operations — message sending, webhooks |
| Models | `app/Models/` | Data operations only, no business logic, no API calls |

### Key Patterns

- **Action Pattern** — `app/Modules/*/Actions/` — static `execute()`, one responsibility
- **Service Pattern** — `app/Services/`, `app/Modules/*/Services/` — injected, reusable logic
- **DTO Pattern** — `app/DTOs/`, `app/Modules/*/DTOs/` — typed data transfer between layers
- **Queue Pattern** — all Telegram/VK API sends go through Jobs, never synchronously
- **Middleware Pattern** — webhook validation before controller runs
- **Contract Pattern** — `ManagerInterfaceContract` decouples manager UI from business logic
- **Platform Registry Pattern** — `PlatformChannel` + `PlatformChannelRegistry` (`app/Platform/`) let external (incl. paid, private) platform packages self-register delivery for a `platform` key without editing the core
- **Settings Pattern** — `SettingsService` + `SettingKeyRegistry` (`app/Services/Settings/`) provide a unified `get/set/has/forget` API for runtime-editable settings (DB → `config()` fallback, Redis cache, `Crypt` encryption for secrets, type coercion); known keys registered in `SettingKeyRegistry`
- **Admin Design System Pattern** — Tailwind v4 tokens in `resources/css/app.css @theme` + shared Blade components in `resources/views/components/admin/`. All admin content screens (chat workspace + Settings) are custom Livewire/Blade on this design system; Filament chrome remains only on the `/admin/login` page.

---

## Project Structure

```
app/
├── Actions/          # Shared isolated operations (Ai/)
├── Contracts/        # Interfaces (AiProviderInterface, ManagerInterfaceContract, PlatformChannel)
├── Livewire/
│   ├── Chat/         # Standalone full-page Livewire workspace (chrome-free)
│   │   └── ConversationPage.php         # GET /admin/chats — full-screen 3-column manager workspace
│   └── Settings/     # Custom full-page Livewire components for Settings section
│       ├── GeneralSettingsPage.php       # /admin/settings/general
│       ├── IntegrationsListPage.php      # /admin/settings/integrations
│       ├── IntegrationChannelPage.php   # /admin/settings/integrations/{channel}
│       ├── AiAssistantPage.php          # /admin/settings/ai
│       ├── AiProviderAccessPage.php     # /admin/settings/ai/{provider}
│       ├── ApiWebhooksPage.php          # /admin/settings/api-webhooks (admin-only, source card list)
│       ├── ApiWebhookSourcePage.php     # /admin/settings/api-webhooks/{source} (per-source edit)
│       └── TeamPage.php                 # /admin/settings/team (admin-only, operator management)
├── Platform/         # PlatformChannelRegistry — registry for pluggable platform modules
├── DTOs/             # Shared Data Transfer Objects (Ai/, Button/, Redis/)
├── Services/
│   └── Settings/     # Settings persistence layer: SettingsService, SettingKeyRegistry
├── Enums/            # Enumerations (ButtonType, TelegramError, VkError)
├── Helpers/          # Utilities (TelegramHelper, AiHelper, DateHelper)
├── Http/
│   └── Controllers/  # SimplePage, FilesController, SwaggerController, PreviewController
├── Logging/          # LokiHandler
├── Models/           # BotUser, Message, ExternalMessage, ExternalSource, AiMessage, etc.
├── Modules/
│   ├── Admin/        # Admin panel — custom Livewire screens (Filament kept for authentication only)
│   │   ├── Actions/  # SendReplyAction, InviteOperator
│   │   ├── AdminPanelProvider.php    # Filament panel: login only; /admin → /admin/chats; nav via navigationItems()
│   │   ├── AdminServiceProvider.php  # Custom Livewire routes (/admin/chats, /admin/settings/*)
│   │   └── Services/ # AdminPanelInterface + ChannelStatusService + WebhookRegistrationService
│   ├── External/     # External Sources integration
│   │   ├── Actions/, Controllers/, DTOs/, Jobs/, Middleware/, Services/
│   ├── Telegram/     # Telegram bot
│   │   ├── Actions/, Api/, Controllers/, DTOs/, Jobs/, Middleware/, Services/
│   │   └── Services/TelegramGroupInterface.php  # ManagerInterfaceContract implementation
│   └── Vk/           # VK bot
│       ├── Actions/, Api/, Controllers/, DTOs/, Jobs/, Middleware/, Services/
├── Providers/        # AppServiceProvider (binds ManagerInterfaceContract)
└── Services/         # Shared services (Ai/, Button/, File/, Swagger/, Webhook/)

resources/
├── css/app.css        # Tailwind v4 @theme tokens: admin design system (Inter, accent, sidebar, input, …)
├── views/
│   ├── components/
│   │   └── admin/    # Shared admin Blade components (Stage 1)
│   │       ├── sidebar.blade.php, nav-item.blade.php, card.blade.php
│   │       ├── form-field.blade.php, button-primary.blade.php
│   │       ├── button-secondary.blade.php, toggle.blade.php
│   ├── layouts/
│   │   ├── admin-settings.blade.php  # Dark-sidebar layout for custom settings pages
│   │   └── admin-chat.blade.php      # Minimal full-screen layout for the chat workspace (no Filament chrome)
│   └── livewire/
│       ├── chat/
│       │   └── conversation-page.blade.php            # View for App\Livewire\Chat\ConversationPage
│       └── settings/
│           ├── general-settings-page.blade.php        # View for GeneralSettingsPage
│           ├── integrations-list-page.blade.php       # View for IntegrationsListPage
│           ├── integration-channel-page.blade.php    # View for IntegrationChannelPage
│           ├── ai-assistant-page.blade.php           # View for AiAssistantPage
│           ├── ai-provider-access-page.blade.php    # View for AiProviderAccessPage
│           ├── api-webhooks-page.blade.php          # View for ApiWebhooksPage (source card list)
│           ├── api-webhook-source-page.blade.php   # View for ApiWebhookSourcePage (per-source edit)
│           └── team-page.blade.php                 # View for TeamPage (operator management)
```

---

## Development Commands

### Start the project
```bash
docker compose up -d
docker exec -it pet composer install
```

### Code formatting (run before every commit)
```bash
docker exec -it pet ./vendor/bin/pint
```

### Static analysis (run before every push)
```bash
docker exec -it pet ./vendor/bin/phpstan analyse
```

### Run tests
```bash
docker exec -it pet php artisan test
# or
docker exec -it pet ./vendor/bin/phpunit
```

### Run specific test
```bash
docker exec -it pet php artisan test --filter=TestName
```

---

## Code Standards

### Formatting (PSR-12 + Laravel, enforced by Pint)

- Indentation: 4 spaces
- Single quotes for strings
- Short array syntax `[]`
- Trailing comma in multiline arrays
- Remove unused imports
- Sort imports alphabetically

### Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Classes | `PascalCase` | `SendBannedMessage` |
| Methods, variables | `camelCase` | `getByTopicId()` |
| Constants | `UPPER_SNAKE_CASE` | `MAX_RETRIES` |
| Migration files | `snake_case` | `create_bot_users_table` |
| Actions | Static `execute()` | `GetChat::execute($chatId)` |
| DTOs | Static `fromRequest()` | `TelegramUpdateDto::fromRequest($request)` |
| Jobs | `*Job` suffix | `SendTelegramMessageJob` |

### PHPDoc (required for all public methods)

```php
/**
 * Brief method description.
 *
 * @param BotUser $botUser The target bot user
 * @return TelegramAnswerDto
 * @throws TelegramException When Telegram API call fails
 */
public static function execute(BotUser $botUser): TelegramAnswerDto
{
}
```

---

## Business Rules Summary

### Messaging

- All message sending to Telegram/VK must go through **queue Jobs**, never synchronously
- Every sent/received message must be saved to the `messages` table
- Banned users receive a banned notification, not a regular reply
- Each bot user has exactly one Telegram forum topic thread

### Bot Users

- Every interaction creates or finds a `BotUser` record
- Users are identified by `chat_id` + `platform` (not `chat_id` alone)
- Banning sets `is_banned = true`, `banned_at`, and closes the Telegram topic

### AI Assistant

- AI is disabled by default (`AI_ENABLED=false`); can be toggled from the admin panel at `/admin/settings/ai`
- AI runs through a **separate Telegram bot** (`TELEGRAM_AI_BOT_TOKEN`) that is added to the same supergroup
- The AI bot webhook URL is `POST /api/ai-bot/webhook`, protected by `AiBotQuery` middleware (`TELEGRAM_AI_BOT_SECRET`)
- `AI_AUTO_REPLY=false` (default): AI posts a draft with "Accept / Cancel" inline buttons; manager reviews before sending
- `AI_AUTO_REPLY=true`: AI posts the reply directly to the topic; it is immediately sent to the user via `SendReplyAction`; enabling via admin panel requires explicit confirmation
- The AI bot only replies to messages whose `from.id` equals `TELEGRAM_BOT_ID` (forwarded user messages from the main bot)
- The AI bot does NOT reply when `MANAGER_INTERFACE=admin_panel`
- Supported providers: OpenAI, DeepSeek, GigaChat (set via `AI_DEFAULT_PROVIDER` or from `/admin/settings/ai`)
- Register the AI bot webhook with: `docker exec -it pet php artisan ai-bot:set-webhook`
- AI conversation history is sourced from the `messages` table (no Redis cache), bounded by `AI_MAX_CONTEXT_TOKENS` (default 3000) using a `mb_strlen / 4` token heuristic with sliding-window trimming
- AI system prompt: stored in `settings` DB under key `ai.system_prompt` (editable from `/admin/settings/ai`); the Blade fallback at `resources/ai/system-prompt.blade.php` is NOT overwritten by the admin UI
- AI provider credentials (API keys, base URLs, models, tokens, cert path) are managed at `/admin/settings/ai/{provider}` and stored encrypted in the `settings` table via `SettingsService`
- AI drafts NEVER write to `messages` (only to `ai_messages`); a `messages` row appears only when the message is actually sent to the user — this invariant is what makes "any outgoing row = delivered" safe for the chat-history assembler
- AI runs across all user platforms (`telegram`, `vk`, `max`). Triggers: `TelegramBotController::maybeDispatchAi()` for TG, `VkMessageService::maybeDispatchAi()` for VK, `MaxMessageService::maybeDispatchAi()` for Max. Triggering is text-only — attachments do not start AI. Gating still goes through `ShouldAiReply` (TG-DTO and platform-agnostic variants share the same rules: AI_ENABLED, `MANAGER_INTERFACE=telegram_group`, replyable text, user active)
- Final delivery of an AI answer to the user (Accept and auto-reply) is routed by `BotUser.platform` through `App\Modules\Ai\Actions\DeliverAiAnswerToUser` → `SendTelegramMessageJob` / `SendVkMessageJob` / `SendMaxMessageJob`. Any other platform is delegated to a `PlatformChannel` registered in `App\Platform\PlatformChannelRegistry` by a pluggable module (e.g. the paid Avito package). The Accept callback still edits the supergroup draft via `SendTelegramMessageJob` using the AI bot token regardless of user platform
- **Runtime application (deferred):** AI settings saved in the admin panel are persisted to the DB and displayed correctly in the UI. `ShouldAiReply`, `AiAssistantService`, and AI providers still read from `config('ai.*')` at runtime — full DB-based runtime wiring is a follow-up task

### Settings Persistence Layer

- Runtime-editable application settings are stored in the `settings` table and accessed exclusively via `SettingsService` (`app/Services/Settings/SettingsService.php`)
- **Reading priority:** DB row → `config()`/`.env` default (declared in `SettingKeyRegistry`) → `null`
- **Never call `config()` directly** for a setting that may be overridden at runtime — use `app(\App\Services\Settings\SettingsService::class)->get('key')` instead
- Secret keys (`is_secret=true` in `SettingKeyRegistry`) are encrypted with `Crypt::encrypt()` before DB write and decrypted transparently in `get()` — never read the raw `settings.value` column for secret keys
- Cache: values cached forever in the default store (Redis); invalidated on `set()` / `forget()`
- Known keys and their types/fallbacks/secret flags are registered in `SettingKeyRegistry::$keys`
- The `settings` table is empty by default — fallback to `config()` is always active until a value is explicitly saved
- The General Settings screen (`/admin/settings/general`, `app/Livewire/Settings/GeneralSettingsPage.php`) provides a custom Livewire/Blade UI (NOT Filament chrome) for editing `app.bot_name`, `app.bot_description`, and `app.manager_interface`. Uses the admin design system (Tailwind v4 tokens + `<x-admin.*>` Blade components)

### Channel Integrations (Settings)

- The Integrations screen (`/admin/settings/integrations`, `app/Livewire/Settings/IntegrationsListPage.php`) shows Telegram, VK, MAX channel cards with connection status computed by `ChannelStatusService`
- Per-channel config forms (`/admin/settings/integrations/{channel}`, `IntegrationChannelPage`) let admins configure tokens and keys for each platform
- Channel config is read/written exclusively via `SettingsService` using the registry keys `telegram.*`, `vk.*`, `max.*`
- All secret fields (tokens, keys) are rendered as `type="password"` inputs; blank submission does not overwrite an existing stored secret
- Webhook registration for each channel is handled by `WebhookRegistrationService` — never directly call platform API methods from the Livewire component
- `WebhookRegistrationService` wraps: Telegram (`TelegramMethods::sendQueryTelegram('setWebhook', ...)`), VK (connectivity via `VkMethods::sendQueryVk('groups.getById', ...)`), MAX (`Http::post(...platform-api.max.ru/subscriptions...)`)
- Tokens are never logged — only non-sensitive context (registered URL, HTTP status code)
- See `rules/domain/admin-panel.md` (BR-013 through BR-016) and `rules/process/security.md` (Secrets in the DB settings table)

### Team Screen (`/admin/settings/team`)

- The Team screen (`app/Livewire/Settings/TeamPage.php`) is admin-only — non-admins are redirected to `admin.settings.general` in `mount()` (see `rules/domain/admin-panel.md` BR-026)
- Inviting a new operator calls `App\Modules\Admin\Actions\InviteOperator::execute(email, role)`: creates the `User` immediately with a 16-char secure password (`Str::password(16)`), queues `OperatorInvitationMail` with the plain-text password and login URL (see BR-027)
- The plain-text password is NEVER logged — it is passed only to the Mailable constructor and discarded after serialisation
- Mail driver is `log` locally (no SMTP needed); queuing must not fail if SMTP is unavailable
- Delete requires a two-step confirmation (`confirmDelete(userId)` → `deleteMember()`). Admins cannot delete themselves — `deleteMember()` guards against `Auth::id() === $confirmDeleteId` (see BR-028)
- Online status: v1 stub — renders a «—» placeholder badge. No `last_seen_at` column or tracking middleware. Real online-tracking is a separate future task
- Avatar initials: deterministic, derived from user name (two-word: first letters; single-word: first 2 chars) or email local-part fallback; color: `crc32(email) % 8` from 8-color palette

### External Sources

- Requests must be authenticated with a bearer token from `external_source_access_tokens`
- When the team replies to an external user, a webhook is sent to `external_sources.webhook_url`
- Bearer tokens are managed in the admin panel at `/admin/settings/api-webhooks` (`ApiWebhooksPage`) — admin-only
- Token length: 64 characters (`Str::random(64)` in `ExternalSourceTokensService::generateToken()`)
- Token active flag: `external_source_access_tokens.active` — inactive tokens (`active=false`) are rejected by `ApiQuery` middleware with 401
- Token values are never logged or displayed in full — only a one-time reveal after regeneration, followed by dismissal
- See `rules/domain/admin-panel.md` (BR-023, BR-024, BR-025) and `rules/domain/external-sources.md`

### Feedback Form

- When `CloseTopic::execute()` closes a conversation, `SendFeedbackForm` creates a `Feedback` record (`status='awaiting_rating'`) and sends a 5-star inline-keyboard rating form to the user on their platform (Telegram/VK/Max; other platforms are delegated to a `PlatformChannel` registered in `App\Platform\PlatformChannelRegistry`, e.g. the paid Avito module)
- Telegram callback_data format: `feedback_rate_{botUserId}_{feedbackId}_{score}` (score 1..5) — handled in `TelegramBotController::checkBotQuery()`
- VK rating callbacks arrive as `type=message_event` with `payload.command` containing `feedback_rate_*` — handled in `VkBotController`
- Max rating callbacks arrive as `update_type=message_callback` with `callback.payload` containing `feedback_rate_*` — handled in `MaxBotController`
- On rating click: `HandleFeedbackRating` saves `rating`, sets `status='completed_no_comment'`, edits message to thank-you text. `comment` stays NULL — no comment capture is implemented
- Every close event creates a new `Feedback` record — history accumulates
- `Feedback` records persist in the DB; the legacy Filament admin UI for them was removed (a redesigned screen is pending)

### Manager Interface

- `MANAGER_INTERFACE=telegram_group` (default) — managers work via Telegram supergroup with forum topics
- `MANAGER_INTERFACE=admin_panel` — managers work via the `/admin/chats` workspace (full-screen, chrome-free, `App\Livewire\Chat\ConversationPage`)
- Switching via `.env`: change `MANAGER_INTERFACE` + `docker compose restart app`
- Switching via admin panel: save via General Settings screen (`/admin/settings/general`, `GeneralSettingsPage`) → value written to `settings` DB table via `SettingsService` (overrides `.env` on next read); container restart still required for DI binding to take effect (`docker compose restart app`) — screen shows a persistent yellow warning notice
- The `ManagerInterfaceContract` DI binding in `AppServiceProvider::register()` reads from `config('app.manager_interface')` at boot, NOT from `SettingsService` — this is intentional to avoid DB dependency at container startup
- Does not require `php artisan migrate` or any DB changes (for mode switching itself)
- See `rules/domain/admin-panel.md` for full rules (BR-001 through BR-025)

---

## Security Rules

- All main bot Telegram webhooks validated by `TelegramQuery` middleware (`X-Telegram-Bot-Api-Secret-Token` vs `TELEGRAM_SECRET_KEY`)
- AI bot webhook validated by `AiBotQuery` middleware (`X-Telegram-Bot-Api-Secret-Token` vs `TELEGRAM_AI_BOT_SECRET`)
- All VK webhooks validated by `VkQuery` middleware
- All External API requests validated by `ApiQuery` middleware (bearer token)
- Never pass raw `Request` objects to Services or Actions — use DTOs
- Never use raw SQL string concatenation — use Eloquent / query builder
- Never commit `.env` files or hardcode secrets
- Never log tokens, passwords, or API keys

---

## Commit Rules

### Message Format

```
issues-{number} | {brief description}
```

### Examples

```
issues-123 | add VK sticker support
issues-45 | fix telegram webhook error handling
issues-78 | update rules documentation
```

### Change Types

- `add` — new feature
- `fix` — bug fix
- `update` — update existing functionality
- `refactor` — refactoring (no behavior change)
- `remove` — deletion
- `docs` — documentation only
- `test` — tests only
- `style` — formatting only
- `chore` — routine maintenance

---

## Branch Naming

```
issues-{number}
issues-{number}-{brief-description}
```

Examples: `issues-38`, `issues-45-fix-telegram-webhook`

---

## Testing

### Test Location Rule

Test file location mirrors source file location:

| Source | Test |
|---|---|
| `app/Actions/Telegram/GetChat.php` | `tests/Unit/Actions/Telegram/GetChatTest.php` |
| `app/Services/Tg/TgMessageService.php` | `tests/Unit/Services/Tg/TgMessageServiceTest.php` |

### Requirements

- New functionality must be covered by tests before merge
- Bug fixes must include a regression test
- Unit tests use `Http::fake()` — no real API calls
- Feature tests use `RefreshDatabase` trait
- Test naming: `test_can_do_something` or `test_throws_exception_when_invalid`
- Tests run with SQLite in-memory database (see `phpunit.xml`)

### Test Structure

```
tests/
├── Unit/       # Actions, Services, Helpers, Models
├── Feature/    # HTTP endpoints, integrations
├── Mocks/      # Mock objects
├── Stubs/      # Raw data stubs (webhook payloads)
└── Traits/     # Reusable traits
```

---

## Git Hooks

| Hook | Script | What it checks |
|---|---|---|
| `pre-commit` | `linting/pre-commit-check.sh` | Laravel Pint formatting |
| `pre-push` | `linting/pre-push-check.sh` | PHPStan level 6 + PHPUnit |

Never bypass hooks with `--no-verify`.

---

## Post-Task Verification

Before marking any task complete:

1. All new public methods have PHPDoc with type hints
2. New classes have corresponding test files in `tests/`
3. Laravel Pint passes with no changes needed
4. PHPStan level 6 passes with 0 errors
5. All tests pass
6. If schema changed → `rules/database/schema.md` updated
7. If routes changed → `rules/api/endpoints.md` updated
8. If business rules changed → relevant `rules/domain/*.md` updated
9. No secrets committed (`.env` excluded from git)
