0.36.2 – 19.07.2026 02:03
- [Критический фикс] (Plane TGSUPBOT-69) SettingsService — после восстановления PostgreSQL настройки AI/DeepSeek больше не могут навсегда остаться скрытыми из-за старого Redis-кэша: время жизни значений и пустых sentinel ограничено пятью минутами; данные DeepSeek восстановлены без повторного ввода и успешно проверены через API.
- [Критическая безопасность] (Plane TGSUPBOT-69) PHPUnit/БД — Тестовый bootstrap теперь fail-closed отклоняет любой Laravel config cache, кроме SQLite `:memory:`; добавлен запуск без сети, production Compose volumes и права записи в репозиторий.
- [Эксплуатация] (Plane TGSUPBOT-69) scripts/run-isolated-tests.ps1, project-tools — Все локальные PHP-тесты направлены через изолированный Docker runner; прямой `docker compose run ... app` запрещён как причина очистки боевой PostgreSQL.
- [Критический фикс] (Plane TGSUPBOT-69) Telegram pollers — Перед polling добавлен `getMe` preflight: отозванные/неверные токены `401/404` больше не маскируются бесконечным циклом `deleteWebhook`, а повторное сообщение об ошибке ограничено одним разом в пять минут.
- [Мониторинг] (Plane TGSUPBOT-69) Docker health — Основной и AI poller публикуют heartbeat только после успешного `getUpdates`; отдельная health-команда и Compose healthcheck показывают `unhealthy`, если реальной связи с Telegram нет.
- [Безопасность] (Plane TGSUPBOT-69) Windows production start — Миграции и смена `APP_KEY` требуют явного подтверждения; перед разрешённой миграцией скрипт обязан создать непустой PostgreSQL dump и не запускает миграции по умолчанию.
- [Фикс] (Plane TGSUPBOT-69 / Linear ID: KAR-319) Перевод истории — Уточнён тип failed-перевода и гарантирована инициализация batch-результата, чтобы retry и fallback всех провайдеров проходили статическую проверку без неопределённого состояния.
- [Проверка] (Plane TGSUPBOT-69) PHPUnit — Добавлены регрессии на валидный токен, `401/404`, transport timeout, свежий/просроченный heartbeat, Docker healthcheck и безопасный Windows-запуск.
- [Восстановление] (Plane TGSUPBOT-69) Production — PostgreSQL восстановлена из подтверждённого дампа после отдельного rollback-бэкапа; через штатные формы заменены только два Telegram-токена, оба `getMe` вернули `200`, все обязательные контейнеры healthy и `/up` отвечает `200`.
- [Проверка] (Plane TGSUPBOT-69) Quality gates — Полный изолированный PHPUnit: 1183 теста и 3468 assertions; PHPStan без ошибок; изменённые PHP-файлы прошли Pint; чистая Docker-сборка frontend и production-образа успешна, npm audit не нашёл уязвимостей.

0.36.1 – 18.07.2026 05:07
- [Фикс] (Plane TGSUPBOT-57 / Linear ID: KAR-319) UI перевода истории — Pending-переводы теперь обновляют экран каждые 3 секунды через локальный `wire:poll.3s`, поэтому retry и batch-перевод не зависают визуально до общего 30-секундного poll.

0.36.0 – 18.07.2026 01:07
- [Новый функционал] (Plane TGSUPBOT-57 / Linear ID: KAR-319) Перевод истории чата — Добавлен batch-job для видимой истории: до 25 сообщений и 5000 символов за пачку, с повторным использованием кэша и fallback на одиночный перевод.
- [Фикс] (Plane TGSUPBOT-57 / Linear ID: KAR-319) Retry перевода — Failed-сообщение больше не переходит в очередь автоматически при открытии/скролле; кнопка `Повторить` перезапускает только выбранное сообщение.
- [Надёжность] (Plane TGSUPBOT-57 / Linear ID: KAR-319) Очередь translation — Для jobs добавлены tries/backoff, lock от параллельного выполнения, статусы `translation_jobs` и безопасное схлопывание дублей `message_translations`.
- [Проверка] (Plane TGSUPBOT-57 / Linear ID: KAR-319) PHPUnit — Пройдены 78 тестов и 216 assertions по ConversationPage, TranslationService, batch-job и странице очереди переводов.
- [Документация] (Plane TGSUPBOT-57 / Linear ID: KAR-319) docs — Обновлены схемы и команды проверки на `vendor/bin/phpunit --do-not-cache-result`, без устаревшего `php artisan test`.

0.35.6 – 18.07.2026 01:00
- [Безопасность] (Plane TGSUPBOT-74) Telegram file proxy — `/api/files/{file_id}` принимает только относительные временные подписи на 15 минут, проверяемые после IP throttle 60/мин; подмена `file_id`, срока или режима выдачи возвращает `403` до обращения к Telegram.
- [Надёжность] (Plane TGSUPBOT-74) FileService — Удалены `die()` и подавление исключений; Telegram ограничен connect timeout 3 с, общим timeout 15 с и размером 20 МБ, файл передаётся через удаляемый временный файл с безопасными кодами `404/413/429/502/504`.
- [Совместимость] (Plane TGSUPBOT-74) External API/Widget/Admin — `file_url` и новый `attachment_urls` содержат подписанные ссылки; старый POST сохранён на один релиз как deprecated и тоже требует подпись.
- [Безопасность] (Plane TGSUPBOT-74) Nginx/headers — Access log отключён только для `/api/files/`, чтобы не сохранять `file_id` и подпись; добавлены `nosniff`, `private, no-store` и `no-referrer`.
- [Проверка] (Plane TGSUPBOT-74) PHPUnit/PHPStan/PostgreSQL/Docker/Trivy — 1157 тестов и 3374 assertions, PHPStan 0, migrate→rollback→migrate PASS, production build PASS, HIGH/CRITICAL 0, Gitleaks 0.

0.35.5 – 16.07.2026 01:33
- [Безопасность] (Plane TGSUPBOT-73) External integrations — Исходящие webhook защищены публичной HTTPS:443 SSRF-политикой, DNS pinning без redirect и HMAC-подписью с бесшовной ротацией current/pending ключей.
- [Безопасность] (Plane TGSUPBOT-73) VK/MAX/External API — Входящие события работают fail-closed с постоянным сравнением секретов и привязкой VK group_id; bearer-токены переведены на SHA-256, one-time reveal, отзыв, срок и 24-часовую ротацию.
- [Безопасность] (Plane TGSUPBOT-73) Widget — Legacy X-Widget-Key отключён; оставлены только короткоживущие X-Widget-Token с привязкой к источнику, клиенту, origin и сроку.
- [Миграция] (Plane TGSUPBOT-73) external_source_access_tokens — Existing plaintext токен backfill-ится без смены значения; добавлены preflight и безопасная команда финализации через 24 часа.
- [Проверка] (Plane TGSUPBOT-73) PHPUnit/PHPStan/PostgreSQL/Docker/Trivy — 1142 теста и 3317 assertions, PHPStan 0, migrate→rollback→migrate и legacy backfill PASS, production build PASS, HIGH/CRITICAL 0.

0.35.4 – 14.07.2026 07:22
- [Критический фикс] (Системные автоответы) TGSUPBOT-70 AutoReplyFormPage — Проверка уникальности теперь учитывает только настоящий системный шаблон со стабильным триггером; старое обычное правило больше не блокирует изменение приветствия ложной ошибкой о дубликате.
- [Миграция] (Совместимость данных) TGSUPBOT-70 auto_replies — Старые записи с системным типом и обычным триггером переводятся в тип «Обычный автоответ» с сохранением текста и переводов.
- [Проверка] (Regression) TGSUPBOT-70 AutoReplyFormPageTest, SystemAutoReplyResolverTest — Зафиксированы успешное редактирование системного шаблона при legacy-записи и безопасная нормализация данных.
- [Документация] (Languages/Operations) docs — Описаны причина конфликта, поведение миграции и команды применения.

0.35.3 – 14.07.2026 05:40
- [Критический фикс] (Telegram Language Selector) SelectLanguage — Одно сообщение выбора языка теперь принимает только первое нажатие, после чего клавиатура отключается; последовательные нажатия арабского и английского больше не создают два приветствия.
- [Проверка] (Regression) SendStartMessageTest — Зафиксированы атомарная блокировка по `message_id` selector-а, сохранение первого выбранного языка и удаление активных кнопок.
- [Документация] (Languages/Operations) docs — Описано одноразовое поведение selector-а и безопасная смена языка через новый `/lang`.

0.35.2 – 14.07.2026 05:02
- [Настройка] (Telegram Canary) routes/console.php — Периодическая проверка служебного диалога `@relaxa_support` переведена с запуска каждые три часа на один запуск в сутки, в 00:00 по часовому поясу приложения.
- [Документация] (Operations) docs — Расписание и пример Laravel Scheduler актуализированы для суточного интервала.

0.35.1 – 14.07.2026 03:57
- [Критический фикс] (Nginx/PHP-FPM) docker/nginx — Upstream `app` и `reverb` переведены на динамическое разрешение через Docker DNS; пересоздание контейнера больше не оставляет nginx со старым IP и не блокирует Telegram webhook ответом 502.
- [Надёжность] (Telegram Pollers) TelegramPollUpdates, AiBotPollUpdates — При отказе внутреннего webhook offset сохраняется, а перед повтором выполняется обязательная пауза вместо горячего цикла запросов и многомегабайтных логов.
- [Мониторинг] (Docker Health) docker-compose.yml — Healthcheck nginx теперь проверяет реальный Laravel `/up` через PHP-FPM, а не только синтаксис nginx-конфига.
- [Проверка] (Regression) DockerComposeNginxDependencyTest, TelegramPollUpdatesCommandTest — Добавлены проверки динамического upstream, сквозного healthcheck и ограничения частоты повторов после ошибки webhook.
- [Документация] (Operations) docs — Зафиксированы причина 502, схема динамического Docker DNS, диагностика и команды проверки.

0.35.0 – 14.07.2026 00:10
- [Архитектура] (Message Pipeline) ingress, delivery_operations, messages — Входящие Telegram, VK и Max получили устойчивые ключи идентичности и событий, структурные типы сообщений и явные статусы доставки без удаления legacy-дублей.
- [Критический фикс] (Telegram Topics) SendTelegramMirrorJob, TopicCreateJob — Устранены отправка в General, гонка создания темы и бесконечное восстановление удалённой темы; support-зеркало всегда использует актуальный `message_thread_id`.
- [Критический фикс] (Operator Replies) SendReplyAction, channel jobs — Ответ оператора подтверждается только после успешного API канала; постоянная ошибка останавливает цепочку и не создаёт ложное «Бот клиенту».
- [Фикс] (Language Selector) Telegram, ConversationPage — Технический выбор языка исключён из зеркала, истории и перевода по структурному признаку с поддержкой старого текстового формата.
- [Надёжность] (AI/Feedback) delivery jobs — AI-ответы, черновики, формы оценки и благодарности сохраняют корректные конечные статусы; иностранному клиенту не отправляется русский fallback.
- [Безопасность] (Observability) webhook и provider logs — Полные payload, тексты, URL с токенами и тела ошибочных API-ответов заменены безопасными структурными метаданными.
- [Проверка] (Regression) PHPUnit, Pint, PHPStan, Composer, Vite, Markdown, Graphify — Добавлены проверки конкурентных дублей, подтверждённой доставки, отсутствующей темы, безопасного перевода и идемпотентной миграции.
- [Документация] (Flow/Operations) docs — Обновлены архитектура, Mermaid-схемы, правила локализации, диагностика и команды применения.

0.34.0 – 11.07.2026 10:19
- [Функционал] (Системные автоответы) TGSUPBOT-70 SystemAutoReplyResolver, AutoReply — Приветствие, закрытие, запрос оценки, благодарность и бан переведены в управляемые системные шаблоны со строгим выбором актуального перевода и английским fallback.
- [Функционал] (Языки каналов) TGSUPBOT-70 Telegram, VK, Max — Добавлен единый выбор языка, нейтральная пагинация и сохранение языка клиента; первое обычное сообщение VK/Max не теряется, а служебные команды не уходят оператору и AI.
- [Функционал] (Клиентская доставка) TGSUPBOT-70 CloseTopic, SendFeedbackForm, HandleFeedbackRating — Закрытие, оценка и блокировка локализованы во всех встроенных каналах; Max получил закрытие, VK/Max — благодарность после оценки.
- [Надёжность] (Feedback) platform jobs — Повторные и чужие callback оценки отклоняются, а фактическая ошибка доставки формы переводит запись в `delivery_failed`, не отменяя закрытие обращения.
- [Совместимость] (Private Channels) LocalizedSystemMessageChannel — Добавлен необязательный локализованный capability-интерфейс без изменения существующего PlatformChannel.
- [Проверка] (Regression) PHPUnit, Vite, Graphify — Пройдено 1103 теста и 3156 проверок, frontend build; граф кода обновлён до 4959 узлов и 10492 связей.
- [Документация] (Languages/Operations) docs/languages-and-translation.md, docs/quality-and-operations.md — Описаны системные шаблоны, fallback, команды перевода, применения и проверки.

