# Последняя редакция: 01.07.2026 05:32 UTC+3

# Карта маршрутов

Маршруты в проекте регистрируются модульно, через `ServiceProvider`.

## Как получить актуальную карту

```powershell
.\scripts\project-tools.ps1 routes
```

Если нужно напрямую через Docker:

```powershell
docker compose exec app php artisan route:list --except-vendor
```

## Где искать маршруты

| Тип | Где смотреть |
| --- | --- |
| Админка | `app/Modules/Admin/AdminServiceProvider.php` |
| AI | `app/Modules/Ai/ai_routes.php` |
| API и Swagger | `app/Modules/Api/api_routes.php`, `app/Modules/Api/web_routes.php` |
| External API и widget | `app/Modules/External/routes.php`, `app/Modules/External/widget-routes.php` |
| MAX | `app/Modules/Max/routes.php` |
| Telegram | `app/Modules/Telegram/routes.php` |
| VK | `app/Modules/Vk/routes.php` |

## Что сделать, чтобы применить изменения:

1) `docker compose up -d --build` — Почему: безопасно пересоздать сервисы с учётом изменений инфраструктуры.
2) `docker compose logs -f app nginx queue scheduler` — Почему: проверить ошибки приложения, nginx, очереди и планировщика.



