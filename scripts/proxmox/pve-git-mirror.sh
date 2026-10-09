#!/bin/bash
# Nightly GitHub mirrors and history snapshots for Proxmox.
# Restore: git clone <file>.bundle
set -uo pipefail
umask 077

REPOSITORIES=(
    'tg-support-bot https://github.com/ukarshiev/tg-support-bot.git'
)
OWNER=ukarshiev
TOKEN_FILE=/root/.config/pve-git-mirror/token
LOCAL_DIR=/superdata/share/pve-backup/github
NFS_SRC='192.168.0.101:/volume2/2.9 Backups/github'
NFS_MNT=/mnt/synology-github
KEEP=10
LOG=/var/log/pve-git-mirror.log
export GIT_TERMINAL_PROMPT=0
export GIT_ASKPASS=/bin/false
export LC_ALL=C

log() { printf '%s %s %s %s %s\n' "$(date '+%F %T')" "$1" "$2" "$3" "$4" >> "$LOG"; }
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
exec 9>/run/pve-git-mirror.lock || exit 1
flock -n 9 || { log FAIL lock pve-git-mirror 0; exit 1; }
trap 'rc=$?; trap - EXIT ERR; heartbeat "$rc" pve-git-mirror /etc/pve-backup/heartbeats.conf || :; exit "$rc"' EXIT

WORK=''
NFS_OWNED=0
PARTS=()
cleanup() {
    local rc=$? part
    trap - EXIT
    for part in "${PARTS[@]}"; do
        timeout -k 5 30 rm -f -- "$part" 2>/dev/null || {
            log FAIL cleanup "${part##*/}" 0
            rc=1
        }
    done
    if [ "$NFS_OWNED" = 1 ] && mountpoint -q "$NFS_MNT"; then
        timeout -k 5 30 umount "$NFS_MNT" 2>/dev/null || {
            timeout -k 5 10 umount -l "$NFS_MNT" 2>/dev/null || :
            log FAIL synology unmount 0
            rc=1
        }
    fi
    if [ -n "$WORK" ]; then
        rm -rf -- "$WORK" || { log FAIL cleanup work 0; rc=1; }
    fi
    heartbeat "$rc" pve-git-mirror /etc/pve-backup/heartbeats.conf || :
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

# Never place backups on the root disk if the ZFS pool is absent.
if ! mountpoint -q /superdata || [ "$(findmnt -rn -M /superdata -o FSTYPE)" != zfs ]; then
    log FAIL local superdata-zfs 0
    exit 1
fi
if ! mkdir -p -- "$LOCAL_DIR" || ! chmod 700 -- "$LOCAL_DIR"; then
    log FAIL local directory 0
    exit 1
fi
WORK=$(mktemp -d "$LOCAL_DIR/.pve-git-mirror.XXXXXX") || {
    log FAIL local temporary-directory 0
    exit 1
}

# Helpers never print tokens except to Git's private askpass pipe.
if ! cat > "$WORK/github-helper.py" <<'PY'
import hashlib
import json
import os
import re
import stat
import sys


def token(path):
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        return None
    parent = os.lstat(os.path.dirname(path))
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
            or info.st_mode & 0o077 or not stat.S_ISDIR(parent.st_mode)
            or parent.st_uid != 0 or parent.st_mode & 0o077):
        raise ValueError()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        opened = os.fstat(stream.fileno())
        if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0
                or opened.st_mode & 0o077
                or (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino)):
            raise ValueError()
        value = stream.read(4097)
    if not value:
        return None
    value = value.rstrip(b"\r\n")
    if not value:
        return None
    if len(value) > 4096 or not re.fullmatch(rb"[A-Za-z0-9_-]+", value):
        raise ValueError()
    return value.decode("ascii")


