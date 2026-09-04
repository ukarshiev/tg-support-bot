# Последняя редакция: 05.09.2026 UTC+3

# Качество и эксплуатация

## Надёжность AI-черновиков

- `SendAiDraftJob` сначала создаёт `ai_messages` со статусом `pending` и `message_id=null`. Только после этого отдельная `SendPendingAiDraftToTelegramJob` пытается показать тот же черновик в forum-теме.
- Ошибка Telegram, включая `400 Bad Request: message is too long`, не удаляет и не отменяет черновик: оператор продолжает видеть его в `/admin/chats`, а job доставки может повториться для существующей записи.
- Telegram-текст делится на части до 4096 символов. Каждая часть содержит валидный HTML: открытые теги закрываются перед границей и переоткрываются после неё, HTML-сущности не режутся.
- Inline-кнопки добавляются только к последней части. В `ai_messages.message_id` сохраняется идентификатор этой части, поэтому callback и текстовый reply находят исходный черновик прежним способом.
- `null` или пустой ответ AI-провайдера не создаёт пустой черновик. Событие `send_ai_draft_empty_provider_response` фиксирует факт попытки, `bot_user_id`, платформу и провайдера без текста и секретов.
- Черновик никогда не записывается в `messages`. Эта таблица меняется только при фактической отправке принятого ответа клиенту.
- Регрессионные тесты добавлены, но не запускались по прямому ограничению задачи.

## Безопасность команд в Telegram forum-топиках

- Сообщение менеджера, первый символ которого `/`, считается служебной командой и никогда не передаётся клиенту как ответ поддержки.
- В клиентском топике поддерживаются `/contact`, `/ai_generate`, `/ban` и `/unban`; Telegram-суффикс с именем бота, например `/ban@relaxaclub_support`, распознаётся.
- `/ban` и `/unban` проходят через `BannedContactMessage`, поэтому используют тот же бизнес-процесс, что inline-кнопки, обновляют карточку контакта и показывают в топике явный итог операции.
- Неизвестная slash-команда остаётся внутри топика и вызывает короткую подсказку. Список подсказки строится из того же перечня, по которому контроллер маршрутизирует команды.
- Private-команды клиента не проходят через этот барьер: `/start`, `/lang` и `/language` сохраняют прежнюю обработку.
- Регрессионная проверка должна подтвердить отсутствие `SendTelegramMessageJob` с текстом команды и адресом клиента, корректное состояние `is_banned`, topic-подтверждение, доставку обычного текста и private `/start`.
- Автотесты запускать только через `scripts/run-isolated-tests.ps1`; для этой версии они не запускались по прямому ограничению задачи.

## Устойчивость сборки при сетевых сбоях

- `composer-build` устанавливает `git` и `ca-certificates` только в своём промежуточном слое. Если загрузка dist-архива с GitHub оборвалась, Composer может перейти на source; в runtime эти пакеты не попадают.
- `composer install` выполняется до четырёх раз с паузами 5, 15 и 30 секунд. HTTP- и process timeout увеличены до 600 секунд, чтобы медленный, но работающий TLS-канал не считался немедленным отказом.
- `npm ci` использует те же четыре попытки и паузы. `npm run build` запускается один раз после успешной установки, поскольку сам frontend build сеть не использует.
- Последняя неудачная попытка Composer или npm завершает Docker-сборку ошибкой. Это защита от кратких сетевых обрывов, а не маскировка постоянной недоступности registry/GitHub.
- Пины базовых образов по digest, набор стадий и состав runtime-образа этим механизмом не меняются.

## Сетевой путь до Telegram API

