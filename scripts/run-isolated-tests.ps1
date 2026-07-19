param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpUnitArguments
)

$ErrorActionPreference = 'Stop'

$repositoryPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$appImage = (docker compose --project-directory $repositoryPath images -q app | Select-Object -First 1).Trim()

if (-not $appImage) {
    throw 'Образ app не найден. Сначала соберите его командой: docker compose build app'
}

$dockerArguments = @(
    'run', '--rm',
    '--network', 'none',
    '--read-only',
    '--user', '33:33',
    '--mount', "type=bind,source=$repositoryPath,target=/work,readonly",
    '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m,uid=33,gid=33,mode=1777',
    '--tmpfs', '/work/bootstrap/cache:rw,noexec,nosuid,size=16m,uid=33,gid=33,mode=0775',
    '--tmpfs', '/work/storage:rw,noexec,nosuid,size=96m,uid=33,gid=33,mode=0775',
    '--workdir', '/work',
    '--env', 'APP_ENV=testing',
    '--env', 'APP_CONFIG_CACHE=/tmp/tg-support-bot-phpunit-config.php',
    '--env', 'APP_KEY=base64:sfUE4/bjDejvPp2HA9b8/YDSW2s5SNOGPW0BvBxqfII=',
    '--env', 'DB_CONNECTION=sqlite',
    '--env', 'DB_DATABASE=:memory:',
    '--env', 'CACHE_STORE=array',
    '--env', 'QUEUE_CONNECTION=sync',
    '--env', 'SESSION_DRIVER=array',
    '--env', 'MAIL_MAILER=array',
    '--env', 'LOG_CHANNEL=null',
    '--env', 'TELESCOPE_ENABLED=false',
    $appImage,
    'sh', '-c', 'mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; exec "$@"',
    'isolated-phpunit',
    'php', 'vendor/bin/phpunit', '--do-not-cache-result'
)

$dockerArguments += $PhpUnitArguments

Write-Host 'PHPUnit запускается без сети, без Compose volumes и только с SQLite :memory:.' -ForegroundColor Cyan
& docker @dockerArguments

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
