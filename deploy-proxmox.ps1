#requires -Version 7.0
param(
    [string]$VmHost = "karshiev@192.168.1.101",
    [string]$RemoteDir = "/opt/tg-support-bot",
    [string]$SshKey = "",
    [switch]$SkipBuild,
    [switch]$NoStart,
    [switch]$ApplyMigrations,
    [switch]$ConfirmProductionChange
)

$ErrorActionPreference = "Stop"

# Shell literals preserve spaces and apostrophes without allowing expansion.
$quoteBash = {
    param([string]$Value)
    "'" + $Value.Replace("'", ("'" + '"' + "'" + '"' + "'")) + "'"
}
$remoteDirectory = & $quoteBash $RemoteDir
$sshOptions = @("-o", "BatchMode=yes", "-o", "ConnectTimeout=10")
if (-not [string]::IsNullOrWhiteSpace($SshKey)) {
    $sshOptions += @("-i", $SshKey, "-o", "IdentitiesOnly=yes")
}

function Invoke-Remote {
    <#
    .SYNOPSIS
    Execute strict Bash in the deployment directory and return stdout.
    .DESCRIPTION
    UTF-8 base64 avoids PowerShell native-stdin CRLF conversion.
    Execute a temporary file with stdin closed so Compose exec cannot consume
    the remaining script. Explicit cleanup preserves the exit code on failure.
    On failure, include the last 40 stderr lines; keep stderr silent on success.
    #>
    param([string]$Script)

    $payload = "set -euo pipefail`ncd -- $remoteDirectory`n" + $Script
    $payload = $payload.Replace("`r`n", "`n").Replace("`r", "`n")
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($payload))
    $stderrPath = [IO.Path]::GetTempFileName()
    try {
        $remoteCommand = "bash -c 'set -uo pipefail; script_file=`$(mktemp) || exit `$?; echo $encoded | base64 -d > `"`$script_file`"; result=`$?; if [ `"`$result`" -eq 0 ]; then bash `"`$script_file`" < /dev/null; result=`$?; fi; rm -f -- `"`$script_file`"; exit `"`$result`"'"
        $output = & ssh @sshOptions $VmHost $remoteCommand 2> $stderrPath
        if ($LASTEXITCODE -ne 0) {
            $remoteExitCode = $LASTEXITCODE
            $diagnostics = (Get-Content -LiteralPath $stderrPath -Tail 40) -join "`n"
            throw "Remote step failed (exit $remoteExitCode). Deployment stopped.`n$diagnostics"
        }
        return $output
    } finally {
        Remove-Item -LiteralPath $stderrPath -Force
    }
}

function Copy-ToRemote {
    <#
    .SYNOPSIS
    Copy one local file using the same noninteractive SSH options.
    .DESCRIPTION
    Legacy SCP transport interprets the quoted destination as a shell literal.
    #>
    param([string]$LocalPath, [string]$RemotePath)

    $destination = "${VmHost}:$(& $quoteBash $RemotePath)"
    & scp -O @sshOptions $LocalPath $destination
    if ($LASTEXITCODE -ne 0) {
        throw "File transfer failed (exit $LASTEXITCODE): $LocalPath"
    }
}