- `TELEGRAM_PROXY` задаёт внешний прокси только для запросов к `api.telegram.org`. Пустое значение сохраняет прямое соединение.
- Поддерживаются HTTP (`http://host.docker.internal:10809`) и SOCKS5 с DNS через прокси (`socks5h://host.docker.internal:10808`).
- Прокси применяется ко всем найденным обращениям к Telegram API: обычным запросам, callback-ответам, загрузке и скачиванию файлов, `getMe`, `deleteWebhook` и `getUpdates` обоих poller.
- Внутренние webhook `http://nginx/...`, PostgreSQL, Redis и другие сервисы Docker идут напрямую. Это защищает внутренний трафик от выхода через внешний прокси.
- `extra_hosts: host.docker.internal:host-gateway` добавлен в `app`, `queue`, `scheduler`, `telegram_poller` и `ai_telegram_poller`, поэтому адрес хоста доступен при пользовательских DNS-серверах Compose.
- Если прокси содержит логин и пароль, transport-логи заменяют credentials на `[hidden]`.

## Эксплуатация логов

- `LOG_CHANNEL=stack` использует ротируемый `LOG_STACK=daily`; `LOG_DAILY_DAYS=7` хранит суточные файлы каналов `daily` и `app` семь дней.
- Планировщик ежедневно в 03:30 запускает `php artisan logs:prune --days=7`. Команда удаляет устаревшие файлы непосредственно из `storage/logs`, но не трогает `.gitignore`, ссылки и каталоги.
- Перед ручной очисткой используйте `php artisan logs:prune --days=7 --dry-run`: команда покажет число файлов и объём в байтах без удаления.
- Для другого срока укажите `--days=N`; значение должно быть целым числом не меньше 1. Laravel-ротация и отдельная команда дополняют друг друга: первая ограничивает суточные серии, вторая подчищает остальные старые файлы в каталоге логов.

## Безопасный порядок выкатки `start.sh`

Релиз выполняется строго в таком порядке:

1. Создать дамп PostgreSQL, убедиться, что он непустой, и вычислить SHA-256.
2. Запомнить текущие образы для rollback и собрать новые образы.
3. Предупредить о паузе и остановить `telegram_poller`, `ai_telegram_poller`, `queue`, `scheduler`.
4. Поднять `pgdb`, `redis`, новый `app`, очистить `bootstrap/cache` и выполнить `migrate --force`. Только миграционной сессии задаются PostgreSQL `lock_timeout=15s` и `statement_timeout=10min`: чужая блокировка быстро прерывает релиз, а тяжёлому штатному DDL остаётся до 10 минут.
5. Выполнить preflight, очистку/сборку Laravel-кэшей, затем поднять `queue`, `reverb`, `scheduler`.
6. Пересоздать `nginx` и проверить базовые сервисы, `artisan about` и Horizon со статусом `running`, пока оба poller ещё остановлены.
7. Только после успешной базовой проверки поднять `telegram_poller` и `ai_telegram_poller`, затем отдельным циклом подтвердить их health.

Пауза безопасна для входящих сообщений: Telegram хранит недоставленные updates до 24 часов, а poller обработают накопленное после запуска. Пока базовая готовность не подтверждена, poller не получают клиентские updates и не могут отправить их в карантин из-за `500` сломанного релиза. Остановка старой очереди до нового `app` не даёт старым worker получить задачи классов, существующих только в новом образе. Таймаут миграции или любая другая ошибка запускают rollback: он возвращает прежние образы и поднимает оба poller, `queue` и `scheduler` даже до запуска миграции.

## Контроль зависших доставок и зеркал

