# Последняя редакция: 18.07.2026 01:07 UTC+3

# Качество и эксплуатация

## Проверка локализованных системных сообщений

- Unit/feature-тесты проверяют строгий перевод, английский fallback, отключённые шаблоны и защиту повторной оценки.
- Telegram canary подтверждает `/start`, `/lang`, страницы выбора языка и приветствие.
- VK и Max проверяются только через выделенные тестовые диалоги; реальные клиентские аккаунты для smoke-теста не используются.
- Ошибка фактической доставки формы оценки переводит запись в `delivery_failed`; закрытие обращения при этом сохраняется.
- В лог `system_auto_reply_resolution` записываются тип шаблона, язык и уровень fallback без текста и секретов.

## Контроль pipeline сообщений

- Входящие webhook-логи содержат только технические поля, длину и хеш — без текста, файлов, токенов и полного ответа API.
- `delivery_operations` показывает фактический итог доставки ответа, AI, формы оценки и зеркала.
- `delivery_failed` означает, что форма оценки не дошла; закрытие диалога при этом остаётся сохранённым.
- AI-черновик сохраняется в админке даже тогда, когда Telegram-тема ещё создаётся.
- Для PostgreSQL после миграции отдельно проверяются уникальные индексы `bot_users.identity_key` и `messages.source_event_key`.

**Что сделать, чтобы применить изменения:**

1) `docker compose up -d --build` — Почему: backend, очереди и Docker-сервисы должны получить новый код.
2) `docker compose exec -T app php artisan migrate --force` — Почему: добавить ключи идентичности, тип сообщения и статус доставки.
3) `docker compose exec -T app php artisan auto-replies:translate-system` — Почему: поставить недостающие переводы включённых системных шаблонов в очередь.
4) `docker compose ps` — Почему: проверить состояние `app`, `queue`, `scheduler`, poller-сервисов и `nginx`.
5) `docker compose logs -f app queue scheduler telegram_poller ai_telegram_poller nginx` — Почему: проверить фактическую доставку и ошибки без догадок.

Цель этого документа — дать один понятный чек-лист: как проверить проект и где смотреть состояние.

## Быстрые команды

Добавлен помощник `scripts/project-tools.ps1`.

```powershell
.\scripts\project-tools.ps1 health
```

Показывает состояние Docker-контейнеров.

```powershell
.\scripts\project-tools.ps1 routes
```

Показывает карту Laravel-маршрутов внутри контейнера `app`.

```powershell
.\scripts\project-tools.ps1 graph
```

Обновляет Graphify-граф проекта.

```powershell
.\scripts\project-tools.ps1 quality
```

Запускает основные локальные проверки: Composer, Pint, PHPStan, PHPUnit и frontend build.

## Docker healthcheck

В `docker-compose.yml` добавлены healthcheck для сервисов:

- `app` — проверяет PHP и базовое состояние Laravel;
- `pgdb` — проверяет PostgreSQL через `pg_isready`;
- `nginx` — проверяет конфиг и реальный ответ Laravel `/up` через PHP-FPM;
- `queue` — проверяет PHP/Laravel окружение воркера;
- `scheduler` — проверяет PHP/Laravel окружение планировщика.

Это помогает быстро увидеть, какой контейнер «болеет», без чтения всех логов подряд.

## Защита от 502 Bad Gateway после пересоздания app

В `docker-compose.yml` зависимости переведены на `condition: service_healthy`, а upstream используют встроенный Docker DNS.

Зачем это нужно:

- `nginx` ждёт, пока `app` станет healthy;
- `telegram_poller` и `ai_telegram_poller` ждут healthy `app`, `nginx` и `queue`;
- nginx повторно разрешает адреса `app` и `reverb` каждые 10 секунд, поэтому пересоздание контейнера не оставляет старый IP;
- healthcheck обращается к Laravel `/up`, а не ограничивается формальной проверкой `nginx -t`;
- при отказе внутреннего webhook оба Telegram poller выдерживают паузу и не создают горячий цикл запросов и логов.

Фактическая причина инцидента 14.07.2026: после пересоздания `app` nginx продолжал обращаться к старому IP PHP-FPM. Контейнер считался healthy, потому что прежний healthcheck проверял только синтаксис конфига. Результатом были `502 Bad Gateway` и остановка Telegram offset на одном update.

Регрессия закрыта тестом:

```bash
docker compose run --rm --no-deps -v ${PWD}:/work -w /work app php vendor/bin/phpunit --do-not-cache-result tests/Unit/Infrastructure/DockerComposeNginxDependencyTest.php
```



