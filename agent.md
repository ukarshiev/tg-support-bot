# Последняя редакция: 11.07.2026 03:42 UTC+3

# Локальные правила для агентов

Основные инструкции лежат в `AGENTS.md`.

## Учётные записи для тестов

- Не создавать новые учётные записи без прямой команды Владыки.
- Не менять пароль, email, роль или доступы `admin@relaxa.club`.
- Для тестирования использовать только:
  - `playwright-admin@example.test`;
  - `test@example.com`.

## Где брать доступы

Пароли и локальные тестовые доступы не хранятся в git.

1) `.env` — Почему: локальный ignored-файл, удобен для автоматизации.
2) `.local-support-credentials.txt` — Почему: локальная памятка для агента, тоже ignored.
3) Bitwarden Secrets Manager — Почему: общее хранилище серверных/API-доступов без хранения значений на ПК.

Нужные ключи для локальных тестов:

- `PLAYWRIGHT_ADMIN_EMAIL`
- `PLAYWRIGHT_ADMIN_PASSWORD`
- `TEST_ADMIN_EMAIL`
- `TEST_ADMIN_PASSWORD`

Для доступа агента к Bitwarden локально используется `C:\Users\umidt\.codex\secrets\services.txt`; в нём должны быть только `bitwarden_ai_token` и `bitwarden_project`.

## Трекинг задач

Основной трекер — Plane, проект `TgSupportBot`. Подробные правила создания и обновления задач см. в `AGENTS.md`. Секреты Plane/API хранятся в Bitwarden Secrets Manager; локальный `C:\Users\umidt\.codex\secrets\services.txt` содержит только доступ агента к Bitwarden и не хранится в git.