- Каноническое состояние ответа хранится в `delivery_operations`; `messages.delivery_status` — синхронизированное представление для веб-чата.
- Клиентская job переводит `admin-reply` из `pending` в `processing`. Успех переводит операцию и сообщение в `delivered`; окончательная ошибка — в `failed` с причиной.
- Служебное уведомление о недоставке использует отдельный хеш от типа события и исходного `operation_key`, поэтому повторная обработка не создаёт дубль.
- Окончательный провал `telegram-support-topic` для входящего сообщения создаёт отдельное уведомление «Входящее сообщение клиента не отображено в теме» с временем сообщения и типом вложения. Оно не смешивается с событием «Ответ клиенту не доставлен».
- Зеркала исходящих bot-сообщений и контактные карточки не считаются потерянным входящим сообщением и такой сигнал не создают.
- Уведомление допускается только при уже существующем `topic_id`; `TopicCreateJob` из этого потока не запускается.
- Уведомительная операция имеет destination `telegram-topic`, поэтому её собственный провал не может рекурсивно поставить новое уведомление.
- Если worker был убит и `failed()` не выполнился, команда `php artisan delivery:reconcile-admin-replies --minutes=15` помечает старые `processing`-операции клиентской доставки и `telegram-support-topic` проваленными. `--minutes` должен быть не меньше 1.
- Команда изменяет рабочие записи. В production её запускать только после проверки окружения, свежего дампа БД и прямого подтверждения Владыки.
- Для регулярного контроля отслеживать количество `admin-reply` и `telegram-support-topic` в `processing` старше выбранного порога и ошибки `admin_reply_failure_notification_failed`.

## Контроль Telegram HTTP 403 и недоступности клиента

- Только описания с `blocked by the user` и `user is deactivated` помечают клиента недоступным; регистр, лишние пробелы и префикс Telegram не влияют на классификацию.
- `bot was kicked from the supergroup chat`, `bot is not a member of the supergroup chat`, `chat not found` и неизвестные 403 дают error-лог с полным `description`, но не плашку о блокировке клиента.
- Плашка отправляется только в существующий `topic_id`; отсутствие темы не запускает её создание.
- Сигнал без темы хранится в `bot_users.is_unavailable`, `unavailable_reason`, `unavailable_at` и виден в карточке диалога веб-админки.
- Первая успешная доставка клиенту или его новое private-сообщение одним обновлением очищает все три поля.
- После применения миграции проверить: неизвестный 403 не создаёт тему, известный ставит бейдж, успешная доставка/входящее сообщение убирают бейдж.

**Что сделать, чтобы применить изменения:**

1) Сделать свежий production-дамп PostgreSQL.
2) После отдельного подтверждения выполнить `php artisan migrate --force` — миграция добавит три поля в `bot_users`.
3) Перезапустить application/queue-процессы штатным production-процессом.
4) Проверить error-логи по событию `Telegram API returned non-recipient 403` и карточку диалога оператора.

## Проверка локализованных системных сообщений

- Unit/feature-тесты проверяют строгий перевод, английский fallback, отключённые шаблоны и защиту повторной оценки.
- Telegram canary подтверждает `/start`, `/lang`, страницы выбора языка и приветствие.
- VK и Max проверяются только через выделенные тестовые диалоги; реальные клиентские аккаунты для smoke-теста не используются.
- Ошибка фактической доставки формы оценки переводит запись в `delivery_failed`; закрытие обращения при этом сохраняется.
- В лог `system_auto_reply_resolution` записываются тип шаблона, язык и уровень fallback без текста и секретов.

### Ночная проверка Telegram-flow