0.33.0 – 11.07.2026 09:33
- [Функционал] (Auto Reply Variables) TGSUPBOT-69 AutoReplyVariableRenderer.php, ConversationPage.php, SupportLanguageService.php — В автоответах и welcome добавлены динамические переменные клиента `{id}`, `{email}`, `{first_name}`, `{last_name}`, `{username}`, `{platform}` с подстановкой данных конкретного адресата.
- [Функционал] (Auto Reply UI) TGSUPBOT-69 auto-reply-form-page.blade.php — В редактор русского текста и переводов добавлены кнопки вставки всех клиентских переменных с tooltip для каждого элемента.
- [Надёжность] (Translation Placeholders) TGSUPBOT-69 PlaceholderProtector — Клиентские переменные сохраняются при машинном переводе и раскрываются только перед отправкой; Telegram-профиль кэшируется на час, отсутствующие поля дают пустую строку.
- [Проверка] (Regression) TGSUPBOT-69 AutoReplyVariableRendererTest.php и связанные тесты — Подтверждены глобальные и шесть динамических переменных, preview без клиента, welcome и служебный flow; 22 теста, 98 assertions.
- [Документация] (Languages) docs/languages-and-translation.md — Добавлена таблица синтаксиса и поведения переменных автоответов.

0.32.4 – 11.07.2026 09:13
- [Конфигурация] (Telegram Canary) TGSUPBOT-69 runtime settings — Выделенный аккаунт `@relaxa_support` подключён как служебный target; автоматическая проверка `/start`, `/lang` и welcome PL/EN/AR включена каждые три часа.
- [Проверка] (Live Telegram Flow) TGSUPBOT-69 runtime — Контрольный запуск полностью пройден: selector `/start` и `/lang`, польское, английское и арабское welcome подтверждены реальными Telegram message_id; identity и постоянный язык canary-аккаунта не изменились.
- [Документация] (Operations) docs/quality-and-operations.md — Зафиксирован активный выделенный canary-аккаунт и проверяемый сценарий.

0.32.3 – 11.07.2026 09:01
- [Критический фикс] (AI Operator Language) TGSUPBOT-69 app/Modules/Ai/Services/RussianOperatorTextService.php, SendAiDraftJob.php, SendAiReplyJob.php — Ответ AI теперь проверяется после провайдера: иностранный текст переводится `auto → ru` до блока «Для оператора», а при невозможности перевода не отправляется как русский источник.
- [Проверка] (AI Regression) TGSUPBOT-69 RussianOperatorTextServiceTest.php, SendAiDraftJobTest.php, SendAiReplyJobTest.php — Проверены русский passthrough, французский ответ провайдера, обязательный перевод на русский и безопасный отказ; 12 тестов, 39 assertions.
- [Документация] (AI Languages) system-prompt.md, docs/languages-and-translation.md — Закреплено безусловное правило: внутренний блок оператора всегда русский, клиентская версия переводится отдельно.

0.32.2 – 11.07.2026 08:49
- [Критический фикс] (Telegram Canary Isolation) TGSUPBOT-69 app/Console/Commands/TelegramSupportFlowCheck.php — Служебная проверка больше не подменяет имя, username и язык существующего пользователя, не удаляет его историю и восстанавливает исходный язык после сценария.
- [Восстановление] (Telegram User Data) TGSUPBOT-69 runtime — Автопроверка немедленно отключена, ошибочный личный target очищен; профиль Dan/@azzazzellom и русский язык восстановлены, 20 тестовых сообщений удалены у клиента и в support, тестовые DB-записи удалены.
- [Проверка] (Regression) TGSUPBOT-69 tests/Feature/Commands/TelegramSupportFlowCheckCommandTest.php — Добавлена защита от изменения реальной идентичности и постоянного языка canary-аккаунта.
- [Документация] (Canary Safety) docs/quality-and-operations.md, docs/realtime-telegram-pipeline.md — Зафиксирован запрет использовать личный или клиентский chat_id: нужен отдельный тестовый Telegram-аккаунт.

0.32.1 – 11.07.2026 08:41
- [Фикс] (Telegram Health Check) TGSUPBOT-69 app/Console/Commands/TelegramSupportFlowCheck.php — Служебная проверка сравнивает welcome с фактически отправляемым plain-text после TelegramMarkupSanitizer; польское и арабское приветствия больше не дают ложный FAIL из-за служебной разметки переводов.
- [Конфигурация] (Telegram Canary) TGSUPBOT-69 settings — Трёхчасовая проверка активирована для подтверждённого личного служебного диалога; `/start` пользователя найден в live-БД и pipeline.

0.32.0 – 11.07.2026 07:57
- [Архитектура] (Realtime Pipeline) TGSUPBOT-69 Dockerfile, docker-compose.yml, config/queue.php, config/horizon.php — PostgreSQL polling worker с `--sleep=3` заменён на Redis 7, PhpRedis и Horizon с выделенными supervisor для интерактивных, mirror, AI и translation jobs.
- [Функционал] (Telegram Delivery) TGSUPBOT-69 app/Modules/Telegram/Jobs/SendTelegramMessageJob.php, SendTelegramMirrorJob.php, DeliveryOperation.php — Клиентская доставка отделена от support mirror, добавлена DB-идемпотентность, независимые retry и трассировка этапов pipeline.
- [Функционал] (Admin Realtime) TGSUPBOT-69 laravel/reverb, resources/js/app.js, ConversationMessageCommitted.php — Добавлен приватный WebSocket-канал Reverb; частый `wire:poll.5s` заменён realtime-событиями с reconciliation polling раз в 30 секунд.
- [Диагностика] (SLO) TGSUPBOT-69 TelegramPipelineTrace.php, TelegramPipelineLatencyProbe.php — Добавлены trace_id, миллисекундные события и измеритель p95 ожидания очереди `telegram-interactive`.
- [Документация] (Operations) TGSUPBOT-69 docs/realtime-telegram-pipeline.md — Описаны архитектура, backup, rollback, Redis/Horizon/Reverb, ingress и команды проверки.

0.31.2 – 11.07.2026 05:59
- [Фикс] (Telegram Language Flow) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SelectLanguage.php — Удалена ошибочная блокировка welcome по истории сообщений и двухминутному lock: каждое новое нажатие языка теперь ставит приветствие в очередь, а повторная доставка одного callback по-прежнему отсекается по `callback_query.id`.
- [Проверка] (Telegram Regression) TGSUPBOT-68 / KAR-336 tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Подтверждено, что старое приветствие больше не блокирует новое и два разных нажатия создают два ожидаемых welcome-сообщения.
- [Документация] (Telegram Languages) docs/languages-and-translation.md — Зафиксирована фактическая семантика повторного выбора языка и защиты только от дубля одного callback.

0.31.1 – 11.07.2026 05:50
- [Фикс] (Telegram Poller) TGSUPBOT-68 / KAR-336 app/Console/Commands/TelegramPollUpdates.php, docker-compose.yml — `telegram_poller` больше не завершается от временных `deleteWebhook/getUpdates` timeout/TLS-сбоев, логирует сетевые ошибки без токена и запускается с `--timeout=10` вместо `25`.
- [Фикс] (Telegram Start Flow) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SendStartMessage.php — Повторный `/start` у старых клиентов теперь снова показывает selector языка, а не молчит после уже выбранного языка.
- [Проверка] (Telegram Regression) TGSUPBOT-68 / KAR-336 tests/Feature/Commands/TelegramPollUpdatesCommandTest.php, tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php — Добавлены регрессии на устойчивость poller к transport error и свежий selector после повторного `/start`.
- [Документация] (Telegram Operations) docs/windows-docker.md, docs/languages-and-translation.md, docs/quality-and-operations.md — Описаны причины зависания, retry-safe poller и команды проверки.

0.31.1 – 11.07.2026 02:18
- [Фикс] (AI/Telegram Operator UX) TGSUPBOT-52 / KAR-303 app/Modules/Ai/Jobs/SendAiDraftJob.php, app/Modules/Ai/Jobs/SendAiReplyJob.php — AI теперь всегда генерирует русский источник для оператора, а клиентская версия отдельно переводится на выбранный язык клиента; в Telegram support-topic блок «🇷🇺 Для оператора» больше не становится польским/английским из-за `preferred_language_code` клиента.
- [Проверка] (AI Regression) TGSUPBOT-52 / KAR-303 tests/Unit/Modules/Ai/Jobs/SendAiDraftJobTest.php, tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php — Добавлена регрессия для PL-клиента: AI-запрос получает русский язык, операторский блок остаётся RU, клиентский блок получает PL-перевод.
- [Документация] (Languages/AI) TGSUPBOT-52 / KAR-303 docs/languages-and-translation.md — Уточнено, что правило русского операторского блока действует и в веб-чате, и в Telegram support-topic.
0.31.0 – 11.07.2026 01:55
- [Фикс] (Telegram Callback/Poller) TGSUPBOT-68 / KAR-336 app/Console/Commands/TelegramPollUpdates.php — Long polling основного бота сокращён до 10 секунд, внутренний webhook получил короткие connect/read timeout, чтобы кнопки языка и меню не висели на длинном сетевом ожидании.
- [Фикс] (Telegram Support Mirror) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Jobs/SendTelegramMessageJob.php — Welcome и другие bot-сообщения зеркалируются в support-topic plain-text без parse_mode; добавлены фактические логи успешной и неуспешной доставки mirror.
- [Функционал] (Telegram Language Commands) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Controllers/TelegramBotController.php, app/Modules/Telegram/Actions/SendStartMessage.php — Команды `/lang` и `/language` принудительно показывают selector языка даже клиентам, которые уже выбирали язык.
- [Функционал] (Telegram Health Check) TGSUPBOT-68 / KAR-336 app/Console/Commands/TelegramSupportFlowCheck.php, routes/console.php — Добавлена служебная проверка каждые 3 часа: `/start`, `/lang`, выбор языков и подтверждение welcome через `messages.to_id > 0`, с отчётом в support-topic служебного диалога.
- [Проверка] (Telegram Regression) TGSUPBOT-68 / KAR-336 tests/Feature/Commands/TelegramSupportFlowCheckCommandTest.php, tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Покрыты принудительный `/lang` selector и служебный health-check flow.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md, docs/quality-and-operations.md, system-prompt.md — Описаны служебный диалог, mirror welcome в support-topic, команды проверки и правило системного промпта о проверке Telegram-flow.