## Регрессия: poller не должен падать от Telegram timeout

Проблема: при `Connection timed out` или TLS EOF на `deleteWebhook/getUpdates` контейнер `telegram_poller` перезапускался. Пока он перезапускался, клиентские `/start` и callback выбора языка могли не доходить до приложения.

Теперь:

- `getUpdates` и внутренний webhook обёрнуты в retry-safe обработку;
- ошибки логируются без Telegram-токена;
- `/start` для старого клиента снова показывает selector, а не молчит;
- compose запускает основной poller с `--timeout=10`.

Проверка:

```bash
docker compose run --rm -T -v ${PWD}:/var/www app php vendor/bin/phpunit --do-not-cache-result tests/Feature/Commands/TelegramPollUpdatesCommandTest.php tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php --filter="poller|start|language|selector|callback"
```

## Служебный Telegram-диалог каждые 24 часа

Планировщик Laravel запускает команду:

```bash
php artisan telegram:support-flow-check
```

Она проверяет рабочую ветку `/start`, `/lang`, выбор языков и доставку welcome. Отчёт уходит в support-topic служебного клиента.

Ручной запуск:

```bash
docker compose exec -T app php artisan telegram:support-flow-check --chat-id=<служебный_chat_id> --languages=pl,en,ar
```

Если команда падает, сначала смотреть:

```bash
docker compose logs -f app queue scheduler telegram_poller
```

Признаки нормы:

- в `messages` есть selector и welcome с `to_id > 0`;
- в логах есть `telegram_outgoing_bot_mirror_delivered`;
- в служебном topic есть отчёт `Служебная проверка Telegram-flow`.

## Регрессии Telegram/AI доставки

Для срочной проверки Telegram-входящих и Auto AI:

```bash
docker compose run --rm -T -v ${PWD}:/var/www app php vendor/bin/phpunit --do-not-cache-result tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
```

Что покрывают тесты:

- повторный private update не создаёт дубль и не запускает AI второй раз;
- входящее сообщение при включённой группе ставится в очередь только один раз;
- `TOPIC_CLOSED` в support topic не мешает отправить AI-ответ клиенту;
- ответ AI сохраняется в `messages`, чтобы веб-чат видел факт отправки.


## Регрессии автоответов, переменных и полной видимости сообщений

Для проверки KAR-336:

```bash
docker compose run --rm -T -v ${PWD}:/var/www app php vendor/bin/phpunit --do-not-cache-result tests/Unit/Modules/Translation/PlaceholderProtectorTest.php tests/Unit/Modules/Translation/TranslationServiceTest.php tests/Unit/Livewire/Settings/AutoRepliesPageTest.php tests/Unit/Livewire/Settings/AutoReplyFormPageTest.php tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php tests/Feature/Jobs/TranslateAutoReplyJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
```

Что покрывает:

- переменные `{{connector}}` и `{{paybot}}` не превращаются в `__TG_SUPPORT_PH_0__`;
- перевод одного языка ставится в очередь отдельно;
- предпросмотр показывает финальный текст с реальными ссылками;
- `/start` и сообщения до выбора языка сохраняются для Web и support-группы;
- AI-ответ клиенту дополнительно зеркалится в support-группу, если основная публикация через AI-бота не видна или не прошла.

## Что сделать, чтобы применить изменения:

1) `docker compose up -d --build` — Почему: код не примонтирован volume, контейнеры должны получить новый образ.
2) `docker compose exec app php artisan migrate --force` — Почему: применить таблицу переменных автоответов.
3) `docker compose logs -f app queue telegram_poller ai_telegram_poller` — Почему: увидеть ошибки Web, очереди и Telegram-доставки.


## Регрессия: один клиент — одна Telegram forum-тема

Проблема KAR-336: при быстром `/start` могли появиться две темы с одинаковым именем клиента. Причина была в двух независимых путях создания темы:

1. контроллер входящего сообщения ставил `TopicCreateJob`;
2. `SendTelegramMessageJob` тоже ставил `TopicCreateJob` в цепочку, если `topic_id` ещё пустой.

Теперь тему создаёт только job доставки, а `TopicCreateJob` дополнительно берёт lock по `bot_user_id` и перед созданием перечитывает `topic_id`. Если тема уже есть — новая не создаётся.

Проверка:

```bash
docker compose run --rm -T -v ${PWD}:/var/www app php vendor/bin/phpunit --do-not-cache-result tests/Feature/Jobs/TopicCreateJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
```

Что должно быть:

- `TopicCreateJob` не вызывает `createForumTopic`, если у пользователя уже есть `topic_id`;
- два подряд запущенных `TopicCreateJob` для одного клиента создают только одну Telegram forum-тему;
- при включённой Telegram support-группе webhook ставит только `SendTelegramMessageJob`;
- входящее сообщение не сохраняется напрямую и не создаёт второй topic-path.

Что сделать, чтобы применить изменения:

1) `docker compose build app queue telegram_poller ai_telegram_poller && docker compose up -d app queue telegram_poller ai_telegram_poller` — Почему: изменён backend-код Laravel, очередь и poller должны получить новый образ.
2) `docker compose logs -f app queue telegram_poller ai_telegram_poller` — Почему: проверить ошибки приложения, очереди и Telegram-доставки.


## Регрессия: полный Telegram-flow клиента

Проверяем цепочку `/start → выбор языка → контактная информация → welcome → текст клиента` полностью. Важные инварианты:

- `/start` виден в истории для отладки;
- повторный `/start` осознанно показывает новый selector для смены языка;
- одно selector-сообщение принимает только первый callback языка, отключает клавиатуру и не создаёт второй welcome;
- контактная карточка отправляется после выбора языка, чтобы `Выбранный язык` был заполнен;
- outgoing bot-сообщения ждут создания topic, чтобы support-topic не терял bot-сообщения;
- выбор языка отправляет полный системный welcome, а не короткий fallback из конфига;
- `stale` перевод welcome можно показывать, если `ready` ещё не пересобран;
- `TopicCreateJob` только создаёт forum-тему и не отправляет неполную контактную карточку;
- текст клиента сохраняется в одном диалоге;
- исходящие bot-сообщения клиенту зеркалятся в support-topic с префиксом `🤖 Бот клиенту:`;
- успешный ответ отдельного AI-бота не дублируется вторым таким же сообщением от основного бота;
- flow сам не закрывает диалог.

Проверка:

```bash
docker compose run --rm -T -v ${PWD}:/var/www app php vendor/bin/phpunit --do-not-cache-result tests/Feature/Jobs/SendTelegramMessageJobTest.php tests/Feature/Jobs/TopicCreateJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php tests/Unit/Livewire/Chat/ConversationPageTest.php
```

Что сделать, чтобы применить изменения:

1) `docker compose up -d --build` — Почему: изменён backend-код Laravel и Blade-шаблон, контейнеры должны получить новый образ.
2) `docker compose logs -f app queue telegram_poller ai_telegram_poller` — Почему: проверить ошибки приложения, очереди и Telegram-доставки.

## CI-проверки

В GitHub Actions уже есть проверки:

- Dockerfile через Hadolint;
- shell-скрипты через ShellCheck;
- PHPStan;
- markdownlint;
- yamllint;
- PHPUnit;
- Pint;
- Composer validate;
- Composer audit;
- Gitleaks;
- Vite build.

Добавлена отдельная проверка Graphify, чтобы на CI можно было построить карту кода и сохранить её как artifact.

## Frontend-аудит зависимостей

После `npm audit fix` frontend-аудит показывает `found 0 vulnerabilities`.

Обновлены lock-зависимости:

- `concurrently` до `9.2.3`;
- `form-data` до `4.0.6`;
- `shell-quote` до `1.8.4`;
- `vite` до `6.4.3`.

## Что сделать, чтобы применить изменения:

1) `docker compose build app queue telegram_poller ai_telegram_poller && docker compose up -d app queue telegram_poller ai_telegram_poller` — Почему: изменён backend-код Laravel и poller/queue должны получить новый образ.
2) `docker compose logs -f app queue telegram_poller ai_telegram_poller` — Почему: проверить ошибки приложения, очереди и poller-сервисов.




## Проверка realtime pipeline

```powershell
docker compose exec -T app php artisan telegram:pipeline-latency-probe --samples=30 --slo=100
docker compose exec -T queue php artisan horizon:status
```

Полный runbook: [Realtime Telegram pipeline](realtime-telegram-pipeline.md).

Служебная проверка сравнивает welcome после той же plain-text очистки, что применяется при реальной отправке. Это исключает ложные ошибки для переводов со служебной разметкой.

Служебный canary разрешено направлять только в отдельный тестовый Telegram-аккаунт. Команда не меняет имя, username и постоянный язык аккаунта, не удаляет историю и восстанавливает исходный язык после проверки. Личный рабочий аккаунт оператора использовать запрещено.

Текущий выделенный canary-аккаунт: `@relaxa_support`. Проверка включена один раз в сутки, в 00:00 по часовому поясу приложения (`Europe/Moscow`), для `/start`, `/lang` и welcome на PL/EN/AR.

