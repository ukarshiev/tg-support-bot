# Последняя редакция: 30.06.2026 17:35 UTC+3

# tg-support-bot — правила агентов для интеграции с PostEditBot

Обращайся к пользователю как **Владыка**. Отвечай по-русски, коротко и по делу. Если нужно действие от Владыки — объясняй простыми словами.

## Главный принцип

Главный принцип — **не навреди**.

- Не гадай. Сначала проверяй факты: git, файлы, Docker, логи, миграции, текущие настройки.
- Не делай прямой доступ к БД PostEditBot. Только API.
- Не коммить чужой WIP и не используй `git add .`.
- Не храни секреты в коде, README, changelog или логах.
- Все runtime-настройки — через БД/UI. `.env` допустим только как bootstrap/fallback.

## Upstream-first правило

Этот репозиторий должен обновляться из официального `prog-time/tg-support-bot`.

Правильная модель:

```text
origin   — наш fork, куда пушим свои ветки
upstream — https://github.com/prog-time/tg-support-bot.git
```

Наши изменения держать отдельно:

- `app/Modules/PostEditBotBridge/**` — основная интеграция;
- `docker-compose.relaxa.yml` — локальный Docker override без конфликтов с PostEditBot;
- отдельные миграции/настройки;
- минимальные hooks в upstream-файлах.

Перед обновлением:

1) `git status --short` — проверить WIP.
2) `git fetch upstream` — получить официальный код.
3) `git merge upstream/main` или `git rebase upstream/main` — только если рабочее дерево чистое.
4) Проверить `app/Modules/PostEditBotBridge/**`, AI hook, chat UI и настройки.

## PostEditBot Bridge

Назначение: показать оператору карточку клиента и дать AI безопасный контекст.

Источник правды:

- клиенты;
- подписки;
- платежи;
- статусы;
- история доступа.

Источник — только защищённый endpoint PostEditBot:

```text
GET /api/support/client-profile
Authorization: Bearer <bridge-token>
```

Запрещено:

- подключаться к PostgreSQL PostEditBot напрямую;
- дублировать Toolsy-интеграцию внутри tg-support-bot;
- выдумывать подписки/оплаты, если PostEditBot не вернул профиль.

## AI

Режим по умолчанию — `hybrid`.

- `draft` — AI только готовит черновики.
- `hybrid` — AI может отвечать в безопасных случаях.
- `auto` — максимум автоматизации, использовать осторожно.

Для тем оплаты, возвратов, блокировок, восстановления доступа и спорных подписок — эскалация оператору.

## Docker

Для локального запуска рядом с PostEditBot использовать:

```bash
docker compose -f docker-compose.yml -f docker-compose.relaxa.yml up -d --build
```

Проверка логов:

```bash
docker compose -f docker-compose.yml -f docker-compose.relaxa.yml logs -f app queue scheduler
```

## Docs/changelog

При изменении кода:

- обновляй документацию;
- обновляй дату последней редакции в изменённых `.md`;
- добавляй запись в `changelog.md`;
- не редактируй старые записи changelog.

Формат команды в документации:

```text
Что сделать, чтобы применить изменения:
1) `<команда>` — Почему: `<коротко>`
```
