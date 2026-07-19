param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'logs', 'health', 'routes', 'graph', 'quality', 'test', 'lint', 'build')]
    [string] $Command = 'health'
)

$ErrorActionPreference = 'Stop'

function Invoke-Step {
    param(
        [string] $Title,
        [string] $CommandLine
    )

    Write-Host "`n==> $Title" -ForegroundColor Cyan
    Write-Host $CommandLine -ForegroundColor DarkGray
    Invoke-Expression $CommandLine
}

switch ($Command) {
    'up' {
        Invoke-Step 'Собираю и запускаю Docker-сервисы' 'docker compose up -d --build'
    }
    'logs' {
        Invoke-Step 'Показываю live-логи ключевых сервисов' 'docker compose logs -f app nginx queue scheduler'
    }
    'health' {
        Invoke-Step 'Показываю состояние контейнеров' 'docker compose ps'
    }
    'routes' {
        Invoke-Step 'Выгружаю карту Laravel-роутов' 'docker compose exec app php artisan route:list --except-vendor'
    }
    'graph' {
        Invoke-Step 'Обновляю Graphify-карту проекта' 'C:\Users\umidt\AppData\Roaming\Python\Python313\Scripts\graphify.exe update . --no-cluster'
    }
    'quality' {
        Invoke-Step 'Проверяю composer.json' 'composer validate --strict --no-check-publish'
        Invoke-Step 'Проверяю PHP-стиль' 'vendor\bin\pint --test'
        Invoke-Step 'Проверяю PHP-статический анализ' 'vendor\bin\phpstan analyse'
        Invoke-Step 'Запускаю изолированные PHP-тесты' '.\scripts\run-isolated-tests.ps1'
        Invoke-Step 'Собираю frontend' 'npm run build'
    }
    'test' {
        Invoke-Step 'Запускаю изолированные PHP-тесты' '.\scripts\run-isolated-tests.ps1'
    }
    'lint' {
        Invoke-Step 'Проверяю PHP-стиль' 'vendor\bin\pint --test'
        Invoke-Step 'Проверяю PHP-статический анализ' 'vendor\bin\phpstan analyse'
    }
    'build' {
        Invoke-Step 'Собираю frontend' 'npm run build'
    }
}
