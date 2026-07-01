# Последняя редакция: 01.07.2026 05:43 UTC+3

# Graphify: карта проекта

Graphify строит граф проекта: какие файлы, классы, функции и маршруты связаны между собой.

Это полезно, когда нужно быстро понять:

1. где находится нужная логика;
2. что может сломаться после изменения;
3. как связаны backend, views, routes и тесты;
4. какие части проекта стоит рефакторить первыми.

## Что уже подключено

- установлен Python-пакет `graphifyy` версии `0.9.3`;
- добавлен `AGENTS.md` с правилами использования Graphify;
- добавлен Codex hook в `.codex/hooks.json`;
- построен первичный AST-граф проекта в `graphify-out/graph.json`;
- точные размеры графа смотри в выводе команды `graphify update . --no-cluster`, потому что счётчик меняется после обновления кода и документации.

## Как пользоваться

```powershell
C:\Users\umidt\AppData\Roaming\Python\Python313\Scripts\graphify.exe query "где обрабатывается логин администратора?"
```

Команда вернёт короткую выжимку по связанным файлам и сущностям.

```powershell
C:\Users\umidt\AppData\Roaming\Python\Python313\Scripts\graphify.exe explain "LoginPage"
```

Команда объяснит выбранную сущность и её ближайшие связи.

```powershell
C:\Users\umidt\AppData\Roaming\Python\Python313\Scripts\graphify.exe update . --no-cluster
```

Команда обновит граф после изменений в коде без LLM и без затрат на API.

## Ограничение

Полная семантическая обработка документации и изображений не выполнена: для неё нужен API-ключ LLM (`OPENAI_API_KEY`, `GEMINI_API_KEY` или другой поддерживаемый ключ). Кодовый AST-граф уже построен и доступен.

## Что сделать, чтобы применить изменения:

1) `C:\Users\umidt\AppData\Roaming\Python\Python313\Scripts\graphify.exe update . --no-cluster` — Почему: обновить карту проекта после изменений в коде.
2) `docker compose logs -f app` — Почему: проверить ошибки приложения после следующего запуска/изменений.









