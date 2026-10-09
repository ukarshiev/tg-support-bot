#!/bin/bash
# TGSUPBOT-94: shared vzdump hook; installation and job script property by orchestrator.
set -uo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
LOG=/var/log/pve-vzdump-heartbeat.log
log() { printf '%s %s %s\n' "$(date '+%F %T')" "${event:-}" "$*" >> "$LOG"; }
# Best effort only: never expose the secret URL or change the backup result.
heartbeat() (
    set +x
    trap - ERR
    local result=$1 name=$2 conf=$3 mode key value url=''
    if [ ! -f "$conf" ] || [ -L "$conf" ]; then
        log "WARN heartbeat not delivered" || :
        return 0
    fi
    mode=$(stat -c '%a' -- "$conf" 2>/dev/null) || {
        log "WARN heartbeat not delivered" || :
        return 0
    }
    if [[ ! "$mode" =~ ^[0-7]{3,4}$ ]] || (( (8#$mode & 077) != 0 )); then
        log "WARN heartbeat not delivered" || :
        return 0
    fi
    while IFS='=' read -r key value || [ -n "$key" ]; do
        if [ "$key" = "$name" ]; then
            url=$value
            break
        fi
    done < "$conf"
    if [[ ! "$url" =~ ^https://[A-Za-z0-9.-]+/[A-Za-z0-9/_-]+$ ]]; then
        log "WARN heartbeat not delivered" || :
        return 0
    fi
    [ "$result" = 0 ] || url="$url/fail"
    # stdin config keeps the URL out of process arguments; ignore user curl config.
    # The outer timeout also bounds retry delays (including server Retry-After).
    if ! printf 'url = "%s"\n' "$url" | timeout -k 5 75 curl --disable --config - \
        -fsS --max-time 20 --retry 2 --retry-delay 5 > /dev/null 2>&1; then
        log "WARN heartbeat not delivered" || :
    else
        log "heartbeat delivered" || :
    fi
    return 0
) > /dev/null 2>&1
main() {
    local phase=${1:-} store=${STOREID:-} name state=/run/pve-vzdump-heartbeat flag result=0 event
    case "$store" in
        superdata-backup) name=vzdump-vm101-superdata ;;
        synology-backup) name=vzdump-vm101-synology ;;
        *) return 0 ;;
    esac
    case "$phase" in
        job-start|backup-abort|job-abort|job-end) ;;
        *) return 0 ;;
    esac
    flag="$state/$store.failed"
    # Refuse success when this event cannot access the state directory.
    if [ -L "$state" ] || ! mkdir -p -- "$state" || ! chmod 700 -- "$state"; then
        result=1
    fi
    case "$phase" in
        job-start)
            [ "$result" = 0 ] && rm -f -- "$flag"
            return 0
            ;;
        backup-abort)
            [ "$result" = 0 ] && [ ! -L "$flag" ] && : > "$flag"
            return 0
            ;;
        job-abort)
            [ "$result" = 0 ] && [ ! -L "$flag" ] && : > "$flag"
            result=1
            ;;
        job-end)
            [ ! -e "$flag" ] && [ ! -L "$flag" ] || result=1
            ;;
    esac
    if [ "$result" = 0 ]; then
        event="$phase OK $name"
    else
        event="$phase FAIL $name"
    fi
    heartbeat "$result" "$name" /etc/pve-backup/heartbeats.conf
    return 0
}

# A hook error must never abort the actual VM backup or leak diagnostics.
main "$@" > /dev/null 2>&1 || :
exit 0
