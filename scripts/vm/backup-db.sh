#!/bin/bash
# Cron: 0 23 * * * /opt/tg-support-bot/scripts/backup-db.sh >/dev/null 2>&1
# 23:00 UTC = 02:00 MSK, 30 minutes before the VM backup.
# FORCE_RESTORE_TEST=1 runs the restore test on any day.
# Run on the VM as karshiev. Never change the production database.
set -uo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Suppress raw diagnostics, which may contain credentials or data.
exec >/dev/null 2>&1
root=/opt/tg-support-bot
daily="$root/backups/daily"
log_file="$root/backups/backup-db.log"
lock_file="$root/backups/.backup-db.lock"
parts=()
container=''
complete=0
log_ready=0
stage=initialization
current_file=-
current_bytes=0

log() {
    local timestamp
    (( log_ready )) || return 1
    timestamp=$(date '+%Y-%m-%d %H:%M:%S') || return 1
    printf '%s %s %s %s %s\n' "$timestamp" "$1" "$2" "$3" "$4" >> "$log_file"
}

# Best effort only: never expose the secret URL or change the backup result.
heartbeat() (
    set +x
    trap - ERR
    local result=$1 name=$2 conf=$3 mode key value url=''
    if [ ! -f "$conf" ] || [ -L "$conf" ]; then
        log WARN heartbeat 'not delivered' 0 || :
        return 0
    fi
    mode=$(stat -c '%a' -- "$conf" 2>/dev/null) || {
        log WARN heartbeat 'not delivered' 0 || :
        return 0
    }
    if [[ ! "$mode" =~ ^[0-7]{3,4}$ ]] || (( (8#$mode & 077) != 0 )); then
        log WARN heartbeat 'not delivered' 0 || :
        return 0
    fi
    while IFS='=' read -r key value || [ -n "$key" ]; do
        if [ "$key" = "$name" ]; then
            url=$value
            break
        fi
    done < "$conf"
    if [[ ! "$url" =~ ^https://[A-Za-z0-9.-]+/[A-Za-z0-9/_-]+$ ]]; then
        log WARN heartbeat 'not delivered' 0 || :
        return 0
    fi
    [ "$result" = 0 ] || url="$url/fail"
    # stdin config keeps the URL out of process arguments; ignore user curl config.
    # The outer timeout also bounds retry delays (including server Retry-After).
    if ! printf 'url = "%s"\n' "$url" | timeout -k 5 75 curl --disable --config - \
        -fsS --max-time 20 --retry 2 --retry-delay 5 > /dev/null 2>&1; then
        log WARN heartbeat 'not delivered' 0 || :
    fi
    return 0
) > /dev/null 2>&1
fail() {
    stage=$1
    exit 1
}

cleanup() {
    local result=$? cleanup_failed=0 part
    trap - EXIT
    trap '' HUP INT TERM
    for part in "${parts[@]}"; do
        rm -f -- "$part" || cleanup_failed=1
    done
    if [[ -n "$container" ]]; then
        docker rm -f "$container" < /dev/null || cleanup_failed=1
    fi
    if (( cleanup_failed )); then
        stage=cleanup
        result=1
    fi
    if (( ! complete || result != 0 )); then
        log FAIL "$stage" "$current_file" "$current_bytes" || :
        result=1
    fi
    if [ "$stage" != already_running ]; then
        heartbeat "$result" vm-backup-db /opt/tg-support-bot/backups/heartbeats.conf || :
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'fail interrupted' HUP INT TERM

# Refuse symlink destinations and keep all host writes under the project.
[[ ! -L "$root/backups" && ! -L "$daily" && ! -L "$log_file" && ! -L "$lock_file" ]] || fail unsafe_paths
mkdir -p -- "$daily" || fail directories
touch -- "$log_file" || fail log_open
chmod 600 -- "$log_file" || fail log_permissions
log_ready=1
exec 9>"$lock_file" || fail lock_open
flock -n 9 || fail already_running
chmod 600 -- "$lock_file" || fail lock_permissions
cd -- "$root" || fail project_directory

# Read only the required keys; never source or print .env.
read_env() {
    local value
    value=$(sed -n "/^${1}=/ { s/^${1}=//; s/\r$//; p; q; }" .env) || return 1
    if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then
        value=${value:1:${#value}-2}
    fi
    printf '%s' "$value"
}
db_name=$(read_env DB_DATABASE) || fail database_configuration
db_user=$(read_env DB_USERNAME) || fail database_configuration
[[ "$db_name" == tg_support_bot && -n "$db_user" ]] || fail database_configuration
stamp=$(date -u '+%Y%m%d-%H%M%S') || fail timestamp
weekday=$(date '+%u') || fail timestamp
dump_name="tg_support_bot-$stamp.dump"
storage_name="storage-$stamp.tar.gz"
dump_path="$daily/$dump_name"
storage_path="$daily/$storage_name"
status_path="$daily/last-success.json"

for target in "$dump_path" "$storage_path" "$dump_path.sha256" "$storage_path.sha256"; do
    [[ ! -e "$target" && ! -L "$target" && ! -e "$target.part" && ! -L "$target.part" ]] || fail filename_collision
done
[[ ! -L "$status_path" && ! -e "$status_path.part" && ! -L "$status_path.part" ]] || fail status_path
parts=("$dump_path.part" "$storage_path.part" "$dump_path.sha256.part" "$storage_path.sha256.part" "$status_path.part")

# Preserve the last successful restore date on ordinary nights.
restore_json=null
if [[ -e "$status_path" ]]; then
    previous=$(sed -n 's/^[[:space:]]*"restore_test":[[:space:]]*\(null\|"[^"]*"\)[[:space:]]*,\{0,1\}[[:space:]]*$/\1/p' "$status_path") || fail status_read
    if [[ "$previous" == null || "$previous" =~ ^\"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\"$ ]]; then
        restore_json=$previous
    else
        fail status_invalid
    fi
fi

stage=dump
current_file=$dump_name
docker compose exec -T pgdb pg_dump -U "$db_user" -d "$db_name" -Fc < /dev/null > "$dump_path.part" || fail dump
[[ -s "$dump_path.part" ]] || fail dump_empty
listing=$(docker compose exec -T pgdb pg_restore --list < "$dump_path.part") || fail dump_list
tables=$(grep -c ' TABLE DATA ' <<< "$listing") || fail dump_tables
[[ "$tables" =~ ^[0-9]+$ ]] && (( tables > 0 )) || fail dump_tables
unset listing
chmod 600 -- "$dump_path.part" || fail dump_permissions
mv -- "$dump_path.part" "$dump_path" || fail dump_publish
dump_bytes=$(stat -c '%s' -- "$dump_path") || fail dump_size
current_bytes=$dump_bytes
log OK dump_checked "$dump_name" "$dump_bytes" || fail log_write

stage=storage
current_file=$storage_name
current_bytes=0
docker compose exec -T app tar -czf - -C /var/www/storage app certs < /dev/null > "$storage_path.part" || fail storage
[[ -s "$storage_path.part" ]] || fail storage_empty
tar -tzf "$storage_path.part" > /dev/null || fail storage_list
chmod 600 -- "$storage_path.part" || fail storage_permissions
mv -- "$storage_path.part" "$storage_path" || fail storage_publish
storage_bytes=$(stat -c '%s' -- "$storage_path") || fail storage_size
current_bytes=$storage_bytes
log OK storage_checked "$storage_name" "$storage_bytes" || fail log_write

write_checksum() {
    local path=$1 name=$2 hash
    hash=$(sha256sum -- "$path") || return 1
    hash=${hash%% *}
    [[ "$hash" =~ ^[a-f0-9]{64}$ ]] || return 1
    printf '%s  %s\n' "$hash" "$name" > "$path.sha256.part" || return 1
    chmod 600 -- "$path.sha256.part" || return 1
    mv -- "$path.sha256.part" "$path.sha256"
}
write_checksum "$dump_path" "$dump_name" || fail dump_checksum
write_checksum "$storage_path" "$storage_name" || fail storage_checksum

if [[ "$weekday" == 7 || "${FORCE_RESTORE_TEST:-0}" == 1 ]]; then
    stage=restore_test
    current_file=$dump_name
    current_bytes=$dump_bytes
    # Use the exact running image, without pulling an image or using volumes.
    pg_container=$(docker compose ps -q pgdb < /dev/null) || fail restore_image
    [[ -n "$pg_container" && "$pg_container" != *$'\n'* ]] || fail restore_image
    image=$(docker inspect --format '{{.Image}}' "$pg_container" < /dev/null) || fail restore_image
    [[ "$image" =~ ^sha256:[a-f0-9]{64}$ ]] || fail restore_image
    IFS= read -r uuid < /proc/sys/kernel/random/uuid || fail restore_identity
    [[ "$uuid" =~ ^[a-f0-9-]{36}$ ]] || fail restore_identity
    container="tgsb-restore-test-$uuid"
    IFS= read -r password < /proc/sys/kernel/random/uuid || fail restore_password
    [[ "$password" =~ ^[a-f0-9-]{36}$ ]] || fail restore_password
    password=${password//-/}
    docker run -d --rm --pull=never --network none --name "$container" \
        --tmpfs /var/lib/postgresql -e "POSTGRES_PASSWORD=$password" "$image" < /dev/null || fail restore_start
    unset password
    ready=0
    deadline=$((SECONDS + 60))
    while (( SECONDS < deadline )); do
        # Plain Docker exec allocates no TTY unless -t is requested.
        if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres < /dev/null; then
            ready=1
            break
        fi
        sleep 1 || fail restore_wait
    done
    (( ready )) || fail restore_timeout
    docker exec "$container" createdb -U postgres restore_check < /dev/null || fail restore_database
    # Only this isolated restore intentionally receives the dump on stdin.
    docker exec -i "$container" pg_restore -U postgres --exit-on-error --no-owner \
        --no-privileges -d restore_check < "$dump_path" || fail restore_import
    query="SELECT (SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'), (SELECT count(*) FROM public.messages);"
    counts=$(docker exec "$container" psql -U postgres -d restore_check -X -A -t \
        -v ON_ERROR_STOP=1 -c "$query" < /dev/null) || fail restore_counts
    [[ "$counts" =~ ^([0-9]+)\|([0-9]+)$ ]] || fail restore_counts
    (( BASH_REMATCH[1] == tables && BASH_REMATCH[2] > 0 )) || fail restore_counts
    docker rm -f "$container" < /dev/null || fail restore_cleanup
    container=''
    restore_time=$(date -u '+%Y-%m-%dT%H:%M:%SZ') || fail timestamp
    restore_json="\"$restore_time\""
    log OK restore_checked "$dump_name" "$dump_bytes" || fail log_write
fi

# Timestamp names sort chronologically; prune each type independently.
retain() {
    local kind=$1 file name limit index
    local -a files=()
    shopt -s nullglob
    for file in "$daily"/$kind; do
        name=${file##*/}
        [[ -f "$file" && ! -L "$file" ]] || continue
        if [[ "$name" =~ ^tg_support_bot-[0-9]{8}-[0-9]{6}\.dump$ || "$name" =~ ^storage-[0-9]{8}-[0-9]{6}\.tar\.gz$ ]]; then
            files+=("$file")
        fi
    done
    limit=$((${#files[@]} - 14))
    for ((index=0; index<limit; index++)); do
        file=${files[index]}
        [[ ! -L "$file.sha256" ]] || return 1
        rm -f -- "$file" "$file.sha256" || return 1
    done
}
stage=retention
retain 'tg_support_bot-*.dump' || fail dump_retention
retain 'storage-*.tar.gz' || fail storage_retention

stage=success_record
completed_time=$(date -u '+%Y-%m-%dT%H:%M:%SZ') || fail timestamp
printf '{\n  "time": "%s",\n  "dump": "%s",\n  "dump_bytes": %s,\n  "storage": "%s",\n  "storage_bytes": %s,\n  "restore_test": %s\n}\n' \
    "$completed_time" "$dump_name" "$dump_bytes" "$storage_name" "$storage_bytes" "$restore_json" \
    > "$status_path.part" || fail status_write
chmod 600 -- "$status_path.part" || fail status_permissions
# Remove owned temporary paths before publishing the success marker.
for part in "${parts[@]}"; do
    [[ "$part" == "$status_path.part" ]] && continue
    rm -f -- "$part" || fail cleanup
done
log OK backup_complete "$dump_name" "$dump_bytes" || fail log_write
mv -f -- "$status_path.part" "$status_path" || fail status_publish
parts=()
complete=1
exit 0