def main():
    mode = sys.argv[1]
    if mode == "prepare":
        value = token(sys.argv[2])
        if value is None:
            return 2
        fd = os.open(sys.argv[3], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as stream:
            stream.write('header = "Authorization: Bearer ' + value + '"\n')
            stream.write('header = "Accept: application/vnd.github+json"\n')
            stream.write('header = "X-GitHub-Api-Version: 2022-11-28"\n')
    elif mode == "askpass":
        prompt = sys.argv[2]
        if re.fullmatch(r"Username for 'https://github\.com(?:/[^']*)?': ?", prompt):
            print("x-access-token")
        elif re.fullmatch(r"Password for 'https://(?:x-access-token@)?github\.com(?:/[^']*)?': ?", prompt):
            value = token(os.environ["PVE_GIT_TOKEN_FILE"])
            if value is None:
                return 1
            print(value)
        else:
            return 1
    elif mode == "page":
        with open(sys.argv[2], encoding="utf-8") as stream:
            items = json.load(stream)
        if not isinstance(items, list) or len(items) > 100:
            raise ValueError()
        rows = []
        invalid = 0
        for item in items:
            if not isinstance(item, dict):
                raise ValueError()
            name = item.get("name")
            url = item.get("clone_url")
            if (not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9._-]+", name)
                    or name in (".", "..")):
                invalid += 1
                continue
            if url != "https://github.com/" + sys.argv[6] + "/" + name + ".git":
                raise ValueError()
            rows.append(name + " " + url + "\n")
        with open(sys.argv[3], "w", encoding="utf-8") as stream:
            stream.writelines(rows)
        with open(sys.argv[4], "w") as stream:
            stream.write(str(len(items)))
        with open(sys.argv[5], "w") as stream:
            stream.write(str(invalid))
    elif mode == "heads":
        refs = []
        with open(sys.argv[2], "rb") as stream:
            for line in stream:
                oid, name = line.rstrip(b"\n").split(b" ", 1)
                if name.startswith(b"refs/"):
                    refs.append(name + b" " + oid + b"\n")
        print(hashlib.sha256(b"".join(sorted(refs))).hexdigest())
    else:
        return 1
    return 0


try:
    sys.exit(main())
except Exception:
    sys.exit(1)
PY
then
    log FAIL local api-helper 0
    exit 1
fi

# Prefer an explicit token file; otherwise use the owner's CLI login.
TOKEN_SOURCE=file
if [ ! -e "$TOKEN_FILE" ] && [ ! -L "$TOKEN_FILE" ]; then
    TOKEN_SOURCE=gh
    if command -v gh > /dev/null 2>&1 \
        && timeout -k 5 20 gh auth token --hostname github.com > "$WORK/gh-token" 2>/dev/null \
        && [ -s "$WORK/gh-token" ]; then
        TOKEN_FILE="$WORK/gh-token"
    else
        TOKEN_FILE="$WORK/token-absent"
    fi
fi

fail=0
api_complete=0
if python3 "$WORK/github-helper.py" prepare "$TOKEN_FILE" "$WORK/curl.conf" 2>/dev/null; then
    api_ok=1
    page=1
    if ! : > "$WORK/repositories"; then api_ok=0; fi
    while [ "$api_ok" = 1 ]; do
        if ! status=$(timeout -k 5 60 curl --disable --config "$WORK/curl.conf" \
            --silent --fail --proto '=https' --connect-timeout 15 --max-time 55 \
            --output "$WORK/page.json" --write-out '%{http_code}' \
            --url "https://api.github.com/user/repos?affiliation=owner&per_page=100&page=$page" 2>/dev/null) \
            || [ "$status" != 200 ] \
            || ! python3 "$WORK/github-helper.py" page "$WORK/page.json" "$WORK/page.list" \
                "$WORK/page.count" "$WORK/page.invalid" "$OWNER" 2>/dev/null; then
            api_ok=0
            break
        fi
        if ! count=$(cat "$WORK/page.count") || ! invalid=$(cat "$WORK/page.invalid"); then
            api_ok=0
            break
        fi
        if [ "$invalid" -gt 0 ]; then
            log FAIL github-api-name invalid-name "$invalid"
            fail=1
        fi
        if ! cat "$WORK/page.list" >> "$WORK/repositories"; then
            api_ok=0
            break
        fi
        [ "$count" -gt 0 ] || break
        page=$((page + 1))
    done
    if [ "$api_ok" = 1 ] && sort -u "$WORK/repositories" > "$WORK/repositories.sorted" \
        && mapfile -t api_repositories < "$WORK/repositories.sorted"; then
        if cat > "$WORK/askpass" <<'SH'
#!/bin/bash
exec python3 "$PVE_GIT_HELPER" askpass "$@"
SH
        then
            if chmod 700 "$WORK/askpass"; then
                export PVE_GIT_TOKEN_FILE="$TOKEN_FILE"
                export PVE_GIT_HELPER="$WORK/github-helper.py"
                export GIT_ASKPASS="$WORK/askpass"
                REPOSITORIES=("${api_repositories[@]}")
                api_complete=1
                log OK token "$TOKEN_SOURCE" 0
            else
                api_ok=0
            fi
        else
            api_ok=0
        fi
    else
        api_ok=0
    fi
    if [ "$api_ok" != 1 ]; then
        log FAIL github-api repositories 0
        fail=1
    fi
