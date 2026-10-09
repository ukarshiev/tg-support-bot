#!/bin/bash
# Nightly copy of the Proxmox host operating system (root filesystem + boot partition + disk layout).
# VM disks are not included: VM 101 is copied by the vzdump jobs.
# Local copy: /superdata/share/pve-backup/host; second copy: Synology "2.9 Backups/Proxmox".
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

LOCAL_DIR=/superdata/share/pve-backup/host
NFS_SRC='192.168.0.101:/volume2/2.9 Backups/Proxmox'
NFS_MNT=/mnt/synology-pve-host
KEEP=7
LOG=/var/log/pve-os-backup.log
VG=pve
SNAP=root-bksnap
SNAP_MNT=/mnt/pve-root-snap
EFI_PART=/dev/nvme0n1p2
EFI_MNT=/mnt/pve-efi-ro
DISK=/dev/nvme0n1
export LVM_SUPPRESS_FD_WARNINGS=1

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
trap 'log "FAIL command at line $LINENO"' ERR
exec 9>/run/pve-os-backup.lock
flock -n 9 || { log "FAIL another os backup is running"; exit 1; }
# Never write a large backup into the host root when the ZFS pool is absent.
mountpoint -q /superdata && [ "$(findmnt -rn -M /superdata -o FSTYPE)" = zfs ] \
    || { log "FAIL superdata is not mounted as ZFS"; exit 1; }
NFS_OWNED=0
exec 8>/run/pve-host-nfs.lock

STAMP=$(date +%Y%m%d-%H%M%S)
NAME="pve-os-$STAMP.tar"
WORK="$LOCAL_DIR/.work-os-$STAMP"

cleanup() {
    rc=$?
    trap - EXIT ERR
    set +e
    # Do not remove a snapshot while it is mounted; report any failed cleanup.
    if [ "$SNAP_MOUNT_OWNED" = 1 ] && mountpoint -q "$SNAP_MNT"; then
        timeout -k 5 30 umount "$SNAP_MNT" >> "$LOG" 2>&1 || rc=1
    fi
    if [ "$SNAP_OWNED" = 1 ] && [ -e "/dev/$VG/$SNAP" ] && ! mountpoint -q "$SNAP_MNT"; then
        timeout -s INT 60 lvremove --config 'global { wait_for_locks = 0 }' -f "$VG/$SNAP" >> "$LOG" 2>&1 || rc=1
    fi
    if [ "$EFI_OWNED" = 1 ] && mountpoint -q "$EFI_MNT"; then
        timeout -k 5 30 umount "$EFI_MNT" >> "$LOG" 2>&1 || rc=1
    fi
    cleanup_nfs || rc=1
    rm -rf -- "$WORK" "$LOCAL_DIR/$NAME.part" || rc=1
    [ "$rc" = 0 ] || log "FAIL run or cleanup; check mounts and LVM snapshot"
    exit "$rc"
}
cleanup_nfs() {
    [ "$NFS_OWNED" = 1 ] || return 0
    local result=0
    if findmnt -rn -C -M "$NFS_MNT" > /dev/null; then
        timeout -k 5 15 rm -f -- "$NFS_MNT/$NAME.part" >> "$LOG" 2>&1 || result=1
        timeout -k 5 30 umount "$NFS_MNT" >> "$LOG" 2>&1 || {
            timeout -k 5 10 umount -l "$NFS_MNT" >> "$LOG" 2>&1 || :
            log "FAIL NFS unmount; lazy detach attempted"
            return 1
        }
    fi
    return "$result"
}
SNAP_OWNED=0
SNAP_MOUNT_OWNED=0
EFI_OWNED=0
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

mkdir -p "$LOCAL_DIR" "$SNAP_MNT" "$EFI_MNT" "$WORK"

