#!/bin/bash
# Linux-версия repro-fpm-terminate-timeout.ps1. Запуск: bash scripts/repro-fpm-terminate-timeout.sh [--keep-artifacts]
# Только свои контейнеры; Compose, проект, БД и образы не изменяются.
# SYS_PTRACE + seccomp=unconfined нужны для трассировки slowlog в обоих замерах.
# Это условие опыта, а не точная копия ограничений безопасности production.
# Тег образа не фиксирует digest: фактический ID образа сохраняется в журнале.
set -Eeuo pipefail
umask 077

keep_artifacts=false
experiment_path=''
created_experiment_path=''
experiment_temp_root=''
experiment_id=''
experiment_error=false
cleanup_failed=false
containers=()
numbers=()
declare -a slowlogs catches durations terminating killed traces valid destinations
for number in 1 2; do
    durations[$number]='замер не состоялся'
    terminating[$number]=false
    killed[$number]=false
    traces[$number]=false
    valid[$number]=false
done

log() {
    printf '%s\n' "$*"
    if [[ -n $experiment_path && -d $experiment_path ]]; then
        printf '%s\n' "$*" >> "$experiment_path/results.log"
    fi
}

repro_docker() {
    # Проверяем реальный код Docker, сохраняем stderr вместе со stdout.
    if docker_output=$(docker "$@" 2>&1); then
        return 0
    else
        docker_code=$?
        log "Docker завершился с кодом $docker_code: $docker_output"
        return "$docker_code"
    fi
}

safe_name() {
    case "$1" in
        pet|pgdb|nginx|laravel_queue|telegram_poller|ai_telegram_poller|tg_support_redis) return 1 ;;
    esac
    [[ -n $experiment_id ]] &&
        [[ $1 == "repro-fpm-$experiment_id-1" || $1 == "repro-fpm-$experiment_id-2" ]]
}

