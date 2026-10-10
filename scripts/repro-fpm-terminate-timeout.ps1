#requires -Version 7.0
<#
.SYNOPSIS
Сравнивает уничтожение зависшего FPM-обработчика при двух настройках slowlog.
.EXAMPLE
pwsh -File .\scripts\repro-fpm-terminate-timeout.ps1 -KeepArtifacts
.DESCRIPTION
Создаёт только repro-fpm-*; проект, Compose, БД и образы не изменяет.
Сеть контейнеров отключена. PHP блокируется на чтении TCP-ответа, а не sleep().
FastCGI-клиент написан на штатном PHP: libfcgi-bin и сеть для apt не нужны.
SYS_PTRACE и одинаковое отключение seccomp позволяют FPM реально снять slowlog.
Это условие опыта, а не точная копия ограничений безопасности production.
Образ берётся по заданному тегу; Dockerfile проекта дополнительно фиксирует digest.
Запуск и проверки выполняет Владыка/оркестратор; исполнитель скрипт не запускает.
#>
param(
    [switch]$KeepArtifacts
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$experimentId = [Guid]::NewGuid().ToString('N')
$tempRoot = [IO.Path]::GetFullPath($env:TEMP)
$experimentPath = Join-Path $tempRoot "repro-fpm-$experimentId"
$containers = [Collections.Generic.List[string]]::new()
$results = [Collections.Generic.List[object]]::new()
$utf8 = [Text.UTF8Encoding]::new($false)
$experimentError = $null
$cleanupFailed = $false

function Write-Artifact {
    # LF и UTF-8 без BOM нужны для PHP и shell внутри Linux-контейнера.
    param([string]$Name, [string]$Content)
    [IO.File]::WriteAllText((Join-Path $experimentPath $Name), $Content.Replace("`r`n", "`n"), $utf8)
}

function Write-ResultLog {
    param([string]$Message)
    Write-Host $Message
    [IO.File]::AppendAllText((Join-Path $experimentPath 'results.log'), "$Message`n", $utf8)
}

function Invoke-ReproDocker {
    # Проверяем реальный код Docker; stderr тоже сохраняется в диагностике.
    param([string[]]$Arguments, [switch]$AllowFailure)
    $output = (& docker @Arguments 2>&1 | Out-String).TrimEnd()
    $code = $LASTEXITCODE
    if ($code -ne 0 -and -not $AllowFailure) {
        throw "Docker завершился с кодом ${code}: $output"
    }
    [pscustomobject]@{ Code = $code; Output = $output }
}

try {
    New-Item -ItemType Directory -Path $experimentPath | Out-Null
    Write-ResultLog "Каталог опыта: $experimentPath"
    Get-Command docker -ErrorAction Stop | Out-Null
    Write-ResultLog 'FastCGI: встроенный PHP-клиент вместо libfcgi-bin; apt не запускается, сеть контейнеров отключена.'
    Write-ResultLog 'Зависание: исходящий TCP-запрос к молчащему серверу 127.0.0.1:18080. Таймаут чтения 300 с.'
    Write-ResultLog 'В обоих замерах: SYS_PTRACE + seccomp=unconfined для записи трассировки slowlog.'

    Write-Artifact 'hang.php' @'
<?php
// Сервер принимает TCP/HTTP-запрос, но не возвращает ни одного байта.
set_time_limit(0);
$socket = stream_socket_client('tcp://127.0.0.1:18080', $errno, $error, 300);
if ($socket === false) {
    file_put_contents('/tmp/repro-results/failed', "connect: $errno $error");
    exit;
}
stream_set_timeout($socket, 300);
fwrite($socket, "GET /hang HTTP/1.0\r\nHost: silent-server\r\n\r\n");
$stat = file_get_contents('/proc/self/stat');
$fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
// PID + время рождения процесса защищают от путаницы с новым worker после убийства.
$marker = ['pid' => getmypid(), 'birth' => $fields[19], 'start' => hrtime(true)];
file_put_contents('/tmp/repro-results/started.tmp', json_encode($marker));
rename('/tmp/repro-results/started.tmp', '/tmp/repro-results/started.json');
$response = fread($socket, 1); // Именно блокирующий сетевой ввод-вывод.
file_put_contents('/tmp/repro-results/returned', json_encode(stream_get_meta_data($socket)));
echo 'NETWORK_CALL_RETURNED';
'@

    Write-Artifact 'silent-server.php' @'
<?php
$server = stream_socket_server('tcp://127.0.0.1:18080', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen: $errno $error\n");
    exit(1);
}
file_put_contents('/tmp/repro-results/server-ready', 'ready');
$connections = [];
// Сохраняем открытые соединения. Блокируемся на accept, ничего не отвечаем.
while (true) {
    $connection = stream_socket_accept($server, -1);
    if ($connection === false) {
        exit(1);
    }
    $connections[] = $connection;
}
'@

    Write-Artifact 'fcgi.php' @'
<?php
// Минимальный FastCGI responder request, без nginx и сторонних пакетов.
function record(int $type, string $body): string
{
    return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body;
}
$socket = stream_socket_client('tcp://127.0.0.1:9000', $errno, $error, 5);
if ($socket === false) {
    fwrite(STDERR, "FastCGI connect: $errno $error\n");
    exit(1);
}
stream_set_timeout($socket, 75);
$params = '';
foreach ([
    'SCRIPT_FILENAME' => '/repro/hang.php',
    'SCRIPT_NAME' => '/hang.php',
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/hang.php',
    'SERVER_PROTOCOL' => 'HTTP/1.0',
    'GATEWAY_INTERFACE' => 'CGI/1.1',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '9000',
    'REMOTE_ADDR' => '127.0.0.1',
    'CONTENT_LENGTH' => '0',
] as $name => $value) {
    $params .= chr(strlen($name)).chr(strlen($value)).$name.$value;
}
$request = record(1, pack('nCxxxxx', 1, 0)).record(4, $params).record(4, '').record(5, '');
while ($request !== '') {
    $written = fwrite($socket, $request);
    if ($written === false || $written === 0) {
        exit(2);
    }
    $request = substr($request, $written);
}
// При убийстве worker FPM закрывает соединение; клиент не завершает запрос раньше.
while (!feof($socket)) {
    $chunk = fread($socket, 8192);
    if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
        exit(3);
    }
    echo $chunk;
}
'@

    Write-Artifact 'inspect.php' @'
<?php
// /proc есть в штатном образе: ps/procps устанавливать не требуется.
$processes = [];
foreach (glob('/proc/[0-9]*/stat') as $path) {
    $stat = @file_get_contents($path);
    if ($stat !== false) {
        $processes[] = trim($stat);
    }
}
$result = ['processes' => $processes, 'ready' => false, 'started' => false];
if ($argv[1] === 'ready') {
    $socket = @stream_socket_client('tcp://127.0.0.1:9000', $errno, $error, 1);
    $result['ready'] = $socket !== false && file_exists('/tmp/repro-results/server-ready');
    if ($socket !== false) {
        fclose($socket);
    }
} elseif (file_exists('/tmp/repro-results/started.json')) {
    $marker = json_decode(file_get_contents('/tmp/repro-results/started.json'), true);
    $stat = @file_get_contents('/proc/'.$marker['pid'].'/stat');
    $fields = $stat === false ? [] : explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $result += [
        'pid' => $marker['pid'],
        'elapsed' => (hrtime(true) - $marker['start']) / 1e9,
        'alive' => isset($fields[19]) && $fields[19] === $marker['birth'],
        'state' => $fields[0] ?? 'absent',
        'returned' => file_exists('/tmp/repro-results/returned'),
    ];
    $result['started'] = true;
}
$result['failed'] = @file_get_contents('/tmp/repro-results/failed') ?: null;
echo json_encode($result, JSON_THROW_ON_ERROR);
'@

    $measurements = @(
        @{ Number = 1; Slowlog = '/proc/self/fd/2'; Catch = 'yes'; Config = 'pool-stream.conf' },
        @{ Number = 2; Slowlog = '/tmp/repro-results/slow.log'; Catch = 'no'; Config = 'pool-file.conf' }
    )
    foreach ($measurement in $measurements) {
        # Полный конфиг без include исключает влияние стандартных www/docker/zz-docker.
        Write-Artifact $measurement.Config @"
[global]
daemonize = no
error_log = /proc/self/fd/2
log_level = notice
[www]
user = www-data
group = www-data
listen = 127.0.0.1:9000
pm = static
pm.max_children = 1
request_terminate_timeout = 10s
request_slowlog_timeout = 2s
slowlog = $($measurement.Slowlog)
catch_workers_output = $($measurement.Catch)
php_admin_value[max_execution_time] = 0
php_admin_value[default_socket_timeout] = 300
"@
    }
    Write-Artifact 'boot.sh' @'
set -eu
mkdir -p /tmp/repro-results
chmod 0777 /tmp/repro-results
php /repro/silent-server.php > /tmp/repro-results/server.log 2>&1 &
exec php-fpm -F -y "/repro/$1"
'@

    foreach ($measurement in $measurements) {
        $name = "repro-fpm-$experimentId-$($measurement.Number)"
        $containers.Add($name)
        Write-ResultLog "Замер $($measurement.Number): slowlog=$($measurement.Slowlog), catch_workers_output=$($measurement.Catch)"
        $run = Invoke-ReproDocker -Arguments @(
            'run', '-d', '--name', $name, '--label', "repro-fpm.owner=$experimentId",
            '--network', 'none', '--cap-add', 'SYS_PTRACE', '--security-opt', 'seccomp=unconfined',
            '--mount', "type=bind,source=$experimentPath,target=/repro,readonly",
            '--entrypoint', 'sh', 'php:8.3-fpm-bookworm', '/repro/boot.sh', $measurement.Config
        )
        Write-ResultLog "Контейнер: $($run.Output)"
        $imageId = Invoke-ReproDocker -Arguments @('inspect', '--format', '{{.Image}}', $name)
        Write-ResultLog "Фактический образ: $($imageId.Output)"
        $destination = Join-Path $experimentPath "measurement-$($measurement.Number)"
        New-Item -ItemType Directory -Path $destination | Out-Null
        $row = [pscustomobject]@{
            Замер = $measurement.Number
            Slowlog = $measurement.Slowlog
            'Catch output' = $measurement.Catch
            'До убийства' = 'замер не состоялся'
            Terminate = $false
            Killed = $false
            SlowTrace = $false
            Valid = $false
        }
        $results.Add($row)
        try {
            $ready = $false
            for ($attempt = 0; $attempt -lt 15; $attempt++) {
                $probe = Invoke-ReproDocker -Arguments @('exec', $name, 'php', '/repro/inspect.php', 'ready')
                if (($probe.Output | ConvertFrom-Json).ready) {
                    $ready = $true
                    break
                }
                Start-Sleep -Seconds 1
            }
            if (-not $ready) { throw 'FPM или молчащий сервер не готовы за 15 с.' }

            Invoke-ReproDocker -Arguments @(
                'exec', '-d', $name, 'sh', '-c',
                'php /repro/fcgi.php > /tmp/repro-results/fcgi.log 2>&1; echo $? > /tmp/repro-results/fcgi.exit'
            ) | Out-Null
            $startWait = [Diagnostics.Stopwatch]::StartNew()
            $snapshot = $null
            $gone = $false
            while ($true) {
                $probe = Invoke-ReproDocker -Arguments @('exec', $name, 'php', '/repro/inspect.php', 'status')
                [IO.File]::AppendAllText((Join-Path $destination 'processes.jsonl'), "$($probe.Output)`n", $utf8)
                $snapshot = $probe.Output | ConvertFrom-Json
                if ($snapshot.failed) { throw "Сетевой запрос не начался: $($snapshot.failed)" }
                if ($snapshot.started) {
                    if ($snapshot.returned) { throw 'Сетевой вызов сам вернулся: зависание не воспроизведено.' }
                    if (-not $snapshot.alive) {
                        $gone = $true
                        break
                    }
                    if ($snapshot.elapsed -ge 60) { break }
                } elseif ($startWait.Elapsed.TotalSeconds -ge 15) {
                    throw 'FastCGI-запрос не дошёл до hang.php за 15 с.'
                }
                Start-Sleep -Seconds 1
            }
            $logs = Invoke-ReproDocker -Arguments @('logs', $name)
            Write-Artifact "fpm-$($measurement.Number).log" $logs.Output
            $row.Terminate = $logs.Output -match "child $($snapshot.pid),.*execution timed out.*terminating"
            $row.Killed = $gone -and $row.Terminate
            $row.'До убийства' = if ($gone) { '{0:N1} с (опрос ~1 с)' -f $snapshot.elapsed } else { 'не убит за 60 с' }
            # Исчезновение без подтверждения FPM не выдаём за действие terminate_timeout.
            $row.Valid = (-not $gone) -or $row.Terminate
            if (-not $row.Valid) { $row.'До убийства' = 'исчез без подтверждения terminate' }
            Write-ResultLog "PID=$($snapshot.pid); $($row.'До убийства'); terminate=$($row.Terminate); состояние=$($snapshot.state)"
        } catch {
            Write-ResultLog "Ошибка замера $($measurement.Number): $($_.Exception.Message)"
        } finally {
            $logs = Invoke-ReproDocker -Arguments @('logs', $name) -AllowFailure
            Write-Artifact "fpm-$($measurement.Number).log" $logs.Output
            $copy = Invoke-ReproDocker -Arguments @('cp', "${name}:/tmp/repro-results/.", $destination) -AllowFailure
            if ($copy.Code -ne 0) { Write-ResultLog "Не удалось сохранить артефакты: $($copy.Output)" }
            $slowPath = Join-Path $destination 'slow.log'
            $slowContent = if (Test-Path -LiteralPath $slowPath) { Get-Content -LiteralPath $slowPath -Raw } else { '' }
            $row.SlowTrace = ($logs.Output + "`n" + $slowContent) -match '\[0x[0-9a-fA-F]+\].*hang\.php'
            Write-ResultLog "Трассировка slowlog записана: $($row.SlowTrace)"
        }
    }
} catch {
    $experimentError = $_.Exception.Message
    Write-Host "Ошибка опыта: $experimentError"
} finally {
    # Никакого prune, Compose и поиска по маске: только имена этого запуска + метка.
    foreach ($name in $containers) {
        try {
            if (-not $name.StartsWith("repro-fpm-$experimentId-")) { throw 'Небезопасное имя контейнера.' }
            $owner = Invoke-ReproDocker -Arguments @('inspect', '--format', '{{index .Config.Labels "repro-fpm.owner"}}', $name) -AllowFailure
            if ($owner.Code -ne 0) {
                # Отсутствующий контейнер убирать не нужно; прочие ошибки не скрываем.
                if ($owner.Output -notmatch 'No such (object|container)') { throw $owner.Output }
                continue
            }
            if ($owner.Output.Trim() -ne $experimentId) { throw 'Метка владельца не совпадает: удаление запрещено.' }
            $stop = Invoke-ReproDocker -Arguments @('stop', '--time', '2', $name) -AllowFailure
            Invoke-ReproDocker -Arguments @('rm', '-f', $name) | Out-Null
        } catch {
            $cleanupFailed = $true
            Write-Host "Ошибка уборки ${name}: $($_.Exception.Message)"
        }
    }
    $table = ($results | Format-Table Замер, Slowlog, 'Catch output', 'До убийства', Terminate, SlowTrace -AutoSize | Out-String).TrimEnd()
    if (Test-Path -LiteralPath $experimentPath) {
        Write-ResultLog $table
        if ($experimentError) { Write-ResultLog "Ошибка опыта: $experimentError" }
        $streamResult = $results | Where-Object { $_.Замер -eq 1 } | Select-Object -First 1
        $validPair = $results.Count -eq 2 -and @($results | Where-Object { -not $_.Valid }).Count -eq 0
        if ($validPair -and -not $experimentError) {
            $fileResult = $results | Where-Object { $_.Замер -eq 2 } | Select-Object -First 1
            if ($streamResult.Killed -eq $fileResult.Killed) {
                Write-ResultLog 'Сравнение: различия в факте убийства между двумя настройками не обнаружено.'
            } else {
                Write-ResultLog 'Сравнение: факт убийства различается. Менялись slowlog и catch_workers_output вместе; отдельная причина этим опытом не доказана.'
            }
            if (@($results | Where-Object { -not $_.SlowTrace }).Count -gt 0) {
                Write-ResultLog 'Внимание: не в обоих замерах подтверждена запись трассировки. Вывод относится к настройке slowlog; влияние успешной записи не доказано.'
            }
            $conclusion = if ($streamResult.Killed) {
                'ВЫВОД: ограничение срабатывает при slowlog в поток'
            } else {
                'ВЫВОД: ограничение не срабатывает при slowlog в поток'
            }
        } else {
            # Ошибку инфраструктуры/ptrace нельзя честно превратить в бинарный результат.
            $conclusion = 'ВЫВОД: результат неопределён — нет двух достоверных замеров'
        }
        [IO.File]::AppendAllText((Join-Path $experimentPath 'results.log'), "$conclusion`n", $utf8)
        if ($KeepArtifacts -or $cleanupFailed) {
            Write-Host "Артефакты сохранены: $experimentPath"
        } else {
            # Проверяем абсолютный путь перед рекурсивным удалением именно своего TEMP.
            try {
                $resolved = (Resolve-Path -LiteralPath $experimentPath).Path
                if ([IO.Path]::GetDirectoryName($resolved).TrimEnd('\') -ne $tempRoot.TrimEnd('\') -or
                    [IO.Path]::GetFileName($resolved) -ne "repro-fpm-$experimentId") {
                    throw 'Каталог уборки вышел за границы опыта.'
                }
                Remove-Item -LiteralPath $resolved -Recurse -Force
            } catch {
                $cleanupFailed = $true
                Write-Host "Ошибка уборки каталога: $($_.Exception.Message); артефакты: $experimentPath"
            }
        }
    } else {
        $conclusion = 'ВЫВОД: результат неопределён — опыт не запустился'
    }
    # Последняя строка всегда содержит фактический вывод, после всей уборки.
    Write-Host $conclusion
}
