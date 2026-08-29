#!/usr/bin/env bash
set -Eeuo pipefail

readonly SERVICES=(app queue reverb scheduler telegram_poller ai_telegram_poller)
readonly HEALTH_SERVICES=(pgdb redis app queue reverb scheduler nginx)
readonly POLLER_HEALTH_SERVICES=(telegram_poller ai_telegram_poller)
# Derived from docker-compose.yml healthchecks: core start_period is at most 30s,
# poller start_period is 60s, and their longest interval is 30s. Each timeout
# covers the start period, three checks, and one extra interval as safety margin.
readonly HEALTHCHECK_INTERVAL_SECONDS=30
readonly REQUIRED_HEALTHCHECK_INTERVALS=3
readonly HEALTHCHECK_WAIT_SAFETY_MARGIN_SECONDS=30
readonly CORE_HEALTHCHECK_START_PERIOD_SECONDS=30
readonly POLLER_HEALTHCHECK_START_PERIOD_SECONDS=60
readonly CORE_READY_TIMEOUT_SECONDS=$((CORE_HEALTHCHECK_START_PERIOD_SECONDS + REQUIRED_HEALTHCHECK_INTERVALS * HEALTHCHECK_INTERVAL_SECONDS + HEALTHCHECK_WAIT_SAFETY_MARGIN_SECONDS))
readonly POLLER_READY_TIMEOUT_SECONDS=$((POLLER_HEALTHCHECK_START_PERIOD_SECONDS + REQUIRED_HEALTHCHECK_INTERVALS * HEALTHCHECK_INTERVAL_SECONDS + HEALTHCHECK_WAIT_SAFETY_MARGIN_SECONDS))
readonly READY_CHECK_INTERVAL_SECONDS=5
readonly READY_PROGRESS_EVERY_ITERATIONS=6
declare -A PREVIOUS_IMAGE_IDS=()
declare -A PREVIOUS_IMAGE_NAMES=()
declare -A PREVIOUS_IMAGE_TAGS=()
PREVIOUS_NGINX_CONFIG=""
HAD_PREVIOUS_NGINX_CONFIG=false
RELEASE_PAUSE_STARTED=false

services_healthy() {
    local service container_id health

    for service in "$@"; do
        container_id="$(docker compose ps -q "$service" 2>/dev/null || true)"
        [[ -n "$container_id" ]] || return 1

        health="$(docker inspect \
            --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
            "$container_id")"
        [[ "$health" == "healthy" || "$health" == "running" ]] || return 1
    done
}

services_ready() {
    services_healthy "${HEALTH_SERVICES[@]}"
}

pollers_ready() {
    services_healthy "${POLLER_HEALTH_SERVICES[@]}"
}