0.30.13 – 11.07.2026 01:27
- [Фикс] (Telegram Welcome Delivery) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SelectLanguage.php, app/Modules/Translation/Support/TelegramMarkupSanitizer.php — Welcome после выбора языка теперь отправляется как безопасный plain-text: служебные XML/HTML-теги переводчика `<x ...>` удаляются перед `sendMessage`, а `parse_mode` не передаётся.
- [Фикс] (Telegram Retry Pipeline) TGSUPBOT-68 / KAR-336 app/Jobs/SendMessage/AbstractSendMessageJob.php — Ошибка Telegram `Bad Request: can't parse entities` больше не зацикливает job на `parse_mode=html`: текст очищается от HTML и job повторяется plain-text.
- [Проверка] (Telegram Regression) TGSUPBOT-68 / KAR-336 tests/Unit/Jobs/SendMessage/AbstractSendMessageJobTest.php, tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Добавлены проверки битых `<x>` и `< / x>`-тегов в welcome и fallback-повтора после `MARKDOWN_ERROR`.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Описана plain-text отправка welcome и защита от сломанной разметки переводчика.
0.30.12 – 11.07.2026 01:23
- [Диагностика] (Telegram Language Flow) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SelectLanguage.php — Добавлены структурные логи `telegram_language_flow` для каждого шага выбора языка: callback принят, callback пропущен lock-ом, welcome уже доставлен, welcome ожидает pending-lock или welcome поставлен в очередь.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Описано, как следующий клик по языку проверять по точным логам без догадок.
0.30.11 – 11.07.2026 01:18
- [Фикс] (Telegram Welcome Dedup) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SelectLanguage.php — Быстрые повторные клики по одному языку больше не ставят несколько одинаковых welcome-job до первой доставки: добавлен короткий lock на dispatch приветствия.
- [Проверка] (Telegram Welcome Regression) TGSUPBOT-68 / KAR-336 tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Добавлен тест на два быстрых callback с разными Telegram callback ID: callback закрывается оба раза, а welcome-job создаётся только один.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Описана защита от дублей welcome при быстрых повторных кликах.
0.30.10 – 11.07.2026 01:12
- [Фикс] (Telegram Retry Pipeline) TGSUPBOT-68 / KAR-336 app/Jobs/SendMessage/AbstractSendMessageJob.php — Временные ошибки Telegram API `5xx` теперь не теряют welcome-сообщение молча: job логирует причину и уходит в retry с короткой backoff-задержкой.
- [Проверка] (Telegram Retry Regression) TGSUPBOT-68 / KAR-336 tests/Unit/Jobs/SendMessage/AbstractSendMessageJobTest.php — Добавлен тест, который доказывает release/retry для сетевой ошибки Telegram `500` вместо успешного завершения job.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Описан retry временных Telegram API ошибок после timeout-фикса.
0.30.9 – 11.07.2026 01:06
- [Фикс] (Telegram API Pipeline) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Api/ParserMethods.php — Для `postQuery`, `getQuery` и загрузок файлов добавлены явные `connectTimeout` и `timeout`, чтобы `sendMessage`/`editMessageText` не зависали до убийства очереди и не превращались в `MaxAttemptsExceededException` без исходной ошибки.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Зафиксировано, что обычные Telegram API-запросы должны иметь короткие timeout, иначе welcome может не дойти после выбора языка.
0.30.8 – 11.07.2026 01:00
- [Фикс] (Telegram Callback UX) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/AnswerCallbackQuery.php, app/Modules/Telegram/Actions/SelectLanguage.php, app/Modules/Telegram/Actions/ShowLanguageSelectionPage.php — Callback выбора языка и пагинации теперь закрывается тихим `answerCallbackQuery` без текста `Язык выбран`, чтобы клиент не видел ложную всплывашку вместо приветствия.
- [Фикс] (Telegram Welcome) TGSUPBOT-68 / KAR-336 app/Modules/Telegram/Actions/SelectLanguage.php — Старое недоставленное приветствие с `to_id = 0` больше не считается отправленным и не блокирует новое welcome-сообщение после повторного выбора языка.
- [Проверка] (Telegram Regression) TGSUPBOT-68 / KAR-336 tests/Unit/Modules/Telegram/Actions/AnswerCallbackQueryTest.php, tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php — Добавлены тесты на тихий callback, отсутствие всплывашки, повторную отправку welcome после недоставленной записи и webhook-путь выбора языка.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Уточнено, что callback должен быть тихим, а welcome считается отправленным только после реальной доставки клиенту.
0.30.7 – 11.07.2026 00:37
- [Артефакт] (Plane) TGSUPBOT-68 / KAR-336 — Работа зафиксирована в Plane: добавлены факты диагностики, проверка, деплой и остаточный риск по сети Telegram API.
- [Фикс] (Telegram Language Flow) app/Modules/Telegram/Actions/SelectLanguage.php, app/Modules/Telegram/Actions/ShowLanguageSelectionPage.php — Кнопки выбора языка и пагинации теперь сразу отвечают `answerCallbackQuery`, поэтому Telegram-клиент не зависает с крутилкой после нажатия.
- [Фикс] (Telegram Contact Flow) app/Modules/Telegram/Actions/SelectLanguage.php — Повторная смена языка больше не отправляет новую `КОНТАКТНАЯ ИНФОРМАЦИЯ` в support-тему; карточка уходит только при первом завершении выбора языка.
- [Проверка] (Telegram Regression) tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Добавлены проверки быстрого callback-ответа, повторного выбора языка и отсутствия повторной контактной карточки.
- [Документация] (Telegram Flow) docs/languages-and-translation.md, docs/chat-workspace.md — Описаны быстрый callback-ответ, отсутствие спама контактными карточками и команды проверки.
0.30.6 – 08.07.2026 04:11
- [Фикс] (Telegram Start Flow) KAR-336 app/Modules/Telegram/Actions/SendStartMessage.php, app/Modules/Telegram/Actions/SendLanguageSelectionMessage.php, app/Modules/Telegram/Actions/SelectLanguage.php — `/start` больше не удаляется, повторный `/start` не создаёт второй selector, а повторный callback выбранного языка не создаёт второй welcome.
- [Фикс] (Contact Flow) KAR-336 app/Modules/Telegram/Jobs/TopicCreateJob.php, app/Modules/Telegram/Actions/SendContactMessage.php — `TopicCreateJob` теперь только создаёт topic; контактная карточка отправляется после выбора языка и содержит заполненный выбранный язык.
- [Фикс] (Message Persistence) KAR-336 app/Modules/Telegram/Jobs/SendTelegramMessageJob.php — Входящий `/start` сохраняется один раз даже при ошибке доставки в support-topic, а служебная смена иконки topic пропускается для стартового flow, чтобы не засорять Telegram.
- [Фикс] (Web Chat) KAR-336 resources/views/livewire/chat/conversation-page.blade.php — Контактная карточка в Web показывается после selector и только когда язык уже выбран.
- [Проверка] (Start Flow Regression) KAR-336 tests/Feature/Jobs/SendTelegramMessageJobTest.php, tests/Feature/Jobs/TopicCreateJobTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php, tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php, tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлены регрессии на отсутствие дублей selector/welcome/contact, сохранение `/start` при ошибке Telegram и правильный Web-порядок.
0.30.5 – 08.07.2026 03:02
- [Фикс] (Telegram Flow) KAR-336 app/Modules/Telegram/Controllers/TelegramBotController.php, app/Modules/Telegram/Jobs/SendTelegramMessageJob.php — `/start` теперь ставится в очередь раньше выбора языка, а outgoing bot-сообщения ждут создания topic, чтобы порядок нового диалога был `/start → КОНТАКТНАЯ ИНФОРМАЦИЯ → выбор языка → welcome`.
- [Фикс] (AI Visibility) KAR-336 app/Modules/Ai/Jobs/SendAiReplyJob.php — Убран дубль AI-ответа в support-topic: если отдельный AI-бот успешно написал ответ, основной бот не отправляет второй такой же mirror; fallback сохраняется при ошибке или отсутствии AI-бота.
- [Фикс] (Web Chat) KAR-336 resources/views/livewire/chat/conversation-page.blade.php, resources/views/livewire/chat/partials/contact-summary-card.blade.php — Контактная карточка в Web-ленте отображается после `/start`, а не принудительно сверху; для старых диалогов без `/start` остаётся fallback-показ карточки.
- [Проверка] (Telegram/Web Regression) KAR-336 tests/Feature/Jobs/SendTelegramMessageJobTest.php, tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php, tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлены регрессии на порядок `/start`, ожидание topic перед bot-сообщением, отсутствие дубля AI и позицию контактной карточки в Web.

0.30.4 – 08.07.2026 01:47
- [Фикс] (Telegram Visibility) KAR-336 app/Modules/Telegram/Jobs/SendTelegramMessageJob.php — Исходящие bot-сообщения клиенту теперь дополнительно зеркалятся в Telegram support-topic с префиксом `🤖 Бот клиенту:`, чтобы выбор языка, приветствие, автоответы и другие bot-сообщения были видны не только клиенту и web, но и в группе поддержки.
- [Проверка] (Telegram Visibility) KAR-336 tests/Feature/Jobs/SendTelegramMessageJobTest.php — Добавлен регрессионный тест, который проверяет два факта одновременно: отправку bot-сообщения клиенту и mirror этого же текста в support-topic.

0.30.3 – 08.07.2026 01:31
- [Фикс] (Telegram Welcome) KAR-336 app/Modules/Telegram/Services/SupportLanguageService.php — Приветствие после выбора языка теперь приоритетно берётся из системного автоответа `__system_welcome__`, а `stale` перевод используется как рабочий fallback вместо короткой фразы из конфига.
- [Фикс] (Telegram Contact) KAR-336 app/Modules/Telegram/Jobs/TopicCreateJob.php, app/Modules/Telegram/Actions/SendContactMessage.php — Контактная карточка `КОНТАКТНАЯ ИНФОРМАЦИЯ` отправляется синхронно сразу после создания forum-темы; ошибка доставки логируется, чтобы карточка не терялась молча.
- [Фикс] (Dialog State) KAR-336 app/Models/BotUser.php — `is_closed`, `is_banned`, `closed_at`, `banned_at` приведены к корректным cast-типам, чтобы тесты и UI одинаково видели открытость диалога.
- [Проверка] (Full Flow) KAR-336 tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php, tests/Feature/Jobs/TopicCreateJobTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php — Добавлены регрессии на `/start → выбор языка → текст`, полный welcome вместо короткого fallback, контактную карточку в topic, отсутствие самозакрытия и видимость сообщений.

0.30.2 – 07.07.2026 22:04
- [Проверка] (Telegram Topics) KAR-336 tests/Feature/Jobs/TopicCreateJobTest.php — Добавлен жёсткий регрессионный тест на сценарий двух `TopicCreateJob` для одного клиента: второй job не должен вызывать `createForumTopic`, если первый уже записал `topic_id`.

0.30.1 – 07.07.2026 21:35
- [Фикс] (Telegram Topics) KAR-336 app/Modules/Telegram/Controllers/TelegramBotController.php — Убрана лишняя постановка `TopicCreateJob` из входящего private-flow: тему теперь создаёт только `SendTelegramMessageJob` через одну цепочку, поэтому один клиент не получает две forum-темы при быстром `/start` или повторном update.
- [Фикс] (Telegram Topics) KAR-336 app/Modules/Telegram/Jobs/TopicCreateJob.php — Добавлен lock и повторная проверка `topic_id` перед `createForumTopic`, чтобы параллельные job не могли создать дубликат темы для одного `bot_user_id`.
- [Проверка] (Telegram Topics) KAR-336 tests/Feature/Jobs/TopicCreateJobTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php — Добавлены и обновлены регрессии на пропуск создания при существующем `topic_id` и на единственный queue-path для входящего сообщения при включённой support-группе.

0.30.0 – 07.07.2026 15:25
- [Новый функционал] (Auto Replies) KAR-336 app/Livewire/Settings/AutoRepliesPage.php, app/Models/AutoReplyVariable.php, database/migrations/2026_07_07_150000_create_auto_reply_variables_table.php — В `/admin/settings/auto-replies` добавлена вкладка `Переменные` с CRUD-хранением `{{connector}}`, `{{paybot}}` и других значений для безопасного повторного использования в автоответах.
- [Новый функционал] (Translation UX) KAR-336 app/Livewire/Settings/AutoReplyFormPage.php, resources/views/livewire/settings/auto-reply-form-page.blade.php — В поля `Текст ответа` и `Текст выбранного языка` добавлены кнопки вставки переменных, предпросмотр `Проверить перевод` и отдельная кнопка `Перевести этот язык` через очередь.
- [Фикс] (Translation Core) KAR-336 app/Modules/Translation/Support/PlaceholderProtector.php — Технические плейсхолдеры `__TG_SUPPORT_PH_*__` заменены на XML-защиту `<x>...</x>` по best practice DeepL, чтобы переводчик не ломал ссылки, упоминания и `{{variables}}`.
- [Фикс] (Telegram/AI Visibility) KAR-336 app/Modules/Telegram/Controllers/TelegramBotController.php, app/Modules/Ai/Jobs/SendAiReplyJob.php — `/start` и сообщения до выбора языка теперь сохраняются/зеркалятся для отладки, а AI-ответ клиенту дополнительно попадает в support-группу через mirror fallback.
- [Проверка] (Regression) KAR-336 tests/Unit/Modules/Translation/PlaceholderProtectorTest.php, tests/Unit/Livewire/Settings/AutoRepliesPageTest.php, tests/Unit/Livewire/Settings/AutoReplyFormPageTest.php, tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php — Добавлены тесты на переменные, безопасный перевод, перевод одного языка, preview и полную видимость `/start`/AI-сообщений.
- [Документация] (Languages/Ops) KAR-336 docs/languages-and-translation.md, docs/quality-and-operations.md — Описаны переменные автоответов, схема защиты перевода, команды применения и регрессионные проверки.
0.29.1 – 07.07.2026 14:46
- [Фикс] (Telegram) KAR-335 app/Modules/Telegram/Controllers/TelegramBotController.php — Повторный private update с тем же `chat_id` и Telegram `message_id` теперь пропускается через cache/idempotency guard, поэтому одно входящее сообщение не создаёт дубль в support-группе, веб-чате и AI-очереди.
- [Фикс] (AI Delivery) KAR-335 app/Modules/Ai/Jobs/SendAiReplyJob.php — Ошибка Telegram `TOPIC_CLOSED` при публикации AI-ответа в support topic больше не обрывает job: AI-запись сохраняется, ошибка логируется, а ответ клиенту отправляется дальше.
- [Проверка] (Telegram/AI) KAR-335 tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php, tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php — Добавлены регрессии на повторный Telegram update при group ON/OFF и на доставку AI-ответа клиенту при закрытой теме.
- [Документация] (Operations) KAR-335 docs/chat-workspace.md, docs/quality-and-operations.md — Описаны защита от дублей, fallback при `TOPIC_CLOSED` и точечная команда проверки.

0.29.0 – 07.07.2026 14:44
- [Новый функционал] (Support RAG) KAR-306 app/Modules/Ai/Support/SupportRagSearchService.php, app/Models/AiSupportKnowledgeChunk.php — Гибридный поиск теперь явно разделяет пригодный RU canonical, оригиналы и legacy-поля: RU используется как главный сигнал только при статусе `translated` или `manual_edited`, а плохой/упавший RU не может подмешать нерелевантный кейс.
- [Улучшение] (AI Instruction) KAR-306 app/Modules/Ai/Support/AiSupportContextService.php — Для support-кейсов без ручной `ai_instruction` добавлена безопасная инструкция по умолчанию, чтобы AI воспринимал клиент-оператор кейс как пример и не обещал скидки, возвраты, продления, доступы или ручные действия.
- [Проверка] (Support RAG) KAR-306 tests/Unit/Modules/Ai/Support/AiSupportRagTest.php, app/Modules/Ai/Support/SupportEvaluationService.php — Evaluation и unit-тесты теперь проверяют RU canonical, original fallback, инструкцию AI и защиту от плохого RU-перевода.
- [Документация] (AI Knowledge) KAR-306 docs/ai-knowledge.md — Описаны правила гибридного поиска, статусы пригодного RU canonical, безопасная инструкция по умолчанию и расширенный evaluation-набор.