finish() {
    local exit_code=$? name owner container_id inspection conclusion number table_line
    trap - EXIT ERR INT TERM
    set +e
    if (( exit_code != 0 )); then experiment_error=true; fi
    # Никакого prune, Compose и поиска по маске: только имена этого запуска + метка.
    for name in "${containers[@]}"; do
        if ! safe_name "$name"; then
            cleanup_failed=true
            log "Ошибка уборки: небезопасное имя $name"
            continue
        fi
        if ! inspection=$(docker inspect --format '{{.Id}} {{index .Config.Labels "repro-fpm.owner"}}' "$name" 2>&1); then
            if [[ ! $inspection =~ No\ such\ (object|container) ]]; then
                cleanup_failed=true
                log "Ошибка уборки $name: $inspection"
            fi
            continue
        fi
        read -r container_id owner <<< "$inspection"
        if [[ $owner != "$experiment_id" || ! $container_id =~ ^[0-9a-f]{64}$ ]]; then
            cleanup_failed=true
            log "Ошибка уборки $name: метка владельца не совпадает; удаление запрещено."
            continue
        fi
        # Полный ID исключает подмену контейнера с тем же именем между проверкой и удалением.
        repro_docker stop --time 2 "$container_id" || :
        if ! repro_docker inspect --format '{{index .Config.Labels "repro-fpm.owner"}}' "$container_id" ||
            [[ $docker_output != "$experiment_id" ]]; then
            cleanup_failed=true
            log "Ошибка уборки $name: повторная проверка владельца не пройдена."
            continue
        fi
        if ! repro_docker rm -f "$container_id"; then cleanup_failed=true; fi
    done

    log 'Замер | Slowlog | Catch output | До убийства | Terminate | SlowTrace'
    for number in "${numbers[@]}"; do
        printf -v table_line '%s | %s | %s | %s | %s | %s' "$number" \
            "${slowlogs[$number]}" "${catches[$number]}" "${durations[$number]}" \
            "${terminating[$number]}" "${traces[$number]}"
        log "$table_line"
    done
    conclusion='ВЫВОД: результат неопределён'
    if [[ ${#numbers[@]} == 2 && ${valid[1]} == true && ${valid[2]} == true && $experiment_error == false ]]; then
        if [[ ${killed[1]} == "${killed[2]}" ]]; then
            log 'Сравнение: различия в факте убийства между двумя настройками не обнаружено.'
        else
            log 'Сравнение: факт убийства различается. Менялись slowlog и catch_workers_output вместе; отдельная причина этим опытом не доказана.'
        fi
        if [[ ${traces[1]} != true || ${traces[2]} != true ]]; then
            log 'Внимание: не в обоих замерах подтверждена запись трассировки. Вывод относится к настройке slowlog; влияние успешной записи не доказано.'
        fi
        if [[ ${killed[1]} == true ]]; then
            conclusion='ВЫВОД: ограничение срабатывает при slowlog в поток'
        else
            conclusion='ВЫВОД: ограничение не срабатывает при slowlog в поток'
        fi
    fi
    if [[ -n $experiment_path && -d $experiment_path ]]; then
        printf '%s\n' "$conclusion" >> "$experiment_path/results.log"
        if [[ $keep_artifacts == true || $cleanup_failed == true ]]; then
            printf 'Артефакты сохранены: %s\n' "$experiment_path"
        else
            # Только точный путь, полученный из mktemp, непосредственно внутри TEMP.
            # Проверка родителя исключает вложенные и чужие пути; ссылки не удаляем.
            if [[ -n $experiment_temp_root && -n $created_experiment_path &&
                $experiment_path == "$created_experiment_path" &&
                $experiment_path == "${experiment_temp_root%/}/repro-fpm-"* &&
                ${experiment_path%/*} == "${experiment_temp_root%/}" &&
                ${experiment_path##*/} == "repro-fpm-$experiment_id" &&
                ! -L $experiment_path ]]; then
                if ! rm -rf -- "$experiment_path"; then
                    cleanup_failed=true
                    printf 'Ошибка уборки каталога; артефакты: %s\n' "$experiment_path"
                fi
            else
                cleanup_failed=true
                printf 'Удаление запрещено: каталог уборки вышел за границы опыта; артефакты: %s\n' "$experiment_path"
            fi
        fi
    fi
    # Последняя строка всегда содержит фактический вывод, после всей уборки.
    printf '%s\n' "$conclusion"
    exit "$exit_code"
}
trap finish EXIT
trap 'error_code=$?; experiment_error=true; log "Ошибка опыта: строка $LINENO, код $error_code"; exit 1' ERR
trap 'experiment_error=true; log "Опыт прерван сигналом INT"; exit 130' INT
trap 'experiment_error=true; log "Опыт прерван сигналом TERM"; exit 143' TERM

for argument in "$@"; do
    case "$argument" in
        --keep-artifacts) keep_artifacts=true ;;
        *) log "Неизвестный аргумент: $argument"; exit 2 ;;
    esac
done
command -v docker >/dev/null || { log 'Не найдена команда docker.'; exit 1; }
experiment_temp_root=$(cd -- "${TMPDIR:-/tmp}" && pwd -P)
experiment_path=$(mktemp -d "${experiment_temp_root%/}/repro-fpm-XXXXXXXXXXXX")
created_experiment_path=$experiment_path
experiment_id=${experiment_path##*/repro-fpm-}
[[ $experiment_id =~ ^[a-zA-Z0-9]+$ ]] || { log 'Небезопасный идентификатор опыта.'; exit 1; }
experiment_parent=${experiment_path%/*}
printf '%s\n' "$experiment_id" > "$experiment_path/owner"
log "Каталог опыта: $experiment_path"
log 'FastCGI: встроенный PHP-клиент; apt не запускается, сеть контейнеров отключена.'
log 'Зависание: TCP-запрос к молчащему серверу 127.0.0.1:18080. Таймаут чтения 300 с.'
log 'В обоих замерах: SYS_PTRACE + seccomp=unconfined для записи трассировки slowlog.'

write_artifact() {
    # Bash читает heredoc без cat; файлы получают LF и UTF-8 без BOM.
    local line
    while IFS= read -r line || [[ -n $line ]]; do
        printf '%s\n' "$line"
    done > "$experiment_path/$1"
}

write_artifact 'hang.php' <<'REPRO_ARTIFACT_EOF'
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
REPRO_ARTIFACT_EOF

write_artifact 'silent-server.php' <<'REPRO_ARTIFACT_EOF'
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
REPRO_ARTIFACT_EOF

write_artifact 'fcgi.php' <<'REPRO_ARTIFACT_EOF'
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
REPRO_ARTIFACT_EOF

write_artifact 'inspect.php' <<'REPRO_ARTIFACT_EOF'
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
REPRO_ARTIFACT_EOF

write_artifact 'boot.sh' <<'REPRO_ARTIFACT_EOF'
set -eu
mkdir -p /tmp/repro-results
chmod 0777 /tmp/repro-results
php /repro/silent-server.php > /tmp/repro-results/server.log 2>&1 &
exec php-fpm -F -y "/repro/$1"
REPRO_ARTIFACT_EOF

for number in 1 2; do
    if (( number == 1 )); then
        slowlogs[$number]='/proc/self/fd/2'
        catches[$number]=yes
    else
        slowlogs[$number]='/tmp/repro-results/slow.log'
        catches[$number]=no
    fi
    # Полный конфиг без include исключает влияние стандартных www/docker/zz-docker.
    write_artifact "pool-$number.conf" <<EOF
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
slowlog = ${slowlogs[$number]}
catch_workers_output = ${catches[$number]}
php_admin_value[max_execution_time] = 0
php_admin_value[default_socket_timeout] = 300
EOF
done

# mktemp и umask закрывают доступ uid 33. Открываем вход и чтение только файлов опыта.
# Без рекурсии: журнал и файл владельца сохраняют закрытые права.
chmod 755 -- "$experiment_path"
chmod 644 -- "$experiment_path/hang.php" "$experiment_path/silent-server.php" \
    "$experiment_path/fcgi.php" "$experiment_path/inspect.php" "$experiment_path/boot.sh" \
    "$experiment_path/pool-1.conf" "$experiment_path/pool-2.conf"

pause_poll() {
    # Штатный PHP внутри своего контейнера заменяет sleep на хосте.
    # Это только пауза опроса; hang.php зависает именно на чтении TCP.
    repro_docker exec "$1" php -r 'usleep(1000000);'
}

measure() {
    local number=$1 name=$2 destination=$3 attempt ready=false start_wait snapshot
    local started alive returned elapsed pid state gone=false
    for (( attempt=0; attempt<15; attempt++ )); do
        repro_docker exec "$name" php /repro/inspect.php ready || return 1
        if [[ $docker_output == *'"ready":true'* ]]; then ready=true; break; fi
        pause_poll "$name" || return 1
    done
    if [[ $ready != true ]]; then log 'FPM или молчащий сервер не готовы за 15 с.'; return 1; fi
    repro_docker exec -d "$name" sh -c \
        'php /repro/fcgi.php > /tmp/repro-results/fcgi.log 2>&1; echo $? > /tmp/repro-results/fcgi.exit' || return 1
    start_wait=$SECONDS
    while :; do
        repro_docker exec "$name" php /repro/inspect.php status || return 1
        snapshot=$docker_output
        printf '%s\n' "$snapshot" >> "$destination/processes.jsonl" || return 1
        if [[ $snapshot != *'"failed":null'* ]]; then
            log "Сетевой запрос не начался или ответ диагностики некорректен: $snapshot"; return 1
        fi
        started=$(sed -nE 's/.*"started":(true|false).*/\1/p' <<< "$snapshot")
        if [[ $started == true ]]; then
            alive=$(sed -nE 's/.*"alive":(true|false).*/\1/p' <<< "$snapshot")
            returned=$(sed -nE 's/.*"returned":(true|false).*/\1/p' <<< "$snapshot")
            elapsed=$(sed -nE 's/.*"elapsed":([0-9.eE+-]+).*/\1/p' <<< "$snapshot")
            pid=$(sed -nE 's/.*"pid":([0-9]+).*/\1/p' <<< "$snapshot")
            state=$(sed -nE 's/.*"state":"([^"]*)".*/\1/p' <<< "$snapshot")
            if [[ ! $pid =~ ^[0-9]+$ || ! $elapsed =~ ^[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$ ||
                ! $alive =~ ^(true|false)$ || ! $returned =~ ^(true|false)$ ]]; then
                log 'Некорректный снимок процесса.'; return 1
            fi
            if [[ $returned == true ]]; then
                log 'Сетевой вызов сам вернулся: зависание не воспроизведено.'; return 1
            fi
            if [[ $alive == false ]]; then gone=true; break; fi
            if awk -v elapsed="$elapsed" 'BEGIN { exit !(elapsed >= 60) }'; then break; fi
        elif [[ $started != false ]]; then
            log 'Некорректный признак начала запроса.'; return 1
        elif (( SECONDS - start_wait >= 15 )); then
            log 'FastCGI-запрос не дошёл до hang.php за 15 с.'; return 1
        fi
        pause_poll "$name" || return 1
    done
    repro_docker logs "$name" || return 1
    printf '%s\n' "$docker_output" > "$experiment_path/fpm-$number.log" || return 1
    if grep -Eq "child $pid,.*execution timed out.*terminating" <<< "$docker_output"; then
        terminating[$number]=true
    fi
    if [[ $gone == true ]]; then
        durations[$number]="$(awk -v elapsed="$elapsed" 'BEGIN { printf "%.1f", elapsed }') с (опрос ~1 с)"
        if [[ ${terminating[$number]} == true ]]; then
            killed[$number]=true
            valid[$number]=true
        else
            # Исчезновение без подтверждения FPM не выдаём за terminate_timeout.
            durations[$number]='исчез без подтверждения terminate'
        fi
    else
        durations[$number]='не убит за 60 с'
        valid[$number]=true
    fi
    log "PID=$pid; ${durations[$number]}; terminate=${terminating[$number]}; состояние=$state"
}

for number in 1 2; do
    name="repro-fpm-$experiment_id-$number"
    containers+=("$name")
    log "Замер $number: slowlog=${slowlogs[$number]}, catch_workers_output=${catches[$number]}"
    repro_docker run -d --name "$name" --label "repro-fpm.owner=$experiment_id" \
        --network none --cap-add SYS_PTRACE --security-opt seccomp=unconfined \
        --mount "type=bind,source=$experiment_path,target=/repro,readonly" \
        --entrypoint sh php:8.3-fpm-bookworm /repro/boot.sh "pool-$number.conf"
    log "Контейнер: $docker_output"
    repro_docker inspect --format '{{.Image}}' "$name"
    log "Фактический образ: $docker_output"
    destinations[$number]=$(mktemp -d "$experiment_path/measurement-$number-XXXXXXXX")
    chmod 755 -- "${destinations[$number]}"
    numbers+=("$number")
    if ! measure "$number" "$name" "${destinations[$number]}"; then
        valid[$number]=false
        killed[$number]=false
        log "Ошибка замера $number: достоверный результат не получен."
    fi
    # Сохраняем диагностику и при ошибке замера, до остановки контейнера.
    repro_docker logs "$name" || :
    printf '%s\n' "$docker_output" > "$experiment_path/fpm-$number.log"
    if ! repro_docker cp "$name:/tmp/repro-results/." "${destinations[$number]}"; then
        log "Не удалось сохранить артефакты: $docker_output"
    fi
    if grep -Eq '\[0x[0-9a-fA-F]+\].*hang\.php' "$experiment_path/fpm-$number.log" ||
        { [[ -f ${destinations[$number]}/slow.log ]] &&
            grep -Eq '\[0x[0-9a-fA-F]+\].*hang\.php' "${destinations[$number]}/slow.log"; }; then
        traces[$number]=true
    fi
    log "Трассировка slowlog записана: ${traces[$number]}"
done
