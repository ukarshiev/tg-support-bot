param(
    [switch]$RegenerateAppKey,
    [switch]$ApplyMigrations,
    [switch]$ConfirmProductionChange
)

$ErrorActionPreference = "Stop"

Write-Host "Start tg-support-bot for relaxaclub in Windows Docker"

if (($RegenerateAppKey -or $ApplyMigrations) -and -not $ConfirmProductionChange) {
    throw "Production-changing actions require -ConfirmProductionChange. Read the impact, create/verify a backup, and obtain explicit approval first."
}

if (-not (Test-Path ".env")) {
    throw ".env file not found. Create it from .env.example and fill settings."
}

$mainDomainLine = Get-Content ".env" | Where-Object { $_ -match "^MAIN_DOMAIN=" } | Select-Object -First 1
if ([string]::IsNullOrWhiteSpace($mainDomainLine)) {
    throw "MAIN_DOMAIN is not set in .env"
}

$mainDomain = $mainDomainLine.Substring("MAIN_DOMAIN=".Length).Trim()

$dbNameLine = Get-Content ".env" | Where-Object { $_ -match "^DB_DATABASE=" } | Select-Object -First 1
$dbUserLine = Get-Content ".env" | Where-Object { $_ -match "^DB_USERNAME=" } | Select-Object -First 1
if ([string]::IsNullOrWhiteSpace($dbNameLine) -or [string]::IsNullOrWhiteSpace($dbUserLine)) {
    throw "DB_DATABASE and DB_USERNAME must be set in .env"
}

$dbName = $dbNameLine.Substring("DB_DATABASE=".Length).Trim()
$dbUser = $dbUserLine.Substring("DB_USERNAME=".Length).Trim()

$appKeyLine = Get-Content ".env" | Where-Object { $_ -match "^APP_KEY=" } | Select-Object -First 1
$appKey = ""
if (-not [string]::IsNullOrWhiteSpace($appKeyLine)) {
    $appKey = $appKeyLine.Substring("APP_KEY=".Length).Trim()
}

Write-Host "1/7 Create nginx HTTP config for Windows Docker"
if (-not (Test-Path "docker/nginx")) {
    New-Item -ItemType Directory -Path "docker/nginx" | Out-Null
}

$templatePath = "docker/nginx/default.windows-docker.conf.template"
if (-not (Test-Path $templatePath)) {
    throw "nginx template not found: $templatePath"
}

$nginxConfig = Get-Content $templatePath -Raw
$nginxConfig = $nginxConfig.Replace("__MAIN_DOMAIN__", $mainDomain)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Resolve-Path "docker/nginx/default.conf"), $nginxConfig, $utf8NoBom)

Write-Host "2/7 Build and start Docker Compose without data removal"
docker compose up -d --build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "3/7 Install PHP dependencies from composer.lock"
docker compose exec -T app bash -lc "composer install --no-interaction --prefer-dist --optimize-autoloader"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if ([string]::IsNullOrWhiteSpace($appKey) -and -not $ConfirmProductionChange) {
    throw "APP_KEY is empty. Generating it can invalidate encrypted settings; rerun only after approval with -ConfirmProductionChange."
}

if ($RegenerateAppKey -or [string]::IsNullOrWhiteSpace($appKey)) {
    Write-Host "4/7 Generate Laravel APP_KEY"
    docker compose exec -T app bash -lc "php artisan key:generate --force"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} else {
    Write-Host "4/7 APP_KEY already set, keep current key"
}

if ($ApplyMigrations) {
    Write-Host "5/7 Create PostgreSQL backup and apply database migrations"
    $backupDirectory = Join-Path $PSScriptRoot "backups"
    New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $backupPath = Join-Path $backupDirectory "$dbName-$timestamp.sql"

    docker compose exec -T pgdb pg_dump -U $dbUser -d $dbName > $backupPath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $backupPath) -or (Get-Item $backupPath).Length -eq 0) {
        throw "Database backup failed. Migrations were not started."
    }

    Write-Host "Fresh database backup: $backupPath"
    docker compose exec -T app bash -lc "php artisan migrate --force"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} else {
    Write-Host "5/7 Skip database migrations (use -ApplyMigrations -ConfirmProductionChange after approval)"
}

Write-Host "6/7 Clear Laravel caches"
docker compose exec -T app bash -lc "php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "7/7 Restart services without volume removal"
docker compose restart app nginx queue scheduler telegram_poller ai_telegram_poller
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host ""
Write-Host "Done. Check:"
Write-Host "1) docker compose ps"
Write-Host "2) docker compose logs -f app nginx queue scheduler telegram_poller ai_telegram_poller"
Write-Host "3) http://127.0.0.1:55612/admin"
