# Последняя редакция: 01.07.2026 05:41 UTC+3

# Качество и эксплуатация

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
- `nginx` — проверяет корректность nginx-конфига;
- `queue` — проверяет PHP/Laravel окружение воркера;
- `scheduler` — проверяет PHP/Laravel окружение планировщика.

Это помогает быстро увидеть, какой контейнер «болеет», без чтения всех логов подряд.

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

1) `docker compose up -d --build` — Почему: изменён `docker-compose.yml`, healthcheck появятся после пересоздания сервисов.
2) `docker compose logs -f app nginx queue scheduler` — Почему: проверить ошибки приложения, nginx, очереди и планировщика.