0.28.7 – 07.07.2026 14:32
- [Фикс] (Docker Compose) docker-compose.yml — `nginx`, poller-сервисы, очередь и приложение переведены на `depends_on.condition=service_healthy`, чтобы nginx не стартовал раньше `app` и не ловил `host not found in upstream "app"` с последующим 502.
- [Проверка] (Docker Compose) tests/Unit/Infrastructure/DockerComposeNginxDependencyTest.php — Добавлена регрессия на обязательное ожидание healthy `app` для nginx и healthy `app/nginx/queue` для Telegram poller-сервисов.
- [Документация] (Operations) docs/quality-and-operations.md — Описана причина 502 Bad Gateway после пересоздания `app`, новый порядок старта и команда точечной проверки compose-регрессии.

0.28.6 – 07.07.2026 12:35
- [Фикс] (AI Safety) KAR-328 system-prompt.md, ai.system_prompt — В системный промпт добавлен запрет создавать новые учётные записи, менять `admin@relaxa.club` и использовать для тестов любые учётки кроме `playwright-admin@example.test` и `test@example.com`.
- [Фикс] (Local Test Access) .env, .local-support-credentials.txt — Пароли тестовых учёток `playwright-admin@example.test` и `test@example.com` обновлены в live-БД и сохранены только в локальных ignored-файлах, без попадания секретов в git.
- [Документация] (Agent Rules) AGENTS.md, agent.md, docs/ai-knowledge.md — Для агентов зафиксировано, где брать тестовые доступы, почему нельзя менять боевого админа и почему нельзя создавать новые учётные записи без прямой команды.

0.28.5 – 07.07.2026 11:53
- [Фикс] (AI Safety) KAR-328 app/Modules/Ai/Services/ShouldAiReply.php, app/Modules/Telegram/Controllers/TelegramBotController.php — Добавлен кодовый guard: финансовые, подписочные, доступные и жалобные темы при включённом Auto AI больше не отправляются клиенту напрямую, а переводятся в AI-черновик для оператора.
- [Фикс] (AI Safety) KAR-328 app/Modules/Vk/Services/VkMessageService.php, app/Modules/Max/Services/MaxMessageService.php — Такой же draft-only guard применён к внешним каналам VK и Max, чтобы автоответ не обещал компенсации, возвраты, продления или доступы.
- [Проверка] (AI Safety) KAR-328 tests/Unit/Modules/Ai/Services/ShouldAiReplyTest.php — Добавлены регрессии на жалобу `down for most of my subscription`, русскую претензию по возврату/доступу и обычный вопрос без принудительного черновика.
- [Документация] (AI Knowledge) KAR-328 docs/ai-knowledge.md — Описан кодовый guard, схема маршрутизации и команды применения через пересборку Docker-образов.

0.28.4 – 07.07.2026 03:15
- [Фикс] (Dark Theme) KAR-305 resources/css/app.css, resources/js/app.js — Livewire error iframe после долгого простоя теперь принудительно получает тёмный фон, чтобы в `/admin/chats` не появлялся большой белый блок поверх чата.
- [Документация] (Chat Workspace) KAR-305 docs/chat-workspace.md — Описана защита от светлого системного окна Livewire и команды применения для пересборки assets.

0.28.3 – 07.07.2026 03:12
- [Фикс] (Provider Keys) KAR-288 app/Livewire/Settings/LanguageSettingsPage.php — Кнопки `Проверить перевод` и `Сохранить провайдеры` теперь передают текущие значения Yandex/Google API key прямо в серверный метод, чтобы ключ не терялся из-за stale-снимка Livewire.
- [Фикс] (Settings Cache) KAR-288 app/Services/Settings/SettingsService.php — Секретные настройки больше не доверяют пустому stale-кэшу: если в БД лежит ключ, сервис перечитывает его и не отдаёт старое пустое значение провайдерам перевода.
- [Проверка] (Language Settings) KAR-288 tests/Feature/Settings/LanguageSettingsPageTest.php — Добавлена регрессия на чтение секретного Google key из БД при пустом stale-кэше.
- [Документация] (Languages) KAR-288 docs/languages-and-translation.md — Уточнён сценарий сохранения Google key перед проверкой и команда восстановления прав Laravel cache.

0.28.2 – 07.07.2026 02:40
- [Фикс] (Provider Keys) KAR-288 app/Livewire/Settings/LanguageSettingsPage.php — Перед ручной проверкой перевода теперь сохраняются непустые ключи из полей Yandex/Google, чтобы сценарий `вставил Google API key → нажал Проверить перевод через Google` не падал с ошибкой `Google Translate не настроен` из-за пустой сохранённой настройки.
- [Проверка] (Language Settings) KAR-288 tests/Feature/Settings/LanguageSettingsPageTest.php — Добавлена регрессия, что проверка Google сначала сохраняет введённый ключ, а затем вызывает Google provider.
- [Документация] (Languages) KAR-288 docs/languages-and-translation.md — Уточнено, что кнопка проверки перевода сохраняет введённые ключи перед вызовом провайдера.

0.28.1 – 06.07.2026 17:11
- [Фикс] (Provider Keys) KAR-288 app/Livewire/Settings/LanguageSettingsPage.php — Пустые поля `Yandex API key` и `Google API key` больше не перезаписывают сохранённые ключи, чтобы браузерная перерисовка или пустой password/input не стирали рабочий Google key после сохранения.
- [Улучшение] (Language Settings UI) KAR-288 resources/views/livewire/settings/language-settings-page.blade.php — Поля ключей переведены на видимый `text`-ввод с live-синхронизацией, чтобы администратор видел текущий ключ и введённое значение успевало попасть в Livewire до нажатия `Сохранить провайдеры`.
- [Проверка] (Language Settings) KAR-288 tests/Feature/Settings/LanguageSettingsPageTest.php — Добавлена регрессия, что пустая отправка полей ключей не стирает уже сохранённые Yandex/Google API keys.
- [Документация] (Languages) KAR-288 docs/languages-and-translation.md — Уточнена защита от случайного удаления ключей при сохранении провайдеров.

0.28.0 – 06.07.2026 17:00
- [Новый функционал] (Language Settings UI) KAR-288 resources/views/livewire/settings/language-settings-page.blade.php — На вкладке провайдеров добавлены радиокнопки выбора провайдера для ручной проверки перевода; кнопка `Проверить перевод` теперь явно работает через выбранный Google/Yandex/Offline, а не через общий fallback-порядок.
- [Улучшение] (Translation) KAR-288 app/Modules/Translation/Services/TranslationService.php — Добавлен прямой вызов выбранного провайдера без чтения старого общего кэша и без переключения на следующий fallback, чтобы тест Google не показывал результат `[yandex]`.
- [Улучшение] (Provider Keys) KAR-288 app/Livewire/Settings/LanguageSettingsPage.php — Поля `Yandex API key` и `Google API key` теперь заполняются текущими сохранёнными значениями и не очищаются после сохранения, чтобы администратор видел, какой ключ используется.
- [Проверка] (Language Settings) KAR-288 tests/Feature/Settings/LanguageSettingsPageTest.php — Добавлены регрессии на выбор провайдера проверки без fallback/cache и на загрузку/сохранение ключей провайдеров.
- [Документация] (Languages) KAR-288 docs/languages-and-translation.md — Описано разделение боевого fallback-порядка и радиокнопки провайдера для теста, а также команды применения для Docker-образов.

0.27.4 – 06.07.2026 16:34
- [Фикс] (Translation) app/Modules/Translation/Providers/YandexTranslationProvider.php — Для исходного языка `auto` Yandex больше не получает `sourceLanguageCode=auto`; поле пропускается, чтобы API сам определял язык и не возвращал 400 при backfill Support RAG.
- [Проверка] (Translation) tests/Unit/Modules/Translation/YandexTranslationProviderTest.php — Добавлена регрессия, что запрос в Yandex с `sourceLocale=auto` не содержит `sourceLanguageCode`.
- [Документация] (Languages) docs/languages-and-translation.md — Описано правило обработки `auto` для Yandex и причина для Support RAG backfill.

0.27.3 – 06.07.2026 16:32
- [Фикс] (AI Safety) KAR-328 system-prompt.md, ai.system_prompt — В системный промпт добавлен жёсткий запрет на самостоятельное решение финансовых жалоб, компенсаций, подписок, доступов, возвратов и бонусных дней; такие обращения AI должен передавать специалисту и не обещать действий, которые не может выполнить.
- [Документация] (AI Knowledge) KAR-328 docs/ai-knowledge.md — Описано правило эскалации финансовых и подписочных вопросов, чтобы база знаний не воспринималась как разрешение на выдачу доступов или компенсаций.

