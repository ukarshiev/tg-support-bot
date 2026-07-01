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
- [Новый функционал] (Telegram) app/Console/Commands/TelegramPollUpdates.php — Добавлен long polling режим 	elegram:poll-updates, который забирает сообщения через исходящие запросы Telegram API и передаёт их в существующий webhook-обработчик.
- [Новый функционал] (Docker) docker-compose.yml — Добавлен сервис 	elegram_poller, чтобы бот работал за домашним роутером/Synology даже при недоступном входящем webhook от Telegram.
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




















