param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpUnitArguments
)

$ErrorActionPreference = 'Stop'

$repositoryPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$composeCommand = $null
$composeConfig = $null

if (Get-Command 'docker' -ErrorAction SilentlyContinue) {
    try {
        $composeCandidateOutput = docker compose --project-directory $repositoryPath config --format json 2>$null
        if ($LASTEXITCODE -eq 0) {
            $composeCommand = 'docker compose'
            $composeConfig = $composeCandidateOutput | ConvertFrom-Json
        }
    } catch {
        # no-op, fallback below
    }
}

if (-not $composeConfig -and (Get-Command 'docker-compose' -ErrorAction SilentlyContinue)) {
    $composeCommand = 'docker-compose'
    $composeConfig = (& docker-compose -f (Join-Path $repositoryPath 'docker-compose.yml') config --format json) | ConvertFrom-Json
}

if (-not $composeCommand) {
    throw 'Neither docker compose nor docker-compose was found. Install Docker and Docker Compose.'
}

if (-not $composeConfig -or -not $composeConfig.name) {
    throw 'Failed to read Compose project name from config.'
}

$appImage = "$($composeConfig.name)-app:latest"

docker image inspect $appImage *> $null

if ($LASTEXITCODE -ne 0) {
    throw ('App image not found. Build first with: {0} build app' -f $composeCommand)
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

Write-Host 'PHPUnit will run offline, without Compose volumes, and with SQLite :memory:.' -ForegroundColor Cyan
& docker @dockerArguments

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