0.27.2 – 05.07.2026 22:45
- [Фикс] (Telegram Bot UI) KAR-288 app/Modules/Telegram/Services/SupportLanguageService.php, app/Modules/Telegram/Actions/ShowLanguageSelectionPage.php — Выбор языка в боте переведён на одно сообщение с пагинацией: `Далее →` и `← Назад` редактируют текущую inline-клавиатуру, а не отправляют второй список языков.
- [Фикс] (Telegram Bot Callback) KAR-288 app/Modules/Telegram/Controllers/TelegramBotController.php — Callback `select_language_page:<page>` отделён от выбора языка `select_language:<code>`, чтобы навигация не пыталась сохранить язык.
- [Проверка] (Telegram Bot UI) KAR-288 tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php — Добавлена регрессия на первую страницу, переход на вторую страницу через `editMessageText` и сохранение выбранного языка.
- [Документация] (Languages) KAR-288 docs/languages-and-translation.md — Описан Telegram UI выбора языка, callbacks пагинации и команды применения для `telegram_poller`.
0.27.1 – 05.07.2026 22:27
- [Фикс] (Language Settings) app/Livewire/Settings/LanguageSettingsPage.php — Убран несовместимый третий аргумент `Collection::slice`; страница языков снова открывается без 500-ошибки.
- [Фикс] (Languages) app/Modules/Translation/Services/SupportLanguageSettings.php — Новые fallback-языки теперь домерживаются выключенными, чтобы массовый перевод автоответов не ставил лишние языки в очередь.
- [Фикс] (Admin Chats) tests/Feature/Admin/ConversationWorkspaceTest.php — Регрессия мобильного переключателя перевода теперь задаёт реально выбранный нерусский язык с датой выбора, не конфликтуя со сценарием `Не выбран`.
- [Документация] (Languages/Admin Chats) docs/languages-and-translation.md, docs/chat-workspace.md — Уточнено, что новые fallback-языки добавляются выключенными и что переводчик истории опирается на реально выбранный язык чата.
0.27.0 – 05.07.2026 22:20
- [Новый функционал] (Languages) config/support_languages.php — Добавлены 10 новых языков: `pt`, `ar`, `zh`, `hi`, `id`, `vi`, `fa`, `ko`, `ja`, `nl`; общий список вырос до 24 языков.
- [Улучшение] (Languages) config/support_languages.php — Порядок языков согласован по охвату аудитории: `ru` и `en` закреплены сверху, далее идут крупные мировые языки.
- [Улучшение] (Language Settings UI) app/Livewire/Settings/LanguageSettingsPage.php, resources/views/livewire/settings/language-settings-page.blade.php — Добавлена пагинация списка языков по 14 элементов: страница 1 для топовых языков, страница 2 для остальных.
- [Фикс] (Language Settings) app/Modules/Translation/Services/SupportLanguageSettings.php — Новые fallback-языки автоматически домерживаются в текущую настройку `support.languages`, не удаляя уже существующие языки.
- [Проверка] (Language Settings) tests/Feature/Settings/LanguageSettingsPageTest.php — Добавлена регрессия на две страницы языков и согласованный порядок кодов.
- [Документация] (Languages) docs/languages-and-translation.md — Описаны 24 языка, пагинация и порядок первой/второй страницы.
0.26.2 – 04.07.2026 06:45
- [Фикс] (Admin Chats UI) KAR-319 app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — Если клиент ещё не выбрал язык, переводчик в шапке показывает `Не выбран` и не подставляет первый/старый язык из списка.
- [Проверка] (Admin Chats) KAR-319 tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлена регрессия на чат без выбранного языка: перевод истории не запускается, состояние остаётся пустым.
- [Документация] (Admin Chats) KAR-319 docs/chat-workspace.md — Описано состояние `Не выбран` для переводчика истории.
0.26.1 – 04.07.2026 06:41
- [Фикс] (Admin Chats UI) KAR-319 resources/views/livewire/chat/conversation-page.blade.php — Из dropdown языка в шапке убран суффикс `ON`; выбранный язык сам означает активный перевод, поэтому кнопка стала короче и понятнее на мобильном.
- [Улучшение] (Admin Chats UI) KAR-319 resources/views/livewire/chat/conversation-page.blade.php — Подписи двухъязычных зон унифицированы до `RU` и `Выбранный язык`, без `Для оператора`, `Для клиента`, иконок и кодов языка в карточках сообщений.
- [Проверка] (Admin Chats) KAR-319 tests/Unit/Livewire/Chat/ConversationPageTest.php — Обновлены регрессии под новый короткий текст dropdown и новые подписи зон.
- [Документация] (Admin Chats) KAR-319 docs/chat-workspace.md — Уточнено, что `ON` больше не показывается, а зоны называются `RU` и `Выбранный язык`.
0.25.3 – 04.07.2026 06:38
- [Фикс] (AI Drafts) KAR-319 app/Livewire/Chat/ConversationPage.php — Для pending AI-черновиков добавлено восстановление русского слоя: если поле источника совпадает с клиентским нерусским текстом, система переводит его в `RU` для операторской зоны.
- [Улучшение] (Admin Chats UI) KAR-319 resources/views/livewire/chat/conversation-page.blade.php — Подписи двухъязычных зон упрощены до `RU` и кода выбранного языка (`EN`, `TR`, `ES`), без формулировок `Для оператора` и `Клиенту`.
- [Проверка] (Admin Chats) KAR-319 tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлена регрессия на AI-черновик, где английский клиентский текст восстанавливается в русский слой для оператора.
- [Документация] (Admin Chats) KAR-319 docs/chat-workspace.md — Описано, что AI-черновики показываются как `RU` + выбранный язык клиента и русский слой восстанавливается при необходимости.
0.25.2 – 04.07.2026 06:34
- [Фикс] (Admin Chats Mobile UI) KAR-319 resources/views/livewire/chat/conversation-page.blade.php — Dropdown перевода диалога перенесён из нижней панели composer в верхнюю шапку чата рядом с меню действий, чтобы на мобильной версии кнопка не сжимала поле ввода и не ломала отправку.
- [Документация] (Admin Chats) KAR-319 docs/chat-workspace.md — Уточнено новое место переводчика и причина переноса из нижней панели.
0.26.0 – 04.07.2026 06:11
- [Новый функционал] (Support RAG) KAR-306 database/migrations/2026_07_04_060000_extend_ai_support_knowledge_chunks_for_ru_canonical.php, app/Models/AiSupportKnowledgeChunk.php — Добавлен русский canonical-слой support-кейса: оригиналы клиента/оператора, RU-поля, инструкция AI, статусы перевода, провайдеры, ошибки, даты и защита ручных правок.
- [Новый функционал] (Translation Queue) KAR-306 app/Jobs/TranslateSupportCaseJob.php, app/Modules/Ai/Support/SupportCaseCanonicalizerService.php, app/Console/Commands/AiSupportCanonicalize.php — Добавлены job и artisan-команда для одиночного и массового backfill RU canonical через существующий translation core и очередь переводов.
- [Улучшение] (AI Knowledge UI) KAR-306 app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — В карточке support-кейса показаны оригинал, RU canonical, статусы, ошибки, инструкция AI и preview `Что увидит AI`; добавлены кнопки перевода одного кейса и всех кейсов.
- [Улучшение] (Support RAG) KAR-306 app/Modules/Ai/Support/SupportRagSearchService.php, app/Modules/Ai/Support/AiSupportContextService.php — Поиск стал гибридным: основной сигнал идёт по RU canonical, fallback — по оригиналу и keywords; AI-контекст содержит RU, оригинал и инструкцию AI.
- [Проверка] (Support RAG) KAR-306 tests/Unit/Modules/Ai/Support/AiSupportRagTest.php, tests/Feature/Settings/AiKnowledgePageTest.php — Добавлены регрессии на RU canonical, защиту ручных правок, failed-перевод, UI-сохранение инструкции и постановку support-кейсов в очередь.
- [Документация] (Support RAG) KAR-306 docs/ai-knowledge.md, docs/languages-and-translation.md — Описаны RU canonical слой, гибридный RAG, очередь переводов support-кейсов и команды применения.
0.25.1 – 04.07.2026 06:10
- [Фикс] (Admin Chats) KAR-319 app/Livewire/Chat/ConversationPage.php — При переключении диалога очищаются черновик, предпросмотр перевода, ошибка, файл и состояние переводчика, чтобы сообщение прошлого клиента нельзя было отправить в новый чат.
- [Фикс] (Admin Chats) KAR-319 app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — Dropdown переводчика теперь берёт язык из текущего чата: свежий `preferred_language_code` важнее старого ручного `chat_translation_locale`, а панель принудительно перерисовывается через `wire:key`.
- [Фикс] (History Translation) KAR-319 resources/views/livewire/chat/conversation-page.blade.php — Для исходящих автоответов/AI зона `Оператор` предпочитает восстановленный `system_to_operator`, поэтому турецкий клиентский текст больше не подписывается как русский операторский слой.
- [Проверка] (Admin Chats) KAR-319 tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлены регрессии на сброс composer при смене чата, защиту от stale-языка и приоритет русского слоя истории для исходящих сообщений.
- [Документация] (Admin Chats) KAR-319 docs/chat-workspace.md, docs/languages-and-translation.md — Описаны правила сброса нижней панели и выбор правильного русского слоя для истории.
0.25.0 – 04.07.2026 05:50
- [Новый функционал] (Admin Chats) KAR-319 app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — Добавлен переводчик всей истории текущего диалога: dropdown языка рядом со скрепкой, автозапуск для нерусских чатов, мобильный режим `Оба / Русский / Клиент`, skeleton и `Повторить` для ошибки одного сообщения.
- [Новый функционал] (Translation Queue) KAR-319 app/Jobs/TranslateMessageHistoryJob.php, app/Models/TranslationJob.php, database/migrations/2026_07_04_010000_extend_chat_history_translations.php — Добавлена очередь перевода истории с записями `translation_jobs`, сохранением языка перевода в контексте чата, статусами `queued/running/ready/failed` и ошибкой на уровне сообщения.
- [Проверка] (Admin Chats) KAR-319 tests/Unit/Livewire/Chat/ConversationPageTest.php — Добавлены регрессии на флаг/dropdown языка, отсутствие перевода для `ru`, автозапуск для нерусского языка, ручную смену только текущего чата и retry одного сообщения.
- [Документация] (Translation) KAR-319 docs/chat-workspace.md, docs/languages-and-translation.md — Описаны UX перевода истории, схема очереди, направления переводов и команды применения.
0.24.1 – 04.07.2026 04:02
- [Фикс] (AI Knowledge UI) KAR-305 resources/views/livewire/settings/ai-knowledge-page.blade.php — Убрана палитра тёмной темы из интерфейса: палитра нужна только как ответ в чат, а не как блок на рабочей странице.
- [Документация] (AI Knowledge) KAR-305 docs/ai-knowledge.md — Оставлено только техническое описание тёмной темы и причин переопределения светлых Tailwind-плашек.
0.23.1 – 04.07.2026 04:01
- [Улучшение] (Language UI) KAR-304 resources/views/livewire/settings/partials/language-tabs.blade.php, resources/views/livewire/settings/language-settings-page.blade.php, resources/views/livewire/settings/translation-queue-page.blade.php — Вкладки `Языки`, `Провайдеры перевода` и `Очередь переводов` вынесены в общий header, чтобы очередь выглядела частью единого раздела, а не отдельной страницей.
- [Улучшение] (Language UI) KAR-304 app/Livewire/Settings/LanguageSettingsPage.php — Вкладка провайдеров открывается по query-параметру `?tab=providers`, чтобы общие вкладки могли быть ссылками между URL.
- [Проверка] (Language UI) KAR-304 tests/Feature/Settings/LanguageSettingsPageTest.php, tests/Feature/Settings/TranslationQueuePageTest.php — Добавлены регрессии на общие вкладки и активное состояние страницы очереди переводов.
- [Документация] (Languages) KAR-304 docs/languages-and-translation.md — Уточнено, что очередь переводов визуально является третьей вкладкой раздела `Языки`.

0.24.0 – 04.07.2026 03:59
- [Фикс] (Admin Dark Theme) KAR-305 resources/css/app.css — Убраны светлые Tailwind-плашки `bg-*-50/100` в тёмной теме: success, warning, danger, info и neutral теперь используют тёмные semantic-токены с читаемым текстом и рамками.
- [Новый функционал] (AI Knowledge UI) KAR-305 resources/views/livewire/settings/ai-knowledge-page.blade.php — На странице AI-базы знаний в тёмной теме добавлена видимая палитра цветов с пояснением, где используется каждый цвет.
- [Документация] (AI Knowledge) KAR-305 docs/ai-knowledge.md — Описана палитра тёмной темы и обновлены команды применения для пересборки Vite/CSS и проверки логов.
0.23.0 – 04.07.2026 03:02
- [Новый функционал] (Translation Queue) KAR-304 database/migrations/2026_07_04_000000_create_translation_jobs_table.php, app/Models/TranslationJob.php — Добавлена таблица `translation_jobs` для бизнес-мониторинга задач перевода со статусами, датами, провайдером, попытками, символами и ошибкой.
- [Новый функционал] (Settings UI) KAR-304 app/Livewire/Settings/TranslationQueuePage.php, resources/views/livewire/settings/translation-queue-page.blade.php, app/Modules/Admin/AdminServiceProvider.php — Добавлена страница `/admin/settings/language/translate_queue` с таблицей очереди, фильтрами по статусу/типу/языку, поиском и ссылкой на связанный автоответ.
- [Улучшение] (Auto Replies) KAR-304 app/Livewire/Settings/AutoReplyFormPage.php, app/Jobs/TranslateAutoReplyJob.php — При запуске `Перевести все языки` создаются записи очереди, а job обновляет статусы `running/done/failed/skipped`.
- [Проверка] (Translation Queue) KAR-304 tests/Feature/Settings/TranslationQueuePageTest.php, tests/Feature/Jobs/TranslateAutoReplyJobTest.php, tests/Unit/Livewire/Settings/AutoReplyFormPageTest.php — Добавлены регрессии на страницу очереди, фильтры, создание monitoring-записей и обновление статусов job.
- [Документация] (Languages) KAR-304 docs/languages-and-translation.md — Описана очередь переводов, статусы, назначение `translation_jobs` и команды применения с перезапуском nginx.

0.22.1 – 04.07.2026 02:34
- [Фикс] (Auto Replies) KAR-287/KAR-289 app/Livewire/Settings/AutoReplyFormPage.php, resources/views/livewire/settings/auto-reply-form-page.blade.php — Кнопка `Перевести все языки` теперь показывает спиннер, блокируется на время Livewire-запроса и сообщает оператору, сколько языков поставлено в очередь.
- [Улучшение] (Settings UI) KAR-287 resources/views/layouts/admin-settings.blade.php — В layout настроек добавлены toast-уведомления `admin-toast`, чтобы действия Livewire давали понятную обратную связь без перехода в чат.
- [Проверка] (Auto Replies) KAR-287 tests/Unit/Livewire/Settings/AutoReplyFormPageTest.php — Добавлена регрессия: автоперевод ставит jobs только для включённых не-русских языков и отправляет уведомление оператору.
- [Документация] (Languages) KAR-287 docs/languages-and-translation.md — Описаны очередь, спиннер и уведомление для перевода автоответов.