else
    token_rc=$?
    if [ "$token_rc" = 2 ]; then
        log WARN token 'token absent, public list only' 0
    else
        log FAIL token-permissions token 0
        fail=1
    fi
fi

snapshot_name() {
    local repo=$1 name=$2 tail
    tail=${name#"$repo-"}
    [ "$tail" != "$name" ] && [[ "$tail" =~ ^[0-9]{8}-[0-9]{6}\.bundle$ ]]
}

snapshot_names() {
    local dir=$1 repo=$2 path name
    for path in "$dir/$repo-"*.bundle; do
        name=${path##*/}
        snapshot_name "$repo" "$name" || continue
        [ -f "$path" ] && [ ! -L "$path" ] || continue
        printf '%s\n' "$name" || return 1
    done
    return 0
}

prune() {
    local dir=$1 repo=$2 name count=0 listing
    listing=$(snapshot_names "$dir" "$repo" | sort -r) || return 1
    [ -n "$listing" ] || return 0
    while IFS= read -r name; do
        count=$((count + 1))
        if [ "$count" -gt "$KEEP" ]; then
            rm -f -- "$dir/$name" "$dir/$name.sha256" || return 1
        fi
    done <<< "$listing"
}

put() {
    local dir=$1 place=$2 repo=$3 name=$4 sum=$5 size=$6 source=$7 actual
    local bundle="$dir/$name" checksum="$dir/$name.sha256" had_bundle=0 had_checksum=0
    local written_bundle=0 written_checksum=0
    if ! mkdir -p -- "$dir"; then
        log FAIL "$place" "$name" "$size"
        return 1
    fi
    if [ "$place" = local ] && ! chmod 700 -- "$dir"; then
        log FAIL "$place" "$name" "$size"
        return 1
    fi
    if [ -L "$bundle" ] || [ -L "$checksum" ]; then
        log FAIL "$place" "$name" "$size"
        return 1
    fi
    [ ! -e "$bundle" ] || had_bundle=1
    [ ! -e "$checksum" ] || had_checksum=1
    # Local names are new; NAS may need an interrupted copy repaired.
    if [ "$place" = local ] && { [ "$had_bundle" = 1 ] || [ "$had_checksum" = 1 ]; }; then
        log FAIL "$place" "$name" "$size"
        return 1
    fi
    PARTS+=("$bundle.part" "$checksum.part")
    if cp -- "$source/$name" "$bundle.part" \
        && cp -- "$source/$name.sha256" "$checksum.part" \
        && actual=$(sha256sum -- "$bundle.part") \
        && [ "${actual%% *}" = "$sum" ] \
        && cmp -s -- "$source/$name.sha256" "$checksum.part" \
        && mv -T -- "$bundle.part" "$bundle" && written_bundle=1 \
        && mv -T -- "$checksum.part" "$checksum" && written_checksum=1 \
        && actual=$(sha256sum -- "$bundle") \
        && [ "${actual%% *}" = "$sum" ]; then
        if ! prune "$dir" "$repo"; then
            log FAIL "$place" "$name" "$size"
            return 1
        fi
        log OK "$place" "$name" "$size" || return 1
        return 0
    fi
    rm -f -- "$bundle.part" "$checksum.part" || :
    if [ "$written_bundle" = 1 ] || [ "$had_bundle" = 0 ]; then rm -f -- "$bundle" || :; fi
    if [ "$written_checksum" = 1 ] || [ "$had_checksum" = 0 ]; then rm -f -- "$checksum" || :; fi
    log FAIL "$place" "$name" "$size"
    return 1
}

load_snapshot() {
    local repo=$1 dir="$LOCAL_DIR/$1" state="$LOCAL_DIR/$1.snapshot-state"
    local listing digest line candidate saved_finger='' saved_name='' extra=''
    LAST_NAME=''
    LAST_SUM=''
    LAST_SIZE=''
    LAST_FINGER=''
    listing=$(snapshot_names "$dir" "$repo" | sort -r) || return 1
    [ -n "$listing" ] || return 0
    # Ignore incomplete snapshots left by an interrupted publication.
    while IFS= read -r candidate; do
        if [ -L "$dir/$candidate.sha256" ] \
            || ! digest=$(sha256sum -- "$dir/$candidate") \
            || ! IFS= read -r line < "$dir/$candidate.sha256" \
            || [ "$line" != "${digest%% *}  $candidate" ] \
            || ! LAST_SIZE=$(stat -c %s -- "$dir/$candidate"); then
            log FAIL local-snapshot "$candidate" 0
            fail=1
            continue
        fi
        LAST_NAME=$candidate
        LAST_SUM=${digest%% *}
        break
    done <<< "$listing"
    [ -n "$LAST_NAME" ] || return 0
    if [ -f "$state" ] && [ ! -L "$state" ]; then
        read -r saved_finger saved_name extra < "$state" || :
    fi
    if [[ "$saved_finger" =~ ^[0-9a-f]{64}$ ]] && [ "$saved_name" = "$LAST_NAME" ] && [ -z "$extra" ]; then
        LAST_FINGER=$saved_finger
    else
        # Bootstrap state from an existing bundle without making another one.
        git bundle list-heads "$dir/$LAST_NAME" > "$WORK/last-heads" 2>/dev/null \
            && LAST_FINGER=$(python3 "$WORK/github-helper.py" heads "$WORK/last-heads" 2>/dev/null) || return 1
    fi
}

save_state() {
    local repo=$1 finger=$2 name=$3 state="$LOCAL_DIR/$1.snapshot-state"
    [ ! -L "$state" ] || return 1
    PARTS+=("$state.part")
    printf '%s  %s\n' "$finger" "$name" > "$state.part" && mv -T -- "$state.part" "$state"
}

SNAP_REPOS=()
SNAP_NAMES=()
SNAP_SUMS=()
SNAP_SIZES=()
declare -A selected=()
for entry in "${REPOSITORIES[@]}"; do
    read -r repo url extra <<< "$entry"
    if [[ ! "$repo" =~ ^[A-Za-z0-9._-]+$ ]] || [ "$repo" = . ] || [ "$repo" = .. ] \
        || [[ ! "$url" =~ ^https://github\.com/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.git$ ]] || [ -n "$extra" ]; then
        log FAIL configuration repository 0
        fail=1
        continue
    fi
    [ -z "${selected[$repo]+present}" ] || continue
    selected[$repo]=1
    mirror="$LOCAL_DIR/$repo.git"
    staged="$WORK/$repo.git"
    previous="$LOCAL_DIR/.$repo.git.previous.$$"
    name="$repo-$(date +%Y%m%d-%H%M%S).bundle"
    if [ -L "$mirror" ]; then
        log FAIL mirror "$repo.git" 0
        fail=1
        continue
    fi
    # Fetch into a separate mirror; failures leave the old mirror intact.
    if [ -e "$mirror" ]; then
        if ! cp -a -- "$mirror" "$staged" \
            || ! git -C "$staged" remote set-url origin "$url" 2>/dev/null \
            || ! timeout -k 10 900 git -c credential.helper= -C "$staged" fetch \
                --atomic --prune --prune-tags origin '+refs/*:refs/*' > /dev/null 2>&1; then
            log FAIL github "$name" 0
            fail=1
            continue
        fi
    elif ! timeout -k 10 900 git -c credential.helper= clone --mirror -- "$url" "$staged" > /dev/null 2>&1; then
        log FAIL github "$name" 0
        fail=1
        continue
    fi
    if ! git -C "$staged" fsck --full > /dev/null 2>&1 \
        || ! git -C "$staged" for-each-ref --format='%(refname) %(objectname)' > "$WORK/refs" 2>/dev/null \
        || ! digest=$(sha256sum -- "$WORK/refs"); then
        log FAIL fsck "$name" 0
        fail=1
        continue
    fi
    finger=${digest%% *}
    empty=0
    [ -s "$WORK/refs" ] || empty=1
    # All renames stay on the same filesystem.
    if [ -e "$previous" ] || [ -L "$previous" ]; then
        log FAIL mirror "$name" 0
        fail=1
        continue
    fi
    if [ -e "$mirror" ] && ! mv -- "$mirror" "$previous"; then
        log FAIL mirror "$name" 0
        fail=1
        continue
    fi
    if ! mv -- "$staged" "$mirror"; then
        if [ -e "$previous" ]; then
            mv -- "$previous" "$mirror" || log FAIL mirror-restore "$repo.git" 0
        fi
        log FAIL mirror "$name" 0
        fail=1
        continue
    fi
    if [ -e "$previous" ] && ! rm -rf -- "$previous"; then
        log FAIL mirror-cleanup "$repo.git" 0
        fail=1
    fi
    if [ "$empty" = 1 ]; then
        log OK empty "$repo" 0
        continue
    fi
    if ! load_snapshot "$repo"; then
        log FAIL local-snapshot "$repo" 0
        fail=1
        continue
    fi
    if [ "$LAST_FINGER" = "$finger" ]; then
        log OK unchanged "$repo" 0
        if ! save_state "$repo" "$finger" "$LAST_NAME"; then
            log FAIL snapshot-state "$repo" 0
            fail=1
        fi
        name=$LAST_NAME
        sum=$LAST_SUM
        size=$LAST_SIZE
    else
        if ! git -C "$mirror" bundle create "$WORK/$name" --all > /dev/null 2>&1 \
            || ! git -C "$mirror" bundle verify "$WORK/$name" > /dev/null 2>&1; then
            log FAIL bundle "$name" 0
            fail=1
            continue
        fi
        if ! digest=$(sha256sum -- "$WORK/$name") || ! size=$(stat -c %s -- "$WORK/$name"); then
            log FAIL checksum "$name" 0
            fail=1
            continue
        fi
        sum=${digest%% *}
        if ! printf '%s  %s\n' "$sum" "$name" > "$WORK/$name.sha256"; then
            log FAIL checksum "$name" "$size"
            fail=1
            continue
        fi
        if ! put "$LOCAL_DIR/$repo" local "$repo" "$name" "$sum" "$size" "$WORK"; then
            fail=1
            continue
        fi
        if ! save_state "$repo" "$finger" "$name"; then
            log FAIL snapshot-state "$repo" 0
            fail=1
        fi
    fi
    SNAP_REPOS+=("$repo")
    SNAP_NAMES+=("$name")
    SNAP_SUMS+=("$sum")
    SNAP_SIZES+=("$size")
done

# Missing API entries are retained, including mirrors of hidden names.
if [ "$api_complete" = 1 ]; then
    for mirror in "$LOCAL_DIR"/*.git "$LOCAL_DIR"/.*.git; do
        [ -d "$mirror" ] && [ ! -L "$mirror" ] || continue
        repo=${mirror##*/}
        repo=${repo%.git}
        [[ "$repo" =~ ^[A-Za-z0-9._-]+$ ]] || continue
        [ -n "${selected[$repo]+present}" ] || log WARN github-removed "$repo" 0
    done
fi

# Finish every local copy before trying the encrypted NAS volume.
if [ "${#SNAP_NAMES[@]}" -gt 0 ]; then
    nfs_ready=0
    if mkdir -p -- "$NFS_MNT" && ! mountpoint -q "$NFS_MNT"; then
        NFS_OWNED=1
        if timeout -k 5 60 mount -t nfs -o vers=4.1,soft,timeo=100,retrans=2 "$NFS_SRC" "$NFS_MNT" 2>/dev/null \
            && mountpoint -q "$NFS_MNT"; then
            nfs_ready=1
        fi
    fi
    for i in "${!SNAP_NAMES[@]}"; do
        repo=${SNAP_REPOS[$i]}
        name=${SNAP_NAMES[$i]}
        sum=${SNAP_SUMS[$i]}
        size=${SNAP_SIZES[$i]}
        if [ "$nfs_ready" != 1 ]; then
            log FAIL synology "$name" "$size"
            fail=1
            continue
        fi
        dir="$NFS_MNT/$repo"
        if [ -L "$dir/$name" ] || [ -L "$dir/$name.sha256" ]; then
            log FAIL synology "$name" "$size"
            fail=1
            continue
        fi
        # Check an existing NAS snapshot; repair missing files from local storage.
        if [ -f "$dir/$name" ]; then
            if actual=$(sha256sum -- "$dir/$name") && [ "${actual%% *}" = "$sum" ]; then
                if cmp -s -- "$LOCAL_DIR/$repo/$name.sha256" "$dir/$name.sha256"; then
                    log OK synology "$name" "$size"
                    continue
                fi
                if [ -e "$dir/$name.sha256" ]; then
                    log FAIL synology-checksum "$name" "$size"
                    fail=1
                fi
            else
                log FAIL synology-checksum "$name" "$size"
                fail=1
            fi
        fi
        put "$dir" synology "$repo" "$name" "$sum" "$size" "$LOCAL_DIR/$repo" || fail=1
    done
fi
exit "$fail"