# Read only the requested key; never source or print the environment file.
$envReader = @'
read_env() {
    local value
    value=$(sed -n "/^${1}=/ { s/^${1}=//; s/\r$//; p; q; }" .env)
    if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then
        value=${value:1:${#value}-2}
    fi
    printf '%s' "$value"
}
'@
$services = @("app", "queue", "reverb", "scheduler", "telegram_poller", "ai_telegram_poller")
$images = @($services | ForEach-Object { "tg-support-bot-${_}:latest" })
$manifest = @{}
$lockAcquired = $false

Write-Host "Deploy tg-support-bot from Windows to Ubuntu VM"
Push-Location $PSScriptRoot
try {
    Write-Host "1/11 Verify deployment prerequisites"
    if ($ApplyMigrations -and -not $ConfirmProductionChange) {
        throw "Production-changing actions require -ConfirmProductionChange. Read the impact, create/verify a backup, and obtain explicit approval first."
    }
    foreach ($path in @("docker-compose.yml", "docker-compose.proxmox.yml", "docker/nginx/default.windows-docker.conf.template")) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Required deployment file not found: $path"
        }
    }
    Invoke-Remote @'
test -d .
test -f .env
grep -q '^MAIN_DOMAIN=' .env
grep -q '^COMPOSE_PROJECT_NAME=tg-support-bot' .env
grep -q '^COMPOSE_FILE=docker-compose.yml:docker-compose.proxmox.yml' .env
'@ | Out-Null
    if ($NoStart) {
        $runningContainers = Invoke-Remote "docker compose ps -q --status running"
        if (-not [string]::IsNullOrWhiteSpace(($runningContainers -join "`n"))) {
            throw "-NoStart предназначен только для первой подготовки ВМ: стек уже работает, запуск без -NoStart обновит его штатно"
        }
    }
    Invoke-Remote @'
mkdir -p -- '.deploy'
if ! mkdir -- '.deploy/lock'; then
    if test -f '.deploy/lock/info'; then
        cat -- '.deploy/lock/info' >&2
    fi
    printf '%s\n' 'Деплой уже идёт или прошлый оборвался. После проверки, что другой деплой не идёт, устаревший замок снимается командой rm -rf .deploy/lock на ВМ из каталога проекта.' >&2
    exit 1
fi
'@ | Out-Null
    $lockAcquired = $true
    $commitHash = "unknown"
    try {
        $commitOutput = & git rev-parse --short HEAD 2> $null
        if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace(($commitOutput -join ""))) {
            $commitHash = ($commitOutput -join "").Trim()
        }
    } catch {
        $commitHash = "unknown"
    }
    $lockInfo = "UTC: $([DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'))`nComputer: $env:COMPUTERNAME`nUser: $env:USERNAME`nCommit: $commitHash"
    $quotedLockInfo = & $quoteBash $lockInfo
    Invoke-Remote "printf '%s\n' $quotedLockInfo > '.deploy/lock/info'" | Out-Null
    if (-not $SkipBuild) {
        $status = & git status --porcelain
        if ($LASTEXITCODE -ne 0) { throw "Cannot check checkout cleanliness. Build stopped." }
        if (-not [string]::IsNullOrWhiteSpace(($status -join "`n"))) {
            throw "Образ запекает рабочее дерево, нужен чистый checkout main."
        }
    }

    Write-Host "2/11 Build one runtime image and tag all services"
    if (-not $SkipBuild) {
        & docker build -t tg-support-bot-app:latest .
        if ($LASTEXITCODE -ne 0) { throw "Runtime image build failed." }
        foreach ($image in $images | Select-Object -Skip 1) {
            & docker tag tg-support-bot-app:latest $image
            if ($LASTEXITCODE -ne 0) { throw "Image tagging failed: $image" }
        }
    } else {
        Write-Host "Skip build; use existing local images"
    }

    Write-Host "3/11 Record image IDs for all six services"
    foreach ($image in $images) {
        $imageId = & docker image inspect --format '{{.Id}}' $image
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($imageId -join ""))) {
            throw "Required local image missing: $image"
        }
        $manifest[$image] = ($imageId -join "").Trim()
    }

    Write-Host "4/11 Preserve previous VM images as rollback tags"
    $rollbackCommands = foreach ($service in $services) {
        $image = "tg-support-bot-${service}:latest"
        $quotedImage = & $quoteBash $image
        $quotedRollback = & $quoteBash "tg-support-bot-rollback-${service}:previous"
        $quotedImageId = & $quoteBash $manifest[$image]
        @'
if current_id=$(docker image inspect --format '{{.Id}}' __IMAGE__ 2>/dev/null); then
    if [[ "$current_id" != __IMAGE_ID__ ]]; then
        docker tag __IMAGE__ __ROLLBACK__
        updated=$((updated + 1))
    else
        unchanged=$((unchanged + 1))
    fi
else
    unchanged=$((unchanged + 1))
fi
'@.Replace('__IMAGE__', $quotedImage).Replace('__IMAGE_ID__', $quotedImageId).Replace('__ROLLBACK__', $quotedRollback)
    }
    $rollbackScript = "updated=0`nunchanged=0`n" + ($rollbackCommands -join "`n") + "`n" + @'