# Fail closed on an unexpected LV/mount. Only our root snapshot may be removed.
if [ -e "/dev/$VG/$SNAP" ]; then
    ORIGIN=$(timeout -k 5 30 lvs --noheadings -o origin "$VG/$SNAP")
    ORIGIN=${ORIGIN//[[:space:]]/}
    STALE_ATTR=$(timeout -k 5 30 lvs --noheadings -o lv_attr "$VG/$SNAP")
    STALE_ATTR=${STALE_ATTR//[[:space:]]/}
    [ "$ORIGIN" = root ] && [[ "$STALE_ATTR" = [sS]* ]] \
        || { log "FAIL snapshot name belongs to an unexpected LV"; exit 1; }
fi
if mountpoint -q "$SNAP_MNT"; then
    [ "$(readlink -f "$(findmnt -rn -M "$SNAP_MNT" -o SOURCE)")" = "$(readlink -f "/dev/$VG/$SNAP")" ] \
        || { log "FAIL unexpected snapshot mount"; exit 1; }
    SNAP_MOUNT_OWNED=1
    timeout -k 5 30 umount "$SNAP_MNT"
    SNAP_MOUNT_OWNED=0
fi
if [ -e "/dev/$VG/$SNAP" ]; then
    timeout -s INT 60 lvremove --config 'global { wait_for_locks = 0 }' -f "$VG/$SNAP" >> "$LOG" 2>&1
fi
mountpoint -q "$EFI_MNT" && { log "FAIL EFI mount already in use"; exit 1; }
# LVM must not be SIGKILLed while suspending the live root LV. Do not wait for locks.
SNAP_OWNED=1
if ! timeout -s INT 60 lvcreate --config 'global { wait_for_locks = 0 }' -s -L 8G -n "$SNAP" "$VG/root" >> "$LOG" 2>&1; then
    log "FAIL snapshot of the root volume not created"
    exit 1
fi
# ext4 may replay its journal on the disposable snapshot, never on the live root LV.
SNAP_MOUNT_OWNED=1
if ! timeout -k 5 30 mount -o ro "/dev/$VG/$SNAP" "$SNAP_MNT" 2>> "$LOG"; then
    log "FAIL snapshot not mounted"
    exit 1
fi

if timeout -k 10 900 tar -I 'zstd -T4 -3' -cpf "$WORK/root.tar.zst" --numeric-owner --xattrs --acls --one-file-system \
    --exclude='./var/lib/vz/template/iso/*' \
    --exclude='./var/cache/apt/archives/*.deb' \
    --exclude='./lost+found' \
    -C "$SNAP_MNT" . 2>> "$LOG"
then rc=0; else rc=$?; fi
# A full/invalid snapshot must never be published, even if compression succeeded.
ATTR=$(timeout -k 5 30 lvs --noheadings -o lv_attr "$VG/$SNAP")
ATTR=${ATTR//[[:space:]]/}
if [ "$rc" -ne 0 ] || [[ ! "$ATTR" =~ ^s...a ]]; then
    log "FAIL root archive not created (tar rc=$rc)"
    exit 1
fi

timeout -k 5 30 umount "$SNAP_MNT"
SNAP_MOUNT_OWNED=0
timeout -s INT 60 lvremove --config 'global { wait_for_locks = 0 }' -f "$VG/$SNAP" >> "$LOG" 2>&1
SNAP_OWNED=0
timeout -k 10 300 zstd -tq "$WORK/root.tar.zst"
timeout -k 10 300 tar -I zstd -tf "$WORK/root.tar.zst" > /dev/null
# /etc/pve is pmxcfs, not part of the root LV snapshot. Save its consistent database.
mkdir -p "$WORK/pve-cluster"
timeout -k 5 60 sqlite3 /var/lib/pve-cluster/config.db ".backup '$WORK/pve-cluster/config.db'" 2>> "$LOG"
[ "$(timeout -k 5 60 sqlite3 "$WORK/pve-cluster/config.db" 'PRAGMA integrity_check;')" = ok ]

# Files of the EFI boot partition (a raw image does not compress: 1 GB per copy).
EFI_OWNED=1
if ! timeout -k 5 30 mount -o ro "$EFI_PART" "$EFI_MNT" 2>> "$LOG" \
    || ! timeout -k 10 300 tar -I 'zstd -T4 -3' -cf "$WORK/efi-partition.tar.zst" -C "$EFI_MNT" . 2>> "$LOG"; then
    log "FAIL boot partition not archived"
    exit 1
fi
timeout -k 5 30 umount "$EFI_MNT"
EFI_OWNED=0
timeout -k 10 300 zstd -tq "$WORK/efi-partition.tar.zst"
timeout -k 10 300 tar -I zstd -tf "$WORK/efi-partition.tar.zst" > /dev/null

timeout -k 5 60 sgdisk --backup="$WORK/partition-table.sgdisk" "$DISK" >> "$LOG" 2>&1
timeout -k 5 60 vgcfgbackup -f "$WORK/lvm-%s.vgcfg" >> "$LOG" 2>&1
{
    echo "# $(date '+%F %T') $(hostname)"
    timeout -k 5 30 pveversion || log "WARN pveversion unavailable"
    echo; timeout -k 5 30 lsblk -o NAME,SIZE,TYPE,FSTYPE,UUID,PARTUUID,MOUNTPOINT || log "WARN lsblk unavailable"
    echo; timeout -k 5 30 blkid || log "WARN blkid unavailable"
    echo; timeout -k 5 30 vgs || log "WARN vgs unavailable"
    echo; timeout -k 5 30 lvs -a || log "WARN lvs unavailable"
    echo; cat /etc/fstab
    echo; timeout -k 5 30 proxmox-boot-tool status 2>&1 || log "WARN boot status unavailable"
    echo; timeout -k 5 30 zpool status 2>&1 || log "WARN pool status unavailable"
} > "$WORK/disk-layout.txt" 2>&1
cat > "$WORK/RESTORE.txt" <<'TXT'
Proxmox host OS backup. The restore below is an outline and has NOT been rehearsed.

Contents
  root.tar.zst            root filesystem (ext4, LV pve/root), taken from an LVM snapshot
  efi-partition.tar.zst   files of the EFI boot partition (vfat)
  partition-table.sgdisk  GPT of the system disk (sgdisk --load-backup)
  lvm-*.vgcfg             LVM metadata of every volume group
  disk-layout.txt         disks, UUIDs, fstab, boot tool status at backup time
  pve-cluster/config.db   consistent pmxcfs database (/etc/pve is not on the root LV)
Not included: VM disks, ISO installers, apt package cache, the ZFS pool "superdata".

Before restoring: verify the outer SHA256 and both inner tar archives. Work offline,
with the old host stopped; identify disks by serial. Never load the saved GPT or
vgcfgrestore onto disks containing VM data: metadata is a reference, not VM backup.

Fast path: install the same Proxmox version on the intended system disk only.
Import superdata without formatting it. Restore selected settings and VM 101 from
vzdump; do not blindly overwrite /etc/pve on a running pve-cluster service.

Full path (outline, NOT a tested recovery procedure):
  1. Install the same Proxmox version on the intended system disk. Do not touch Samsung
     or superdata. Boot a live Linux; activate only VG pve and mount its root at /mnt.
  2. Verify /mnt is the NEW root LV with no nested mounts before emptying it.
     Extract root.tar.zst with numeric owners, xattrs and ACLs into /mnt.
  3. While offline, restore pve-cluster/config.db into /mnt/var/lib/pve-cluster/config.db
     (root:root, mode 600); do not copy files into the unmounted /mnt/etc/pve.
  4. Before chroot: bind /dev (including /dev/pts), /proc, /sys, /run; expose efivarfs.
     Check NEW UUIDs in /etc/fstab, /etc/initramfs-tools/conf.d/resume, GRUB settings
     and /etc/kernel/proxmox-boot-uuids. Remove stale EFI UUIDs from the last file.
  5. Identify the NEW EFI partition; format it ONLY if needed (destroys its contents).
     In chroot: proxmox-boot-tool init <new EFI partition> grub
     then update-initramfs -u -k all; update-grub; proxmox-boot-tool refresh.
     Do not unpack the old EFI files over a newly initialized bootloader.
  6. Check proxmox-boot-tool status and UEFI boot entries before reboot.
     Import superdata if needed; check storage and VM configuration before starting VMs.
The root LV snapshot and pmxcfs database are taken at different times; pause host
configuration changes during backup if a coordinated configuration is required.
TXT

if ! timeout -k 10 300 tar -cf "$LOCAL_DIR/$NAME.part" -C "$WORK" . 2>> "$LOG"; then
    log "FAIL bundle not created"
    exit 1
fi
timeout -k 10 60 sync -f "$LOCAL_DIR/$NAME.part"
timeout -k 10 300 tar -tf "$LOCAL_DIR/$NAME.part" > /dev/null
mv "$LOCAL_DIR/$NAME.part" "$LOCAL_DIR/$NAME"
rm -rf "$WORK"
SUM=$(sha256sum "$LOCAL_DIR/$NAME" | cut -d' ' -f1)
SIZE=$(stat -c %s "$LOCAL_DIR/$NAME")
echo "$SUM  $NAME" > "$LOCAL_DIR/$NAME.sha256"

prune() { # Exact generated names only, newest timestamp first (not mtime).
    local dir=$1 old name sum listed count=0
    local -a names=()
    for old in "$dir"/pve-os-*.tar; do
        name=${old##*/}
        [[ "$name" =~ ^pve-os-[0-9]{8}-[0-9]{6}\.tar$ ]] || continue
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
timeout -k 10 60 sync -f "$LOCAL_DIR/$NAME.sha256"
prune "$LOCAL_DIR"
log "OK local $NAME $SIZE bytes"

put_nfs() {
    cp -- "$LOCAL_DIR/$NAME" "$NFS_MNT/$NAME.part" \
        && sync -f "$NFS_MNT/$NAME.part" \
        && [ "$(sha256sum "$NFS_MNT/$NAME.part" | cut -d' ' -f1)" = "$SUM" ] \
        && mv -- "$NFS_MNT/$NAME.part" "$NFS_MNT/$NAME" \
        && echo "$SUM  $NAME" > "$NFS_MNT/$NAME.sha256" \
        && sync -f "$NFS_MNT/$NAME.sha256" \
        && prune "$NFS_MNT" || return 1
    log "OK synology $NAME $SIZE bytes"
}
fail=0
if flock -w 60 8; then
    if findmnt -rn -C -M "$NFS_MNT" > /dev/null; then
        log "FAIL NFS mount already in use; left untouched"
        fail=1
    else
        mkdir -p "$NFS_MNT"
        NFS_OWNED=1
        if timeout -k 5 60 mount -t nfs -o vers=4.1,soft,timeo=100,retrans=2 "$NFS_SRC" "$NFS_MNT" 2>> "$LOG"; then
            export LOCAL_DIR NAME NFS_MNT SUM SIZE KEEP LOG
            export -f put_nfs prune log
            timeout -k 10 600 bash -o pipefail -c put_nfs >> "$LOG" 2>&1 \
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