0.22.0 – 03.07.2026 05:47
- [Новый функционал] (Translation Core) KAR-287/KAR-294 app/Modules/Translation/*, database/migrations/2026_07_03_120000_create_translation_tables.php — Добавлен централизованный слой машинного перевода с fallback-порядком провайдеров, кэшем, usage-логами, защитой ссылок/плейсхолдеров и circuit breaker для проблемных провайдеров.
- [Новый функционал] (Language Settings) KAR-287/KAR-288 app/Livewire/Settings/LanguageSettingsPage.php, resources/views/livewire/settings/language-settings-page.blade.php — Добавлен раздел `/admin/settings/language` с вкладками языков и провайдеров перевода, настройкой показа языков при старте, ключами Yandex/Google, offline endpoint и тестом перевода.
- [Новый функционал] (Auto Replies) KAR-287/KAR-289 app/Livewire/Settings/AutoReplyFormPage.php, app/Jobs/TranslateAutoReplyJob.php — Автоответы получили типы `regular`, `welcome`, `dialog_closed`, `ban`, переводы по выбранным языкам, ручную правку и защиту manual-переводов от перезаписи.
- [Новый функционал] (Chat Translation) KAR-287/KAR-290 app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — В чат-композер добавлен предпросмотр `Русский → язык клиента`; клиенту отправляется перевод, а русский источник сохраняется в `message_translations`.
- [Новый функционал] (AI Drafts) KAR-287/KAR-303 app/Modules/Ai/Jobs/SendAiDraftJob.php, app/Modules/Ai/Jobs/SendAiReplyJob.php, app/Modules/Ai/Actions/AiAcceptMessage.php — AI-черновики и Telegram-помощник сохраняют русскую зону оператора и перевод для клиента; при принятии клиенту уходит переведённый текст.
- [Проверка] (Quality Gate) KAR-287 tests/Unit/Modules/Translation/*, tests/Feature/Settings/LanguageSettingsPageTest.php, tests/Feature/Jobs/TranslateAutoReplyJobTest.php, tests/Unit/Livewire/Settings/AutoReply*.php — Добавлены тесты translation core, настроек языков, переводов автоответов и списков автоответов; полный PHPUnit-прогон: 1005 tests / 2793 assertions.
- [Документация] (Languages) KAR-287 docs/languages-and-translation.md, docs/chat-workspace.md, docs/architecture.md — Описаны архитектура переводов, UI языков/автоответов, чат-предпросмотр, AI-черновики, проверки и команды применения.
0.21.1 – 03.07.2026 05:26
- [Фикс] (Auto Replies) KAR-292 app/Livewire/Settings/AutoRepliesPage.php, app/Livewire/Chat/ConversationPage.php — Обычные автоответы отделены от служебных записей: в списке и быстрых чипах показывается только тип `regular`, чтобы приветствия и системные тексты не попадали оператору как обычные ответы.
- [Фикс] (AI Reply Language) KAR-292 app/Modules/Ai/Jobs/SendAiDraftJob.php, app/Modules/Ai/Jobs/SendAiReplyJob.php — AI-запросы снова используют выбранный язык клиента из карточки диалога, а при его отсутствии безопасно падают обратно на русский.
- [Проверка] (Full Test Gate) KAR-292 tests/Feature/Admin/ConversationWorkspaceTest.php, tests/Unit/Modules/Ai/Jobs/SendAiDraftJobTest.php — Актуализированы регрессии под новый формат `ИИ-черновик` и debounce-поле ввода.
- [Документация] (Chat UI) KAR-292 docs/chat-workspace.md — Описаны быстрые автоответы только для обычных правил, новый заголовок AI-черновика и команды применения.
0.21.0 – 03.07.2026 05:06
- [Новый функционал] (AI Support Evaluation) KAR-302 resources/ai/support-evaluation.json — Добавлен стартовый evaluation-набор для проверки RAG-поиска support-кейсов по ожидаемым и запрещённым маркерам.
- [Новый функционал] (CLI) KAR-302 app/Console/Commands/AiSupportEvaluate.php, app/Modules/Ai/Support/SupportEvaluationService.php — Добавлена команда `ai:support-evaluate`, которая прогоняет evaluation JSON и возвращает PASS/FAIL по каждому кейсу.
- [Проверка] (AI Support RAG) KAR-302 tests/Unit/Modules/Ai/Support/AiSupportRagTest.php — Добавлена регрессия, что evaluation учитывает только активные RAG-кейсы и не пропускает выключенный мусор.
- [Документация] (AI Knowledge) KAR-302 docs/ai-knowledge.md, docs/ai-support-moderation.md — Описан evaluation-файл, формат проверок и команда запуска после модерации.
0.14.2 – 03.07.2026 05:06
- [Фикс] (Telegram/AI Bot) KAR-293 app/Console/Commands/AiBotPollUpdates.php, docker-compose.yml — Добавлен отдельный `ai_telegram_poller`, который забирает `callback_query` AI-бота через `getUpdates` и передаёт их во внутренний `/api/ai-bot/webhook`; это решает `Connection timed out` публичного webhook и оживляет кнопки `Отправить / Изменить / Отменить`.
- [Проверка] (AI Bot Polling) tests/Feature/Commands/AiBotPollUpdatesCommandTest.php — Добавлена регрессия: AI-poller отключает webhook без удаления pending updates и пересылает callback во внутренний webhook с секретом AI-бота.
- [Документация] (Docker/Chat) docs/windows-docker.md, docs/chat-workspace.md — Описана схема отдельного polling для AI-бота и команды проверки логов `ai_telegram_poller`.
0.20.0 – 03.07.2026 05:03
- [Новый функционал] (AI Knowledge UI) KAR-301 app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — Добавлен отдельный режим `Из архива`: модальное окно принимает папку Telegram HTML export, показывает предварительный расчёт файлов/сообщений/кандидатов и запускает импорт отдельно от текущих диалогов.
- [Улучшение] (AI Support RAG) KAR-301 app/Modules/Ai/Support/SupportArchiveImportService.php — Архивный импорт создаёт только новые source hash, не перезаписывает существующие кейсы и ставит новые записи в `Нужно проверить` до AI-модерации.
- [Улучшение] (Settings) KAR-301 app/Services/Settings/SettingKeyRegistry.php — Добавлена настройка `ai.support_archive_path` для сохранения папки архива между запусками.
- [Проверка] (AI Knowledge UI) KAR-301 tests/Feature/Settings/AiKnowledgePageTest.php — Добавлена регрессия на модальное окно архива, сохранение пути, импорт архивного кейса и применение AI-модерации.
- [Документация] (AI Knowledge) KAR-301 docs/ai-knowledge.md, docs/ai-support-moderation.md — Описан отдельный UI-режим `Из архива` и правило модерации только новых source hash текущего запуска.
0.19.0 – 03.07.2026 04:59
- [Новый функционал] (AI Knowledge UI) KAR-300 app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — Во вкладку `Support-диалоги` добавлена кнопка `Пополнить базу AI` с модальным окном, предварительным расчётом диалогов/сообщений/кандидатов и описанием действий перед запуском.
- [Улучшение] (AI Support RAG) KAR-300 app/Modules/Ai/Support/SupportCurrentDialogImportService.php — Пополнение из текущих диалогов теперь создаёт только новые source hash и не перезаписывает уже существующие кейсы.
- [Улучшение] (AI Support Moderation) KAR-300 app/Modules/Ai/Support/SupportCaseModeratorService.php — UI-режим модерирует только новые кейсы текущего запуска, а не все старые записи со статусом `Нужно проверить`.
- [Проверка] (AI Knowledge UI) KAR-300 tests/Feature/Settings/AiKnowledgePageTest.php — Добавлена регрессия на модальное окно `Пополнить базу AI`, импорт текущего диалога и применение AI-модерации.
- [Документация] (AI Knowledge) KAR-300 docs/ai-knowledge.md, docs/ai-support-moderation.md — Описан UI-режим пополнения базы AI и правило, что старые кейсы не трогаются.
0.18.0 – 03.07.2026 04:54
- [Новый функционал] (AI Support Moderation) KAR-299 app/Modules/Ai/Support/SupportCaseModeratorService.php — Добавлен AI-модератор support-кейсов: отправляет кейсы со статусом `Нужно проверить` в DeepSeek/OpenAI-совместимый JSON-режим, валидирует ответ и применяет статусы `Активен`/`Нужно проверить`/`Выключен`.
- [Новый функционал] (CLI) KAR-299 app/Console/Commands/AiSupportModerateCases.php — Добавлена команда `ai:support-moderate --limit=50` для пакетной модерации кандидатов перед попаданием в AI-контекст.
- [Улучшение] (Settings) KAR-299 app/Services/Settings/SettingKeyRegistry.php — Зарегистрированы настройки `ai.support_moderator_provider` и `ai.support_moderator_model`, чтобы модератора можно было отделить от обычного AI-ответчика.
- [Проверка] (AI Support Moderation) KAR-299 tests/Unit/Modules/Ai/Support/AiSupportRagTest.php — Добавлены регрессии на применение валидного JSON-ответа и безопасный fallback в `Нужно проверить` при битом ответе модели.
- [Документация] (AI Knowledge) KAR-299 docs/ai-support-moderation.md, docs/ai-knowledge.md — Описана фактическая JSON-схема модерации, команда запуска и правило: рекомендация `delete` не удаляет запись автоматически.
0.14.1 – 03.07.2026 04:52
- [Фикс] (Telegram/AI Bot) KAR-293 app/Modules/Telegram/DTOs/TelegramUpdateDto.php — `callback_query.id` теперь читается как строка, как его реально присылает Telegram, поэтому кнопки AI-бота больше не отбрасываются на этапе DTO.
- [Фикс] (Docker/AI Bot) KAR-293 docker-compose.yml — При старте `telegram_poller` автоматически выполняется `php artisan ai-bot:set-webhook`, чтобы нативные кнопки отдельного AI-бота работали после пересборки и не конфликтовали с polling основного бота.
- [Проверка] (AI Bot) tests/Feature/Modules/Ai/AiBotWebhookButtonsTest.php, tests/Unit/Modules/Ai/Actions/AiEditHintMessageTest.php — Добавлена регрессия: webhook AI-бота принимает callback кнопок `Отправить` и `Изменить`, доставляет ответ клиенту и отвечает callback-подсказкой.
- [Документация] (Chat/Docker) docs/chat-workspace.md, docs/windows-docker.md — Описано, что кнопки AI-подсказки обслуживаются отдельным AI-ботом через `/api/ai-bot/webhook`, а webhook поднимается автоматически.
0.17.0 – 03.07.2026 04:49
- [Новый функционал] (AI Support RAG) KAR-298 app/Modules/Ai/Support/SupportCurrentDialogImportService.php — Добавлен сборщик кандидатов из текущих диалогов: группировка идёт строго внутри одного `bot_user_id`, подряд идущие сообщения клиента становятся одним вопросом, подряд идущие ответы оператора — одним ответом.
- [Новый функционал] (CLI) KAR-298 app/Console/Commands/AiSupportImportCurrentDialogs.php — Добавлена команда `ai:support-import-current` с режимами `--dry-run`, `--activate` и ограничением `--limit-dialogs` для безопасного пополнения базы AI текущими диалогами.
- [Улучшение] (AI Support Moderation) KAR-298 app/Modules/Ai/Support/SupportCurrentDialogImportService.php — Новые кейсы из текущих диалогов создаются в статусе `Нужно проверить`, не активируются для AI автоматически и получают `source_metadata` со ссылкой на админский чат и ID сообщений.
- [Проверка] (AI Support RAG) KAR-298 tests/Unit/Modules/Ai/Support/AiSupportRagTest.php — Добавлена регрессия на раздельную группировку двух диалогов, объединение нескольких клиентских/операторских сообщений и идемпотентность импорта.
- [Документация] (AI Knowledge) KAR-298 docs/ai-knowledge.md — Описаны команды импорта текущих диалогов, статус новых кандидатов и правило защиты от склейки разных чатов.
0.16.0 – 03.07.2026 04:44
- [Новый функционал] (AI Knowledge UI) KAR-297 app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — Во вкладку `Support-диалоги` добавлены поиск, фильтр статуса, сортировка, пагинация, Drawer карточки кейса и действия `Активен`/`Нужно проверить`/`Выключен`/`Удалить`.
- [Новый функционал] (AI Support RAG) KAR-297 database/migrations/2026_07_03_043600_add_moderation_fields_to_ai_support_knowledge_chunks.php, app/Models/AiSupportKnowledgeChunk.php — Для support-кейсов добавлены статусы модерации, причина, риски, группа дублей и source metadata; выключенные записи мигрируют в статус `Выключен`.
- [Фикс] (AI Support RAG) KAR-297 app/Modules/Ai/Support/SupportRagSearchService.php — RAG-поиск теперь отдаёт AI только активные и разрешённые кейсы, чтобы `Нужно проверить` и `Выключен` не дезинформировали модель.
- [Проверка] (AI Knowledge UI) KAR-297 tests/Feature/Settings/AiKnowledgePageTest.php, tests/Unit/Modules/Ai/Support/AiSupportRagTest.php — Добавлены регрессии на Drawer, смену статуса, физическое удаление и исключение неактивных кейсов из AI-контекста.
- [Документация] (AI Knowledge) KAR-297 docs/ai-knowledge.md — Описаны статусы support-кейсов, Drawer, фильтры, пагинация и команды применения миграции.
0.15.0 – 03.07.2026 04:32
- [Новый функционал] (AI Knowledge UI) KAR-296 app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — Раздел `/admin/settings/ai/knowledge` разделён на вкладки `Support-диалоги`, `Блоки знаний` и `AI-модератор`; вкладка модератора показывает провайдера, модель, версию правил и краткое описание пайплайна.
- [Проверка] (AI Knowledge UI) KAR-296 tests/Feature/Settings/AiKnowledgePageTest.php — Добавлена регрессия на вкладки и отображение правил AI-модерации; существующие проверки блоков знаний переведены на вкладку `Блоки знаний`.
- [Документация] (AI Knowledge) KAR-296 docs/ai-knowledge.md — Описана новая вкладочная структура раздела и назначение вкладки `AI-модератор`.
0.14.1 – 03.07.2026 04:26
- [Документация] (AI Knowledge) KAR-295 docs/ai-support-moderation.md — Добавлена спецификация AI-модерации support-кейсов: статусы, группировка клиентских и операторских блоков, JSON-ответ DeepSeek, защита от дублей, правила пополнения базы AI и ссылки на best practices RAG-evaluation.
- [Документация] (AI Knowledge) KAR-295 docs/ai-knowledge.md — Добавлена ссылка на спецификацию модерации и краткое правило: AI использует только support-кейсы со статусом `Активен`.
0.14.0 – 03.07.2026 03:52
- [Новый функционал] (Chat UI) KAR-293 app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — В Web-ленту выбранного диалога добавлено виртуальное системное сообщение «КОНТАКТНАЯ ИНФОРМАЦИЯ» с теми же ключевыми полями, что и в Telegram, без записи дублей в `messages`.
- [Новый функционал] (Telegram/AI Draft) app/Modules/Ai/Actions/AcceptAiDraftReplyMessage.php, app/Modules/Telegram/Controllers/TelegramBotController.php — Reply на pending AI-подсказку теперь считается отредактированным ответом оператора: текст уходит клиенту, черновик помечается accepted, обычная отправка не создаёт дубль.
- [Улучшение] (Telegram/AI Buttons) app/Helpers/AiHelper.php, app/Modules/Ai/Actions/AiEditHintMessage.php, app/Modules/Ai/Controllers/AiBotController.php, app/Modules/Telegram/DTOs/TGTextMessageDto.php — Кнопка «Изменить» больше не использует inline-вставку, а показывает оператору понятную инструкцию ответить reply на AI-подсказку.
- [Улучшение] (Contact summary) app/Modules/Telegram/Services/ContactSummaryFormatter.php, app/Modules/Telegram/Actions/SendContactMessage.php — Сбор контактной карточки вынесен в общий форматтер для Web и Telegram, чтобы поля не расходились.
- [Проверка] (Chat/AI) tests/Feature/Admin/ConversationWorkspaceTest.php, tests/Feature/Modules/Telegram/AutoAiModeCommandWebhookTest.php, tests/Unit/Modules/Ai/Actions/AcceptAiDraftReplyMessageTest.php, tests/Unit/Modules/Ai/Actions/AiEditHintMessageTest.php, tests/Unit/Helpers/AiHelperTest.php, tests/Unit/Modules/Telegram/DTOs/TGTextMessageDtoTest.php — Добавлены регрессии на контактное Web-сообщение, новые AI-кнопки и reply-flow без дублей.
- [Документация] (Chat UI) docs/chat-workspace.md — Описаны контактная карточка в Web, внутренняя видимость AI-подсказок и редактирование через reply.

0.13.2 – 03.07.2026 01:17
- [Фикс] (Chat input) app/Livewire/Chat/ConversationPage.php, resources/views/livewire/chat/conversation-page.blade.php — После фонового `wire:poll` чат отправляет браузеру событие пересчёта autosize, поэтому поле с длинным черновиком не схлопывается обратно в одну строку.
- [Проверка] (Chat UI) tests/Feature/Admin/ConversationWorkspaceTest.php — Обновлена проверка textarea: разметка должна содержать обработчик восстановления высоты после Livewire poll.
- [Документация] (Chat UI) docs/chat-workspace.md — Описано восстановление высоты поля после фонового обновления и актуализированы команды применения для Docker-образов.

0.13.2 – 03.07.2026 03:14
- [Фикс] (Telegram/Auto AI) app/Modules/Telegram/Services/Commands/AutoAiModeCommand.php — Сообщения в теме General теперь корректно распознаются даже без `message_thread_id`, как Telegram реально присылает для General; ответ бота в General отправляется без некорректного thread id.
- [Фикс] (Telegram/AI) app/Modules/Telegram/Controllers/TelegramBotController.php — Новое private-сообщение клиента сразу переоткрывает закрытый диалог до AI-gate, поэтому AI снова генерирует автоответ или внутреннюю подсказку.
- [Проверка] (Auto AI) tests/Feature/Modules/Telegram/AutoAiModeCommandWebhookTest.php, tests/Unit/Modules/Telegram/Services/Commands/AutoAiModeCommandTest.php — Добавлены регрессии на General без `message_thread_id` и на генерацию AI после повторного обращения в закрытый диалог.
- [Документация] (Chat UI) docs/chat-workspace.md — Уточнены особенности General topic и поведение повторного обращения после закрытия.

0.13.1 – 03.07.2026 01:08
- [Фикс] (Тёмная тема) resources/views/layouts/admin-*.blade.php, resources/views/partials/admin-theme-head.blade.php — Тема теперь выставляется до загрузки CSS: сервер читает cookie `tg_support_admin_theme` из raw Cookie-заголовка и сразу отдаёт `data-theme`, а общий inline-скрипт синхронизирует localStorage/cookie без светлой вспышки.
- [Фикс] (Тёмная тема) resources/js/app.js — Переключатель темы теперь сохраняет выбор и в localStorage, и в cookie, чтобы следующий заход в настройки сразу открывался в выбранной теме.
- [Документация] (Windows Docker) docs/windows-docker.md — Описана новая схема хранения темы и команда применения через rebuild образов.

0.13.0 – 03.07.2026 01:04
- [Новый функционал] (Chat UI) resources/views/livewire/chat/conversation-page.blade.php — В шапку веб-чата добавлен переключатель `Auto AI` с состоянием ON/OFF, tooltip и мгновенным изменением `ai.auto_reply`.
- [Новый функционал] (Telegram) app/Modules/Telegram/Services/Commands/AutoAiModeCommand.php — Добавлены команды `/autoAi on|off|status` и `autoai on|off|status` в теме General support-группы с проверкой администратора через `getChatMember`.
- [Улучшение] (AI Draft) app/Helpers/AiHelper.php — Внутренний AI-черновик теперь явно начинается с `🧠 AI-подсказка, клиент не видит:`, чтобы оператор понимал, что клиент это сообщение не получил.
- [Проверка] (Auto AI) tests/Feature/Modules/Telegram/AutoAiModeCommandWebhookTest.php, tests/Unit/Modules/Telegram/Services/Commands/AutoAiModeCommandTest.php — Добавлены проверки Telegram-команд, доступа администраторов, обработки до поиска BotUser и выбора SendAiReplyJob/SendAiDraftJob по режиму.
- [Документация] (Chat UI) docs/chat-workspace.md — Описаны режимы Auto AI ON/OFF, Telegram-команды, видимость внутренних подсказок и команды применения.

0.12.1 – 03.07.2026 01:02
- [Фикс] (Deploy/Docs) docs/chat-workspace.md — Уточнена команда применения изменений для текущего Docker Compose: после PHP/Blade-правок нужен rebuild образов `app`, `queue`, `scheduler`, `telegram_poller`, а не простой restart.
0.12.0 – 03.07.2026 00:26
- [Новый функционал] (Chat UI) resources/views/livewire/chat/conversation-page.blade.php — Профиль клиента теперь открывается правым Drawer с вкладками «Сведения», «Подписки» и «История» вместо центральной модалки.
- [Улучшение] (Chat input) resources/views/livewire/chat/conversation-page.blade.php — Поле ответа синхронизируется с Livewire отложенно, растёт до 5 строк и дальше прокручивается внутри, чтобы набор текста не вызывал прыжки интерфейса.
- [Улучшение] (Contact details) app/Livewire/Chat/ConversationPage.php — Добавлены строки карточки контакта из сохранённых данных BotUser без Telegram API-запроса при открытии Drawer.
- [Проверка] (Chat UI) tests/Feature/Admin/ConversationWorkspaceTest.php — Добавлены проверки Drawer-вкладок, сведений контакта, заглушек и ограничения textarea.
- [Документация] (Chat UI) docs/chat-workspace.md — Описаны новый Drawer контакта, поведение поля ввода, UX-доступность и команды применения.

0.11.1 – 03.07.2026 00:05
- [Документация] (Agents/Linear) AGENTS.md, KAR-286 — Перенесено правило из PostEditBot: changelog-значимые работы должны сопровождаться Linear-задачей как артефактом в проекте `tg-support-bot` команды `Karshiev`; создан Linear-проект `tg-support-bot`.

0.11.0 – 02.07.2026 05:35
- [Новый функционал] (AI Support RAG) database/migrations/2026_07_02_120000_create_ai_support_rag_tables.php, app/Models/AiSupport*.php — Добавлены отдельные таблицы и модели для истории импортов, сообщений support-архива и RAG-фрагментов «клиент → оператор».
- [Новый функционал] (AI Support RAG) app/Modules/Ai/Support — Добавлены парсер Telegram HTML, импорт support-архива, гибридный поиск по старым кейсам и optional embeddings в JSON без pgvector.
- [Улучшение] (AI) app/Modules/Ai/Services/AiAssistantService.php — Перед генерацией ответа AI получает не только ручную базу знаний, но и похожие support-кейсы как примеры.
- [Новый функционал] (CLI) app/Console/Commands/AiSupportImport.php, app/Console/Commands/AiSupportExportFineTune.php — Добавлены команды dry-run/activate импорта support-диалогов и экспорт JSONL для будущего fine-tuning.
- [Улучшение] (AI Knowledge UI) app/Livewire/Settings/AiKnowledgePage.php, resources/views/livewire/settings/ai-knowledge-page.blade.php — В раздел базы знаний добавлена статистика support-диалогов, переиндексация и включение/выключение RAG-фрагментов с tooltip.
- [Проверка] (AI Support RAG) tests/Unit/Modules/Ai/Support/AiSupportRagTest.php — Добавлены тесты парсинга Telegram HTML, идемпотентного импорта, поиска без embeddings, лимита AI-контекста и fine-tune экспорта.
- [Документация] (AI) docs/ai-knowledge.md — Описан Support-RAG, поток архив → импорт → поиск → AI-ответ, команды импорта и применения изменений.

0.10.3 – 01.07.2026 10:22
- [Фикс] (Docker/Frontend) docker-compose.yml — Убрано монтирование всего проекта `.:/var/www` в PHP-сервисы; Laravel, Livewire и `vendor` теперь читаются из Docker-образа, чтобы действия админки не зависали на Windows bind mount.
- [Фикс] (Docker/Assets) docker-compose.yml — Добавлен общий volume `app_public` для `public/build`, чтобы nginx отдавал CSS/JS из той же сборки, которую использует Laravel.
- [Фикс] (Docker/Assets) Dockerfile — Собранный каталог `public` сохраняется внутри образа и при старте `app` копируется в `app_public`, чтобы после rebuild nginx не оставался на старых CSS/JS.
- [Документация] (Windows Docker) docs/windows-docker.md — Описана причина задержек 2–7 секунд, новая схема Docker-volume и команды применения.

0.10.2 – 01.07.2026 10:15
- [Фикс] (Telegram/AI) app/Modules/Telegram/Actions/SendTypingAction.php — Статус `sendChatAction=typing` теперь отправляется в Telegram API сразу, а не через отдельную очередь, чтобы клиент видел «бот печатает» во время подготовки AI-ответа.
- [Проверка] (Telegram/AI) tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php — Обновлены проверки: typing-индикатор уходит прямым HTTP-вызовом, а очередь остаётся для доставки сообщения клиенту.
- [Документация] (Архитектура) docs/architecture.md — Описан прямой typing-вызов и обновлены команды применения без миграций.

0.10.1 – 01.07.2026 09:46
- [Фикс] (Git hygiene) .gitignore — Добавлены локальные agent/dev-артефакты и файл с support-credentials в игнор, чтобы кэши, временные результаты, Graphify-граф и секреты не попадали в fork.
- [Фикс] (Docker) Dockerfile — Добавлено PHP-расширение GD и библиотеки PNG/JPEG/Freetype, чтобы тесты аватарок и операции с изображениями работали внутри Docker-образа.
- [Документация] (Windows Docker) docs/windows-docker.md — Описана роль PHP GD и обновлены команды пересборки/проверки после изменения Dockerfile.
0.10.0 – 01.07.2026 08:29
- [Новый функционал] (Telegram) config/support_languages.php, app/Modules/Telegram/Services/SupportLanguageService.php — Добавлен справочник языков и inline-клавиатура выбора языка при `/start` без переписывания общего Telegram-контура.
- [Новый функционал] (Telegram) database/migrations/2026_07_01_081800_add_preferred_language_to_bot_users_table.php, app/Models/BotUser.php — Выбранный язык клиента сохраняется в `bot_users` и может быть изменён повторным `/start`.
- [Улучшение] (AI) app/Modules/Ai/Services/ShouldAiReply.php, app/Modules/Ai/Services/BaseAiProvider.php, app/Modules/Ai/Jobs/SendAiReplyJob.php — Автоответ Telegram запускается только после выбора языка, AI получает жёсткое правило выбранного языка и перед ответом отправляет `sendChatAction=typing`.
- [Улучшение] (Telegram) app/Modules/Telegram/Actions/SendContactMessage.php — Карточка контактной информации дополнена именем, username, ссылкой, выбранным языком, Telegram language_code, статусами телефона/региона и датами обращения.
- [Проверка] (Telegram/AI) tests/Unit/Modules/Telegram/Actions/SendStartMessageTest.php, tests/Unit/Modules/Telegram/Actions/SendContactMessageTest.php, tests/Feature/Modules/Telegram/IncomingMessagePersistenceTest.php, tests/Unit/Modules/Ai/Services/ShouldAiReplyTest.php, tests/Unit/Modules/Ai/Services/OpenAiProviderTest.php, tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php — Добавлены проверки выбора языка, запрета AI до выбора, языкового правила, карточки контакта и typing-индикатора.
- [Документация] (Архитектура) docs/architecture.md — Описан новый flow `start → language → contact info → AI`, места кода и команды применения.
0.9.0 – 01.07.2026 08:12
- [Новый функционал] (AI Knowledge UI) app/Livewire/Settings/AiKnowledgePage.php — Добавлен Livewire CRUD-раздел «База знаний AI» с поиском, фильтром активности, сортировкой, пагинацией, счётчиками и Drawer-карточкой записи.
- [Новый функционал] (AI Knowledge UI) resources/views/livewire/settings/ai-knowledge-page.blade.php — Добавлен адаптивный интерфейс: desktop-таблица, мобильные карточки, Drawer 50% ширины на desktop и полноэкранный Drawer на мобильных.
- [Улучшение] (Навигация) app/Modules/Admin/AdminServiceProvider.php, resources/views/layouts/admin-settings.blade.php — Добавлен маршрут `/admin/settings/ai/knowledge` и пункт меню «База знаний AI».
- [Проверка] (AI Knowledge UI) tests/Feature/Settings/AiKnowledgePageTest.php — Добавлены Feature/Livewire-тесты доступа, отображения, поиска, фильтрации, создания, редактирования, переключения активности, удаления и валидации slug.
- [Документация] (AI) docs/ai-knowledge.md, docs/architecture.md — Описан веб-интерфейс управления базой знаний AI, мобильная адаптация и команды применения.

0.8.1 – 01.07.2026 07:52
- [Документация] (AI Prompt) system-prompt.md — Устаревший длинный промпт заменён коротким справочным шаблоном; добавлено пояснение, что боевой промпт хранится в БД `ai.system_prompt`, а каталог и FAQ вынесены в AI-базу знаний.

0.8.0 – 01.07.2026 07:43
- [Новый функционал] (AI Knowledge) storage/app/ai-knowledge.json — Текущие знания из длинного системного промпта вынесены в JSON: общие ссылки, FAQ-навигация, выбор тарифа и основные продукты RelaxaClub.
- [Улучшение] (AI Prompt) storage/app/ai-system-prompt.short.txt, settings.ai.system_prompt — Боевой системный промпт сокращён до правил поведения; каталог продуктов больше не отправляется целиком в каждый AI-запрос.
- [Фикс] (AI Knowledge) app/Modules/Ai/Services/AiKnowledgeService.php — Поиск теперь отбрасывает слабые совпадения только по содержимому, если есть точное совпадение по названию или ключевым словам продукта.
- [Проверка] (AI) tests/Unit/Modules/Ai/Services/AiKnowledgeServiceTest.php — Добавлен тест, что вопрос про BroSpace выбирает именно BroSpace, а не пакеты, где он просто указан в составе.
- [Документация] (AI) docs/ai-knowledge.md, docs/architecture.md — Описаны текущие импортированные блоки, короткий системный промпт и команды обновления базы знаний.

0.7.0 – 01.07.2026 07:36
- [Новый функционал] (AI Knowledge) database/migrations/2026_07_01_073652_create_ai_knowledge_items_table.php, app/Models/AiKnowledgeItem.php — Добавлена таблица AI-базы знаний для хранения продуктов, цен, ссылок и FAQ отдельно от системного промпта.
- [Новый функционал] (AI Knowledge) app/Modules/Ai/Services/AiKnowledgeService.php — Добавлен простой поиск релевантных блоков по текущему вопросу клиента с ограничением до 1-3 блоков в AI-запросе.
- [Улучшение] (AI) app/Modules/Ai/Services/AiAssistantService.php — Перед генерацией ответа теперь подставляются найденные блоки знаний, а не весь каталог в системном промпте.
- [Новый функционал] (CLI) app/Console/Commands/AiKnowledgeImport.php — Добавлена команда импорта/обновления базы знаний из JSON-файла.
- [Проверка] (AI) tests/Unit/Modules/Ai/Services/AiKnowledgeServiceTest.php, tests/Unit/Modules/Ai/Services/OpenAiProviderTest.php — Добавлены тесты поиска знаний и проверки подстановки релевантного блока в payload AI-провайдера.
- [Документация] (AI) docs/ai-knowledge.md, docs/architecture.md — Описана лайтовая схема AI-базы знаний, формат JSON и команды применения.

0.6.0 – 01.07.2026 07:14
- [Новый функционал] (AI) app/Modules/Ai/Jobs/SendAiReplyJob.php — Автоответы ИИ теперь дублируются в Telegram-группу саппорта через основного бота, если отдельный AI-бот не настроен; оператор видит, что именно получил клиент.
- [Проверка] (AI) tests/Unit/Modules/Ai/Jobs/SendAiReplyJobTest.php — Добавлен тест, который подтверждает отправку копии автоответа ИИ в тему саппорт-группы без `telegram_ai.token`.
- [Документация] (Архитектура) docs/architecture.md — Описан путь AI-ответа: клиент, веб-чат и копия в Telegram-группу саппорта.

0.5.3 – 01.07.2026 07:07
- [Фикс] (Telegram) app/Modules/Telegram/Jobs/TopicCreateJob.php — Шаблон названия топика больше не сбрасывается в запасной формат, если Telegram не вернул необязательное поле вроде last_name; пустые части убираются, пробелы нормализуются.
- [Проверка] (Telegram) tests/Feature/Jobs/TopicCreateJobTest.php — Добавлен тест для шаблона `{first_name} {last_name} ({username})`, который подтверждает результат `Test (testuser)` при пустой фамилии.
- [Документация] (Архитектура) docs/architecture.md — Описано формирование названия Telegram-топика, поддерживаемые плейсхолдеры и команды применения.

0.5.2 – 01.07.2026 06:52
- [Фикс] (AI) app/Modules/Ai/Services/BaseAiProvider.php — Добавлено отдельное правило языка перед текущим сообщением клиента, чтобы ИИ отвечал на языке последнего сообщения, а не продолжал язык старой истории диалога.
- [Улучшение] (AI) app/Modules/Ai/Services/OpenAiProvider.php, app/Modules/Ai/Services/DeepSeekProvider.php, app/Modules/Ai/Services/GigaChatProvider.php — Провайдеры переведены на общий сборщик сообщений с единым языковым правилом.
- [Проверка] (AI) tests/Unit/Modules/Ai/Services/OpenAiProviderTest.php — Обновлён тест payload, который подтверждает порядок: системный промпт, история, языковое правило, текущее сообщение.
- [Документация] (Архитектура) docs/architecture.md — Добавлена схема выбора языка AI-ответа и команды применения после PHP-правки.

0.5.1 – 01.07.2026 06:43
- [Фикс] (UI) resources/css/app.css — Добавлены отдельные theme-токены для чатовых поверхностей, чтобы входящие сообщения, поле ввода, quick replies, вложения и soft-кнопки не оставались светлыми в тёмной теме.
- [Улучшение] (UX) resources/views/livewire/chat/conversation-page.blade.php — Чат переведён с жёстко заданных светлых цветов на токены темы; интерактивным элементам в изменённых блоках добавлены понятные tooltip.
- [Документация] (Windows Docker) docs/windows-docker.md — Обновлено описание тёмной темы чата и команды применения после изменения frontend-ассетов.

0.5.0 – 01.07.2026 05:48
- [Новый функционал] (Telegram) app/Console/Commands/TelegramPollUpdates.php — Добавлен long polling режим telegram:poll-updates, который забирает сообщения через исходящие запросы Telegram API и передаёт их в существующий webhook-обработчик.
- [Новый функционал] (Docker) docker-compose.yml — Добавлен сервис telegram_poller, чтобы бот работал за домашним роутером/Synology даже при недоступном входящем webhook от Telegram.
- [Документация] (Windows Docker) docs/windows-docker.md — Описан poller-режим, диагностика логов и команды применения для полного пути Telegram → админка → support-группа.
0.4.4 – 01.07.2026 05:43
- [Улучшение] (Graphify) docs/graphify.md — Убрана жёстко зафиксированная цифра размера графа, чтобы документация не устаревала после каждого обновления Graphify.
0.4.3 – 01.07.2026 05:41
- [Фикс] (Dependencies) package-lock.json — Обновлены frontend-зависимости через npm audit fix; npm audit теперь показывает 0 уязвимостей.
- [Документация] (Качество) docs/quality-and-operations.md — Добавлен блок о frontend-аудите зависимостей и обновлённых пакетах.
0.4.2 – 01.07.2026 05:39
- [Фикс] (Graphify) docs/graphify.md — Обновлены фактические размеры графа после финальной синхронизации: 3772 узла и 7624 связи.
0.4.1 – 01.07.2026 05:38
- [Фикс] (Graphify) docs/graphify.md — Обновлены фактические размеры графа после применения изменений: 3769 узлов и 7624 связи.
- [Проверка] (Docker) docker compose — Подтверждено, что app, pgdb, nginx, queue и scheduler после пересборки находятся в состоянии healthy.
0.4.1 – 01.07.2026 05:37
- [Фикс] (Telegram webhook) app/Console/Commands/TelegramSetWebhook.php, app/Modules/Telegram/routes.php — Уменьшено количество параллельных webhook-соединений Telegram до 5, чтобы домашний reverse proxy и PHP-FPM не ловили таймауты.
- [Фикс] (Docker) Dockerfile, docker/php-fpm/zz-relaxa-pool.conf — Добавлен отдельный PHP-FPM pool-конфиг с pm.max_children=20, чтобы админка Livewire не забивала обработку webhook.
- [Документация] (Windows Docker) docs/windows-docker.md — Описана схема Ростелеком → Synology reverse proxy → компьютер с Docker и команды применения webhook/PHP-FPM изменений.
0.4.0 – 01.07.2026 05:32
- [Новый функционал] (Документация) docs/architecture.md — Добавлена простая архитектурная карта проекта с Mermaid-диаграммами модулей, маршрутов и основных зон ответственности.
- [Новый функционал] (Эксплуатация) scripts/project-tools.ps1 — Добавлен PowerShell-помощник для быстрых команд health, routes, graph, quality, test, lint, build, up и logs.
- [Новый функционал] (Docker) docker-compose.yml — Добавлены healthcheck для app, pgdb, nginx, queue и scheduler, чтобы быстрее видеть проблемный сервис.
- [Новый функционал] (CI) .github/workflows/ci.yml — Добавлена сборка Graphify-графа в GitHub Actions с публикацией graphify-out как artifact.
- [Улучшение] (Lint) .markdownlint.yml — Разрешены обязательный заголовок «Что сделать, чтобы применить изменения:» с двоеточием и changelog без H1, чтобы документация проходила markdownlint.
- [Документация] (ADR) docs/adr — Добавлен формат ADR и первое решение о модульной структуре Laravel.
- [Документация] (Маршруты) docs/route-map.md — Добавлена карта мест, где искать маршруты разных модулей.
- [Документация] (Качество) docs/quality-and-operations.md — Описаны быстрые проверки, Docker healthcheck и CI-контур проекта.
0.3.1 – 01.07.2026 05:20
- [Фикс] (UI) resources/css/app.css — Убраны белые пятна в тёмной теме на разделах настроек, вложенных страницах интеграций и AI-провайдеров за счёт dark-overrides для жёстко заданных светлых фонов upstream-шаблонов.
- [Документация] (Windows Docker) docs/windows-docker.md — Обновлено описание тёмной темы и указано, что светлые фоны официальных шаблонов перекрываются для единообразного интерфейса.

0.3.0 – 01.07.2026 05:17
- [Новый функционал] (Graphify) AGENTS.md — Подключены правила Graphify для Codex, чтобы агент сначала использовал граф проекта при вопросах по кодовой базе.
- [Новый функционал] (Graphify) graphify-out/graph.json — Построен первичный AST-граф проекта: 3766 узлов и 7594 связи.
- [Улучшение] (Codex) .codex/hooks.json — Добавлен hook Graphify с абсолютным путём к graphify.exe, чтобы проверка работала даже без добавления Python Scripts в PATH.
- [Документация] (Graphify) docs/graphify.md — Описаны назначение Graphify, команды использования, ограничение по LLM-ключу и действия для применения.

0.2.0 – 01.07.2026 05:05
- [Новый функционал] (UI) админка — Добавлен переключатель светлой/тёмной темы рядом со ссылкой «Документация» в сайдбаре; выбор сохраняется в браузере.
- [Улучшение] (UX) тёмная тема — Добавлена спокойная slate-палитра для фона, карточек, полей, текста и границ без чистого чёрного цвета.
- [Улучшение] (UX) вход — Экран логина теперь тоже уважает выбранную тёмную тему, чтобы не было белой вспышки после выхода.
- [Документация] (Windows Docker) docs/windows-docker.md — Описана тёмная тема, принцип сохранения выбора и команды применения.

0.1.0 – 01.07.2026 04:32
- [Новый функционал] (Docker) start-relaxaclub-windows-docker.ps1 — Добавлен безопасный Windows Docker-запуск для relaxaclub без certbot, проверки IP через WSL и удаления Docker volume.
- [Фикс] (Nginx) docker/nginx/default.windows-docker.conf.template — Конфиг nginx теперь генерируется без BOM, чтобы контейнер nginx не падал на директиве server.
- [Документация] (Windows Docker) docs/windows-docker.md — Описан запуск relaxaclub через Docker Desktop/WSL и команды проверки.