printf 'Rollback tags: updated %s, left unchanged %s\n' "$updated" "$unchanged"
'@
    Invoke-Remote $rollbackScript | ForEach-Object { Write-Host $_ }

    Write-Host "5/11 Transfer archive and verify SHA256 and image IDs"
    $archiveName = "tg-support-bot-$([Guid]::NewGuid().ToString('N')).tar"
    $archivePath = Join-Path ([IO.Path]::GetTempPath()) $archiveName
    $remoteArchive = & $quoteBash "$RemoteDir/.deploy/$archiveName"
    try {
        & docker save -o $archivePath @images
        if ($LASTEXITCODE -ne 0) { throw "Image archive creation failed." }
        $localHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash
        Invoke-Remote "mkdir -p -- '.deploy'" | Out-Null
        Copy-ToRemote $archivePath "$RemoteDir/.deploy/$archiveName"
        $hashOutput = Invoke-Remote "sha256sum -- $remoteArchive"
        $remoteHash = (($hashOutput -join "`n") -split '\s+')[0]
        if (-not [string]::Equals($localHash, $remoteHash, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Archive SHA256 mismatch. Images were not loaded."
        }
        Invoke-Remote "docker load -i $remoteArchive" | Out-Null
        $mismatches = @()
        foreach ($image in $images) {
            $remoteId = Invoke-Remote "docker image inspect --format '{{.Id}}' '$image'"
            if (($remoteId -join "").Trim() -cne $manifest[$image]) {
                $mismatches += $image
            }
        }
        if ($mismatches.Count -gt 0) {
            throw "Loaded image IDs differ from the local manifest: $($mismatches -join ', ')"
        }
    } finally {
        try {
            Invoke-Remote "rm -f -- $remoteArchive" | Out-Null
        } finally {
            if (Test-Path -LiteralPath $archivePath) {
                Remove-Item -LiteralPath $archivePath -Force
            }
        }
    }

    Write-Host "6/11 Copy Compose files and generate nginx HTTP config"
    Copy-ToRemote "docker-compose.yml" "$RemoteDir/docker-compose.yml"
    Copy-ToRemote "docker-compose.proxmox.yml" "$RemoteDir/docker-compose.proxmox.yml"
    Invoke-Remote "mkdir -p -- 'docker/nginx'" | Out-Null
    Copy-ToRemote "docker/nginx/default.windows-docker.conf.template" "$RemoteDir/docker/nginx/default.windows-docker.conf.template"
    Invoke-Remote ($envReader + "`n" + @'
main_domain=$(read_env MAIN_DOMAIN)
[[ "$main_domain" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]]
sed "s|__MAIN_DOMAIN__|$main_domain|g" docker/nginx/default.windows-docker.conf.template > docker/nginx/default.conf
'@) | Out-Null

    Write-Host "7/11 Pull infrastructure images"
    Invoke-Remote "docker compose pull pgdb redis nginx" | Out-Null
    if ($NoStart) {
        Invoke-Remote "docker compose create --no-build" | Out-Null
        Write-Host "Start skipped (-NoStart). Images and files transferred; containers created."
        return
    }

    Write-Host "8/11 Start services and verify baked PHP dependencies"
    Invoke-Remote @'
docker compose up -d --no-build
docker compose exec -T app test -f vendor/autoload.php
'@ | Out-Null

    if ($ApplyMigrations) {
        Write-Host "9/11 Create PostgreSQL backup and apply database migrations"
        Invoke-Remote ($envReader + "`n" + @'
db_name=$(read_env DB_DATABASE)
db_user=$(read_env DB_USERNAME)
[[ "$db_name" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]]
test -n "$db_user"
mkdir -p backups
timestamp=$(date +%Y%m%d-%H%M%S)
backup_path="backups/${db_name}-${timestamp}.sql"
docker compose exec -T pgdb pg_dump -U "$db_user" -d "$db_name" > "$backup_path"
test -s "$backup_path"
printf 'Fresh database backup: %s/%s\n' "$PWD" "$backup_path"
'@) | ForEach-Object { Write-Host $_ }
        Invoke-Remote "docker compose exec -T app php artisan migrate --force" | ForEach-Object { Write-Host $_ }
    } else {
        Write-Host "9/11 Skip database migrations (миграции пропущены; use -ApplyMigrations -ConfirmProductionChange after approval)"
    }

    Write-Host "10/11 Clear Laravel file caches without touching Redis or restarting services"
    Invoke-Remote @'
# Never run cache:clear here: it erases Telegram poller offsets in Redis.
docker compose exec -T app bash -lc 'php artisan config:clear && php artisan route:clear && php artisan view:clear'
'@ | Out-Null

    Write-Host "11/11 Check service status and both Telegram pollers"
    Invoke-Remote "docker compose ps" | ForEach-Object { Write-Host $_ }
    try {
        Invoke-Remote @'
for ((attempt=1; attempt<=18; attempt++)); do
    if ((attempt == 18)); then
        docker compose exec -T telegram_poller php artisan telegram:poller-health main --max-age=90 >&2
        exit 0
    fi
    if docker compose exec -T telegram_poller php artisan telegram:poller-health main --max-age=90 >/dev/null 2>&1; then
        exit 0
    fi
    sleep 5
done
'@ | Out-Null
    } catch {
        throw "Main Telegram poller health check failed. Check its status and logs on the VM.`n$($_.Exception.Message)"
    }
    try {
        Invoke-Remote @'
for ((attempt=1; attempt<=18; attempt++)); do
    if ((attempt == 18)); then
        docker compose exec -T ai_telegram_poller php artisan telegram:poller-health ai --max-age=90 >&2
        exit 0
    fi
    if docker compose exec -T ai_telegram_poller php artisan telegram:poller-health ai --max-age=90 >/dev/null 2>&1; then
        exit 0
    fi
    sleep 5
done
'@ | Out-Null
    } catch {
        throw "AI Telegram poller health check failed. Check its status and logs on the VM.`n$($_.Exception.Message)"
    }

    Write-Host ""
    Write-Host "Done. On the VM, cd to $RemoteDir and check:"
    Write-Host "1) docker compose ps"
    Write-Host "2) docker compose logs -f app nginx queue scheduler telegram_poller ai_telegram_poller"
    Write-Host "3) docker compose exec -T telegram_poller php artisan telegram:poller-health main --max-age=90"
    Write-Host "4) docker compose exec -T ai_telegram_poller php artisan telegram:poller-health ai --max-age=90"
} finally {
    try {
        if ($lockAcquired) {
            try {
                Invoke-Remote "rm -f -- '.deploy/lock/info'; rmdir -- '.deploy/lock'" | Out-Null
            } catch {
                Write-Warning "Не удалось снять замок деплоя. Проверьте .deploy/lock на ВМ перед следующим запуском. $($_.Exception.Message)" -WarningAction Continue
            }
        }
    } finally {
        Pop-Location
    }
}
