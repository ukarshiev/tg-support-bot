#!/usr/bin/env bash
set -Eeuo pipefail

readonly SERVICES=(app queue reverb scheduler telegram_poller ai_telegram_poller)
declare -A PREVIOUS_IMAGE_IDS=()
declare -A PREVIOUS_IMAGE_NAMES=()

rollback() {
    local exit_code=$?
    set +e
    echo "Release failed; restoring previous application images." >&2

    for service in "${SERVICES[@]}"; do
        if [[ -n "${PREVIOUS_IMAGE_IDS[$service]:-}" && -n "${PREVIOUS_IMAGE_NAMES[$service]:-}" ]]; then
            docker image tag "${PREVIOUS_IMAGE_IDS[$service]}" "${PREVIOUS_IMAGE_NAMES[$service]}"
        fi
    done

    docker compose up -d --no-build --force-recreate "${SERVICES[@]}" nginx || true
    docker compose logs --tail=200 app queue nginx || true
    exit "$exit_code"
}

trap rollback ERR

if [[ ! -f .env ]]; then
    echo "Missing .env file." >&2
    exit 1
fi

app_key="$(sed -n 's/^APP_KEY=//p' .env | tail -n 1)"
if [[ -z "$app_key" || "$app_key" == *YOUR_APP_KEY_HERE* ]]; then
    echo "APP_KEY must be generated once before deployment." >&2
    exit 1
fi

for service in "${SERVICES[@]}"; do
    container_id="$(docker compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
        PREVIOUS_IMAGE_IDS[$service]="$(docker inspect --format '{{.Image}}' "$container_id")"
        PREVIOUS_IMAGE_NAMES[$service]="$(docker inspect --format '{{.Config.Image}}' "$container_id")"
    fi
done

umask 077
REPO_ROOT="$(pwd -P)"
readonly REPO_ROOT
BACKUP_DIR="${BACKUP_DIR:-../tg-support-bot-backups}"
mkdir -p "$BACKUP_DIR"
BACKUP_ROOT="$(cd "$BACKUP_DIR" && pwd -P)"
readonly BACKUP_ROOT

case "$BACKUP_ROOT/" in
    "$REPO_ROOT/"*)
        echo "BACKUP_DIR must be outside the repository." >&2
        exit 1
        ;;
esac

chmod 700 "$BACKUP_ROOT"
backup_file="$BACKUP_ROOT/pre-release-$(date -u +%Y%m%dT%H%M%SZ).sql"
docker compose exec -T pgdb sh -lc 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$backup_file"
test -s "$backup_file"
chmod 600 "$backup_file"
backup_checksum="$(sha256sum "$backup_file" | awk '{print $1}')"

docker compose build --pull
docker compose up --no-deps assets_init
docker compose up -d pgdb redis app
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan security:external-preflight
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
docker compose up -d queue reverb scheduler telegram_poller ai_telegram_poller nginx
docker compose exec -T queue php artisan horizon:terminate

for _ in {1..12}; do
    if docker compose exec -T app php artisan about --only=environment >/dev/null && \
       docker compose exec -T queue php artisan horizon:status | grep -qi running; then
        trap - ERR
        echo "Release completed successfully. Backup: $backup_file"
        echo "Backup SHA-256: $backup_checksum"
        exit 0
    fi
    sleep 5
done

echo "Services did not become ready in 60 seconds." >&2
false
