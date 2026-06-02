# Security Rules

> **Purpose:** Ensure every change is secure by default. Prevent common classes of vulnerabilities frequently introduced by AI-generated code (injection, broken auth, leaked secrets, unsafe defaults).
> **Context:** Read this file before implementing any feature that touches authentication, input handling, database queries, external APIs, or configuration.
> **Version:** 1.0

---

## 1. Core Principle

Security is not optional or deferred.

- Always secure by default
- Always validate external input
- Always enforce authorization explicitly
- Never trust generated code blindly
- Never assume a route or service is internal-only

---

## 2. Webhook Authentication Rules

### Telegram Webhooks

All Telegram requests must be validated by the appropriate middleware before the controller runs.

**Main bot** — `TelegramQuery` middleware:
- Validates `X-Telegram-Bot-Api-Secret-Token` header against the `telegram.secret_key` setting (read via `SettingsService`; stored in the `settings` DB table)
- Rejects invalid requests with `403`
- Applies to: `POST /api/telegram/bot` and `POST /api/telegram/ai/bot`

**AI bot** — `AiBotQuery` middleware (`app/Modules/Ai/Middleware/AiBotQuery.php`):
- Validates `X-Telegram-Bot-Api-Secret-Token` header against the `telegram_ai.secret` setting (read via `SettingsService`)
- Rejects missing or invalid tokens with `403`
- Applies to: `POST /api/ai-bot/webhook`

```php
// ✅ Correct — each bot has its own middleware and secret
Route::post('/api/telegram/bot', [TelegramBotController::class, 'bot_query'])
    ->middleware(TelegramQuery::class);

Route::post('/api/ai-bot/webhook', [AiBotController::class, 'handle'])
    ->middleware(AiBotQuery::class);
```

```php
// ❌ Incorrect — unprotected webhook endpoint
Route::post('/api/ai-bot/webhook', [AiBotController::class, 'handle']);
```

### VK Webhooks

All VK requests must be validated by `VkQuery` middleware:

- Validates secret code from request body against the `vk.secret_key` setting (read via `SettingsService`)
- Returns confirmation code for VK verification requests
- Rejects invalid requests with `403`

### External API

All External API requests must be validated by `ApiQuery` middleware:

- Validates `Authorization: Bearer {token}` against `external_source_access_tokens` table
- Only `active = true` tokens are accepted
- Rejects missing or invalid tokens with `401`

---

## 3. Input Validation Rules

All external input is untrusted.

Applies to:
- HTTP requests (Telegram webhooks, VK webhooks, External REST API)
- Queue payloads
- File uploads

**Mandatory rules:**
- Validate all inputs at the boundary (Form Request or DTO `fromRequest()`)
- Use DTOs to type incoming data before passing to services
- Never pass raw `Request` objects to Services or Actions
- Reject unexpected fields
- Validate file types before storing

```php
// ✅ Correct — DTO validates and types incoming data
$dto = TelegramUpdateDto::fromRequest($request);
$this->service->process($dto);
```

```php
// ❌ Incorrect — raw request passed to service
$this->service->process($request);
```

---

## 4. Database Safety Rules

- Always use Eloquent ORM or Laravel's query builder with parameter binding
- Never concatenate user input into raw SQL
- Never use `DB::statement()` with user-provided values

```php
// ✅ Correct — parameterized query via Eloquent
BotUser::where('chat_id', $chatId)
    ->where('platform', $platform)
    ->first();
```

```php
// ❌ Incorrect — SQL injection risk
DB::select("SELECT * FROM bot_users WHERE chat_id = '$chatId'");
```

---

## 5. Secrets Management Rules

- **Application access credentials (bot tokens, webhook secrets, AI provider keys) live in the DB `settings` table, NOT in `.env`/`config()`.** They are read via `SettingsService`, stored encrypted (`Crypt::encrypt()`) for `is_secret` keys, and edited in `/admin/settings/*`. There is no `config()` fallback for these keys (`config => null` in `SettingKeyRegistry`).
- Only **infrastructure** secrets remain in environment variables (`.env`): `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_*`, `TG_LOGGER_TOKEN`. Never commit `.env`.
- Never hardcode API keys, tokens, or passwords in code or config files
- Never echo secrets in logs (see also `process/observability.md`)

```php
// ✅ Correct — read application credentials from the DB settings layer
$token = app(\App\Services\Settings\SettingsService::class)->get('telegram.token');
```

```php
// ❌ Incorrect — reading an app credential from config()/env() (removed), or hardcoding
$token = config('traffic_source.settings.telegram.token'); // no longer exists
$token = '1234567890:AABBcc_my_telegram_token_here';        // hardcoded secret
```