rollback() {
    local exit_code=$?
    set +e
    echo "Release failed; restoring previous application images." >&2

    for service in "${SERVICES[@]}"; do
        if [[ -n "${PREVIOUS_IMAGE_TAGS[$service]:-}" && -n "${PREVIOUS_IMAGE_NAMES[$service]:-}" ]]; then
            docker image tag "${PREVIOUS_IMAGE_TAGS[$service]}" "${PREVIOUS_IMAGE_NAMES[$service]}"
        fi
    done

    if [[ "$HAD_PREVIOUS_NGINX_CONFIG" == true ]]; then
        cp "$PREVIOUS_NGINX_CONFIG" docker/nginx/default.conf
    else
        rm -f docker/nginx/default.conf
    fi

    docker compose up -d --no-build --force-recreate app reverb || true
    docker compose up -d --no-build --force-recreate nginx || true
    if [[ "$RELEASE_PAUSE_STARTED" == true ]]; then
        echo "Rollback: restarting message receivers, queue, and scheduler." >&2
        docker compose up -d --no-build --force-recreate \
            telegram_poller ai_telegram_poller queue scheduler || true
    fi
    docker compose logs --tail=200 app queue nginx || true
    rm -f "$PREVIOUS_NGINX_CONFIG"
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

main_domain="$(sed -n 's/^MAIN_DOMAIN=//p' .env | tail -n 1 | tr -d '\r')"
if [[ ! "$main_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "MAIN_DOMAIN must contain only a valid DNS hostname." >&2
    exit 1
fi

nginx_config="docker/nginx/default.conf"
PREVIOUS_NGINX_CONFIG="$(mktemp)"
if [[ -f "$nginx_config" ]]; then
    cp "$nginx_config" "$PREVIOUS_NGINX_CONFIG"
    HAD_PREVIOUS_NGINX_CONFIG=true
fi

if [[ -n "${NGINX_CONFIG_TEMPLATE:-}" ]]; then
    case "$NGINX_CONFIG_TEMPLATE" in
        docker/nginx/default.conf.template|docker/nginx/default.windows-docker.conf.template)
            nginx_template="$NGINX_CONFIG_TEMPLATE"
            ;;
        *)
            echo "NGINX_CONFIG_TEMPLATE must be one of the bundled templates." >&2
            exit 1
            ;;
    esac
elif [[ -f "/etc/letsencrypt/live/${main_domain}/fullchain.pem" && \
        -f "/etc/letsencrypt/live/${main_domain}/privkey.pem" ]]; then
    nginx_template="docker/nginx/default.conf.template"
else
    nginx_template="docker/nginx/default.windows-docker.conf.template"
fi

if [[ ! -f "$nginx_template" ]]; then
    echo "Missing nginx config template: $nginx_template" >&2
    exit 1
fi

sed "s/__MAIN_DOMAIN__/${main_domain}/g" "$nginx_template" > "${nginx_config}.tmp"
mv "${nginx_config}.tmp" "$nginx_config"

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

for service in "${SERVICES[@]}"; do
    container_id="$(docker compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
        PREVIOUS_IMAGE_IDS[$service]="$(docker inspect --format '{{.Image}}' "$container_id")"
        PREVIOUS_IMAGE_NAMES[$service]="$(docker inspect --format '{{.Config.Image}}' "$container_id")"
        PREVIOUS_IMAGE_TAGS[$service]="tg-support-bot-rollback-${service}:previous"
        docker image tag "${PREVIOUS_IMAGE_IDS[$service]}" "${PREVIOUS_IMAGE_TAGS[$service]}"
    fi
done

docker compose build --pull
echo "Telegram message reception is temporarily paused. Telegram keeps pending updates for 24 hours; accumulated updates will be processed after the pollers restart."
RELEASE_PAUSE_STARTED=true
docker compose stop telegram_poller ai_telegram_poller queue scheduler
docker compose up -d pgdb redis app
docker compose exec -T --user root app sh -lc 'rm -f bootstrap/cache/*.php'
# Limit lock waits to 15s so a blocked migration rolls back promptly; allow up to
# 10 minutes per statement for legitimate production DDL. PGOPTIONS applies only
# to this migration process and does not change normal application connections.
docker compose exec -T \
    -e PGOPTIONS='-c lock_timeout=15s -c statement_timeout=10min' \
    app php artisan migrate --force
docker compose exec -T app php artisan security:external-preflight
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
# queue was stopped before migration and is started with the new image; terminating
# Horizon here is redundant and could turn a normal startup race into a rollback.
docker compose up -d queue reverb scheduler
docker compose up -d --force-recreate nginx

release_ready=false
for ((attempt = 1; attempt <= CORE_READY_TIMEOUT_SECONDS / READY_CHECK_INTERVAL_SECONDS; attempt++)); do
    if services_ready && \
       docker compose exec -T app php artisan about --only=environment >/dev/null && \
       docker compose exec -T queue php artisan horizon:status | grep -qi running; then
        release_ready=true
        break
    fi
    sleep "$READY_CHECK_INTERVAL_SECONDS"
    if ((attempt % READY_PROGRESS_EVERY_ITERATIONS == 0)); then
        elapsed_seconds=$((attempt * READY_CHECK_INTERVAL_SECONDS))
        echo "Still waiting for core services: ${elapsed_seconds}/${CORE_READY_TIMEOUT_SECONDS} seconds."
    fi
done

if [[ "$release_ready" != true ]]; then
    echo "Core services did not become ready in ${CORE_READY_TIMEOUT_SECONDS} seconds; pollers remain stopped." >&2
    false
fi

docker compose up -d telegram_poller ai_telegram_poller

pollers_healthy=false
for ((attempt = 1; attempt <= POLLER_READY_TIMEOUT_SECONDS / READY_CHECK_INTERVAL_SECONDS; attempt++)); do
    if pollers_ready; then
        pollers_healthy=true
        break
    fi
    sleep "$READY_CHECK_INTERVAL_SECONDS"
    if ((attempt % READY_PROGRESS_EVERY_ITERATIONS == 0)); then
        elapsed_seconds=$((attempt * READY_CHECK_INTERVAL_SECONDS))
        echo "Still waiting for Telegram pollers: ${elapsed_seconds}/${POLLER_READY_TIMEOUT_SECONDS} seconds."
    fi
done

if [[ "$pollers_healthy" != true ]]; then
    echo "Telegram pollers did not become healthy in ${POLLER_READY_TIMEOUT_SECONDS} seconds." >&2
    false
fi

rm -f "$PREVIOUS_NGINX_CONFIG"
trap - ERR
echo "Release completed successfully. Backup: $backup_file"
echo "Backup SHA-256: $backup_checksum"
