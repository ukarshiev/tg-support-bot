#!/bin/bash
# Nightly copy of the Proxmox host settings (not the VMs: those are vzdump jobs).
# Local copy: /superdata/share/pve-backup/host; second copy: Synology "2.9 Backups/Proxmox".
# Restore notes: reinstall Proxmox, then take files from the archive (etc/, pve-cluster/config.db).
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOCAL_DIR=/superdata/share/pve-backup/host
NFS_SRC='192.168.0.101:/volume2/2.9 Backups/Proxmox'
NFS_MNT=/mnt/synology-pve-host
KEEP=30
LOG=/var/log/pve-host-backup.log

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
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
    fi
    return 0
) > /dev/null 2>&1
trap 'log "FAIL command at line $LINENO"' ERR
exec 9>/run/pve-host-backup.lock
flock -n 9 || { log "FAIL another host backup is running"; exit 1; }
trap 'rc=$?; trap - EXIT ERR; heartbeat "$rc" pve-host-backup /etc/pve-backup/heartbeats.conf || :; exit "$rc"' EXIT
# Never write a large backup into the host root when the ZFS pool is absent.
mountpoint -q /superdata && [ "$(findmnt -rn -M /superdata -o FSTYPE)" = zfs ] \
    || { log "FAIL superdata is not mounted as ZFS"; exit 1; }
NFS_OWNED=0
exec 8>/run/pve-host-nfs.lock

STAMP=$(date +%Y%m%d-%H%M%S)
NAME="pve-settings-$STAMP.tar.zst"
WORK=$(mktemp -d /var/tmp/pve-host-backup.XXXXXX)
cleanup() {
    rc=$?
    trap - EXIT ERR
    set +e
    if [ "$NFS_OWNED" = 1 ] && findmnt -rn -C -M "$NFS_MNT" > /dev/null; then
        timeout -k 5 15 rm -f -- "$NFS_MNT/$NAME.part" >> "$LOG" 2>&1 || rc=1
        timeout -k 5 30 umount "$NFS_MNT" >> "$LOG" 2>&1 || {
            timeout -k 5 10 umount -l "$NFS_MNT" >> "$LOG" 2>&1 || :
            rc=1
        }
    fi
    rm -rf -- "$WORK" "$LOCAL_DIR/$NAME.part" || rc=1
    [ "$rc" = 0 ] || log "FAIL run or cleanup; check NFS mount"
    heartbeat "$rc" pve-host-backup /etc/pve-backup/heartbeats.conf || :
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

mkdir -p "$WORK/info" "$WORK/pve-cluster" "$LOCAL_DIR"
info() { # Optional inventory: an offline storage must not prevent a local copy.
    local file=$1
    shift
    timeout -k 5 30 "$@" > "$WORK/info/$file" 2>&1 \
        || log "WARN inventory $file unavailable or timed out"
}
info pveversion.txt pveversion -v
info dpkg-selections.txt dpkg --get-selections
info apt-manual.txt apt-mark showmanual
info zpool-status.txt zpool status
info zfs-list.txt zfs list -o name,used,avail,mountpoint
info lsblk.txt lsblk -o NAME,SIZE,TYPE,FSTYPE,MOUNTPOINT,SERIAL
info ip-addr.txt ip -br addr
info qm-list.txt qm list
info pvesm-status.txt pvesm status
timeout -k 5 60 sqlite3 /var/lib/pve-cluster/config.db ".backup '$WORK/pve-cluster/config.db'" 2>> "$LOG"
[ "$(timeout -k 5 60 sqlite3 "$WORK/pve-cluster/config.db" 'PRAGMA integrity_check;')" = ok ]

if timeout -k 10 600 tar --zstd --numeric-owner --xattrs --acls -cf "$WORK/$NAME" --warning=no-file-changed \
    -C / etc root var/spool/cron usr/local \
    -C "$WORK" info pve-cluster 2>> "$LOG"
then rc=0; else rc=$?; fi
if [ "$rc" -eq 1 ]; then
    log "WARN files changed while reading the live filesystem (tar rc=1); checking archive"
fi
if [ "$rc" -ge 2 ] || ! timeout -k 10 300 zstd -tq "$WORK/$NAME" \
    || ! timeout -k 10 300 tar --zstd -tf "$WORK/$NAME" > /dev/null; then
    log "FAIL archive not created (tar rc=$rc)"
    exit 1
fi
SUM=$(sha256sum "$WORK/$NAME" | cut -d' ' -f1)
SIZE=$(stat -c %s "$WORK/$NAME")

prune() {
    local dir=$1 old name sum listed count=0
    local -a names=()
    for old in "$dir"/pve-settings-*.tar.zst; do
        name=${old##*/}
        [[ "$name" =~ ^pve-settings-[0-9]{8}-[0-9]{6}\.tar\.zst$ ]] || continue
        [ -f "$old" ] && [ ! -L "$old" ] && [ -s "$old.sha256" ] && [ ! -L "$old.sha256" ] || continue
        read -r sum listed < "$old.sha256" || continue
        [[ "$sum" =~ ^[0-9a-f]{64}$ ]] && [ "$listed" = "$name" ] || continue
        names+=("$name")
    done
    [ "${#names[@]}" -gt "$KEEP" ] || return 0
    while IFS= read -r name; do
        count=$((count + 1))
        [ "$count" -le "$KEEP" ] || rm -f -- "$dir/$name" "$dir/$name.sha256" || return 1
    done < <(printf '%s\n' "${names[@]}" | sort -r)
}
put() { # $1 = target dir, $2 = label
    cp -- "$WORK/$NAME" "$1/$NAME.part" \
        && sync -f "$1/$NAME.part" \
        && [ "$(sha256sum "$1/$NAME.part" | cut -d' ' -f1)" = "$SUM" ] \
        && mv -- "$1/$NAME.part" "$1/$NAME" \
        && echo "$SUM  $NAME" > "$1/$NAME.sha256" \
        && sync -f "$1/$NAME.sha256" \
        && prune "$1" || return 1
    log "OK $2 $NAME $SIZE bytes"
}

fail=0
export WORK NAME LOCAL_DIR SUM SIZE KEEP LOG
export -f put prune log
timeout -k 10 300 bash -o pipefail -c 'put "$LOCAL_DIR" local' >> "$LOG" 2>&1 \
    || { log "FAIL local copy"; fail=1; }

if flock -w 60 8; then
    if findmnt -rn -C -M "$NFS_MNT" > /dev/null; then
        log "FAIL NFS mount already in use; left untouched"
        fail=1
    else
        mkdir -p "$NFS_MNT"
        NFS_OWNED=1
        if timeout -k 5 60 mount -t nfs -o vers=4.1,soft,timeo=100,retrans=2 "$NFS_SRC" "$NFS_MNT" 2>> "$LOG"; then
            export WORK NAME NFS_MNT SUM SIZE KEEP LOG
            export -f put prune log
            timeout -k 10 600 bash -o pipefail -c 'put "$NFS_MNT" synology' >> "$LOG" 2>&1 \
                || { log "FAIL synology copy or retention"; fail=1; }
        else
            log "FAIL synology not mounted"
            fail=1
        fi
    fi
else
    log "FAIL NFS lock timeout; local copy saved"
    fail=1
fi
exit "$fail"