**Application access secrets (DB `settings` table, via `SettingsService`):**
- `telegram.token`, `telegram.secret_key` — main bot token + webhook validation secret
- `telegram_ai.token`, `telegram_ai.secret` — AI bot token + webhook validation secret
- `vk.token`, `vk.secret_key`, `vk.confirm_code` — VK API token + webhook secret + confirm code
- `max.token`, `max.secret_key` — MAX token + webhook secret
- `ai.openai_api_key`, `ai.deepseek_client_secret`, `ai.gigachat_client_secret` (+ client ids / base urls / models / cert) — AI providers
- Non-secret access keys (`telegram.group_id`, `telegram_ai.username`, etc.) also live in `settings` but are stored unencrypted

**Infrastructure secrets (`.env` only):** `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_*`, `TG_LOGGER_TOKEN`. Per-source bearer tokens live in the `external_source_access_tokens` table.

**Handling rules for DB settings secrets:**
- Secrets (`is_secret = true` in `SettingKeyRegistry`) are encrypted via `Crypt::encrypt()` and surfaced in the admin UI as `<input type="password">` fields
- Never log decrypted token values — log only non-sensitive context (URL registered, HTTP status code)
- Blank-submission guard: if the UI field is left empty, do NOT overwrite the stored encrypted value
- In logs, do not include any key whose `is_secret = true` in `SettingKeyRegistry`
- Read credentials via `SettingsService::get()` — never via `config()`/`env()` (the access branches were removed)

---

## 6. File Upload Security Rules

- Validate file type before storing (do not trust `Content-Type` header alone)
- Store uploaded files in `storage/app/` (not in `public/`)
- Serve files through `FilesController` which controls access
- Never expose raw file paths in API responses

```php
// ✅ Correct — file served via controller with access control
GET /api/files/{file_id}  → FilesController@getFileStream
```

```php
// ❌ Incorrect — direct public URL to stored file
return response()->json(['url' => 'storage/uploads/user_file.jpg']);
```

---

## 7. Authorization Rules

- Every protected route must go through appropriate middleware (`TelegramQuery`, `VkQuery`, `ApiQuery`)
- The External API uses token-based authorization (`ApiQuery` middleware)
- Admin routes (if any) must require authenticated session
- Never implement authorization logic inside Services or Models — use middleware and policies

```php
// ✅ Correct — authorization in middleware
class ApiQuery
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $token = $request->bearerToken();
        if (!ExternalSourceAccessTokens::where('token', $token)->where('active', true)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
```

```php
// ❌ Incorrect — inline auth check in service
class ExternalTrafficService
{
    public function store(Request $request): void
    {
        if ($request->header('Authorization') !== 'Bearer my-token') {
            abort(401);  // auth logic in service
        }
    }
}
```

---

## 8. AI-Specific Safety Rules

These rules exist specifically for AI agents:

- Never invent security mechanisms
- Never guess cryptography implementations
- Never design custom auth or crypto
- Use established Laravel mechanisms only (middleware, Eloquent, Hash facade)
- Never fabricate fields like `isAdmin`, `isVerified`, `isSecure` that don't exist in the schema
- If unsure about a security decision, stop and ask instead of guessing

Hallucinated security logic is dangerous.

---

## 9. Error Response Rules

- Never return stack traces to clients
- Never expose internal IDs, file paths, or debug info in API responses
- Use generic error messages for security-sensitive failures

```php
// ✅ Correct
return response()->json(['message' => 'Unauthorized'], 401);
```

```php
// ❌ Incorrect
return response()->json([
    'error' => 'Unauthorized',
    'trace' => $e->getTraceAsString(),
    'token' => $bearerToken,
], 401);
```

---

## 10. Dependency Security Rules

- All dependencies are locked via `composer.lock`
- Never add packages without explicit justification
- Prefer packages with active maintenance history
- Do not upgrade packages without testing

---

## Forbidden Behaviors

- ❌ Unprotected webhook endpoints
- ❌ Raw SQL string concatenation
- ❌ Hardcoded credentials
- ❌ Tokens or passwords in log output
- ❌ Raw `Request` objects passed to business logic
- ❌ Catching and silently ignoring security errors
- ❌ Custom cryptography implementations
- ❌ Returning debug information in API error responses

---

## Checklist

- [ ] All webhook routes use appropriate middleware
- [ ] All External API routes use `ApiQuery` middleware
- [ ] Input validated via DTO `fromRequest()` or Form Request
- [ ] No raw SQL with user input
- [ ] No secrets in code or config files
- [ ] Files served via controller, not public URLs
- [ ] Authorization enforced via middleware
- [ ] No sensitive data in error responses
- [ ] No invented security mechanisms