- Команда `php artisan telegram:support-flow-check` без отдельного списка проходит все языки, включённые в `support.languages` и доступные в Telegram selector. Опция `--languages=pl,en` важнее настройки `telegram.health_check_languages`; непустая настройка важнее общего списка.
- Служебный callback использует `message_id=0` и уникальный `callback_id` для каждого языка. Поэтому canary не пытается очистить клавиатуру у несуществующего сообщения, а повтор одного callback по-прежнему отсекается Cache-lock.
- Ответ Telegram `MESSAGE_TO_EDIT_NOT_FOUND` считается штатным no-op: удалённое или отсутствующее сообщение уже нельзя отредактировать, поэтому job пишет info-лог и завершается без retry и `failed_jobs`.
- Политика клиентской Telegram-доставки хранится в одном месте — `TelegramClientDeliveryRetryPolicy`: 9 попыток и растущие паузы `5+10+20+40+80+160+240+300`. Полное сетевое окно — 927 секунд (15 минут 27 секунд); оно переживает измеренный пятиминутный обрыв без частых запросов к деградирующему Telegram.
- Ожидание одного подтверждения задаётся `--await-timeout` или `telegram.health_check_await_timeout_seconds`, но ограничено 150 секундами. Это отдельный canary-лимит из `TelegramClientDeliveryRetryPolicy`: проверка быстро сигнализирует «не подтверждено за отведённое время», пока реальная доставка продолжает свои повторы в полном 927-секундном окне. Такой timeout не называется окончательным провалом доставки.
- Пауза задаётся `--language-pause` или `telegram.health_check_language_pause_milliseconds`. Дефолт 1100 мс оставляет небольшой запас относительно ограничения Telegram примерно в одно сообщение в секунду для одного чата.
- Общий предел задаётся `--deadline` или `telegram.health_check_deadline_seconds`, но всегда ограничен 3600 секундами. Расчёт по шагам и паузам сохраняется внутри этого потолка; для 24 языков дефолт равен одному часу. После дедлайна новые шаги не запускаются, а оставшиеся языки попадают в отчёт как непроверенные.
- Cache-lock `telegram:support-flow-check:lock` не допускает двух прогонов одновременно и живёт не дольше фактического дедлайна прогона + 60 секунд запаса. Повторный запуск при живом lock завершается успешно с сообщением `another run is still active`.
- Отчёт агрегирует успешные шаги, подробно показывает ошибки и при необходимости делится на части до 4096 символов каждая.
- Для ручной диагностики безопасно сначала запустить команду с узким `--languages`; это production-flow, поэтому фактический запуск требует проверки окружения, свежего дампа и прямого подтверждения Владыки.

## Контроль pipeline сообщений

- Любое private-сообщение Telegram, кроме голых `/start`, `/lang` и `/language`, сначала проходит дедупликацию, сохранение и зеркалирование, даже если клиент ещё не выбрал язык.
- Автоматический селектор языка после такого сообщения отправляется не чаще одного раза в 60 секунд на `bot_user`; атомарный `Cache::add` защищает от двух одновременных selector-job.
- Основной Telegram poller считает детерминированным отказом приложения только HTTP `4xx` и ровно `500`: они повторяются не более трёх раз для одного `update_id`.
- `502`, `503`, `504` и прочие недетерминированные HTTP-отказы nginx/upstream ведут себя как ошибка соединения или timeout: не расходуют лимит и не сдвигают offset, поэтому update повторится после восстановления инфраструктуры.
- Перед сдвигом offset отравленный update обязательно записывается в `discarded_telegram_updates` вместе с payload, HTTP-статусом, числом попыток и временем. Ошибка этой записи не позволяет молча пропустить update.
- Для контроля head-of-line blocking отслеживать `telegram_poller_poisoned_update`, `telegram_poller_internal_webhook_infrastructure_failure` и строки `discarded_telegram_updates`; рост quarantine означает устойчивую ошибку приложения, а рост инфраструктурных событий — недоступность nginx/app.
- Входящие webhook-логи содержат только технические поля, длину и хеш — без текста, файлов, токенов и полного ответа API.
- `delivery_operations` показывает фактический итог доставки ответа, AI, формы оценки и зеркала.
- `delivery_failed` означает, что форма оценки не дошла; закрытие диалога при этом остаётся сохранённым.
- AI-черновик сохраняется в админке даже тогда, когда Telegram-тема ещё создаётся, а после появления `topic_id` отдельная retry-задача доставляет сохранённый черновик в Telegram-помощник.
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
- `scheduler` — проверяет PHP/Laravel окружение планировщика;
- `telegram_poller` — требует heartbeat не старше 90 секунд после успешного `getUpdates` основного бота;
- `ai_telegram_poller` — аналогично проверяет реальную связь AI-бота с Telegram.

Это помогает быстро увидеть, какой контейнер «болеет», без чтения всех логов подряд.

## Защита от 502 Bad Gateway после пересоздания app

В `docker-compose.yml` зависимости переведены на `condition: service_healthy`, а upstream используют встроенный Docker DNS.

Зачем это нужно:

- `nginx` ждёт, пока `app` станет healthy;
- `telegram_poller` и `ai_telegram_poller` ждут healthy `app`, `nginx` и `queue`;
- nginx повторно разрешает адреса `app` и `reverb` каждые 10 секунд, поэтому пересоздание контейнера не оставляет старый IP;
- healthcheck обращается к Laravel `/up`, а не ограничивается формальной проверкой `nginx -t`;
- при отказе внутреннего webhook оба Telegram poller выдерживают паузу и не создают горячий цикл запросов и логов.

## Инцидент 19.07.2026: контейнер running, но Telegram не работает

Основной poller получал `401 Unauthorized`, AI poller — `404 Not Found`, однако Docker показывал оба контейнера как running. Причина — отсутствие проверки реального Telegram API.

Теперь каждый poller:

- проверяет токен через `getMe` до отключения webhook;
- считает `401/404` постоянной ошибкой токена и ждёт замену 60 секунд;
- ограничивает одинаковые error-записи интервалом пять минут;
- обновляет heartbeat только после успешного `getUpdates`;
- становится `unhealthy`, если heartbeat отсутствует или старше 90 секунд.

Проверка без отправки сообщений и записи в БД:

```bash
docker compose ps
docker compose exec -T telegram_poller php artisan telegram:poller-health main --max-age=90
docker compose exec -T ai_telegram_poller php artisan telegram:poller-health ai --max-age=90
```

Фактическая причина инцидента 14.07.2026: после пересоздания `app` nginx продолжал обращаться к старому IP PHP-FPM. Контейнер считался healthy, потому что прежний healthcheck проверял только синтаксис конфига. Результатом были `502 Bad Gateway` и остановка Telegram offset на одном update.

Регрессия закрыта тестом:

```bash
.\scripts\run-isolated-tests.ps1 tests/Unit/Infrastructure/DockerComposeNginxDependencyTest.php
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
.\scripts\run-isolated-tests.ps1 tests/Feature/Commands/TelegramPollUpdatesCommandTest.php tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php --filter="poller|start|language|selector|callback"
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
.\scripts\run-isolated-tests.ps1 tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
```

Что покрывают тесты:

- повторный private update не создаёт дубль и не запускает AI второй раз;
- входящее сообщение при включённой группе ставится в очередь только один раз;
- `TOPIC_CLOSED` в support topic не мешает отправить AI-ответ клиенту;
- ответ AI сохраняется в `messages`, чтобы веб-чат видел факт отправки.


## Регрессии автоответов, переменных и полной видимости сообщений

Для проверки KAR-336:

```bash
.\scripts\run-isolated-tests.ps1 tests/Unit/Modules/Translation/PlaceholderProtectorTest.php tests/Unit/Modules/Translation/TranslationServiceTest.php tests/Unit/Services/AutoReplies/AutoReplyVariableRendererTest.php tests/Unit/Services/AutoReplies/SystemAutoReplyResolverTest.php tests/Unit/Livewire/Settings/AutoRepliesPageTest.php tests/Unit/Livewire/Settings/AutoReplyFormPageTest.php tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php tests/Feature/Jobs/TranslateAutoReplyJobTest.php tests/Feature/Commands/TranslateSystemAutoRepliesTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
```

Что покрывает:

- переменные `{{connector}}` и `{{paybot}}` не передаются переводчику открытым текстом;
- потерянный/дублированный marker, переведённый ключ и остатки XML отклоняются до статуса `ready`;
- повреждённый Yandex-ответ передаётся следующему провайдеру, а старый битый кэш игнорируется;
- команда `auto-replies:translate-system` повторно ставит повреждённые ready-переводы в очередь;
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
.\scripts\run-isolated-tests.ps1 tests/Feature/Jobs/TopicCreateJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php
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
.\scripts\run-isolated-tests.ps1 tests/Feature/Jobs/SendTelegramMessageJobTest.php tests/Feature/Jobs/TopicCreateJobTest.php tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php tests/Unit/Livewire/Chat/ConversationPageTest.php
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
