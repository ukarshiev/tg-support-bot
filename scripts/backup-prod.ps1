#requires -Version 7.0
param(
    [string]$VmHost = "karshiev@192.168.1.101",
    [string]$RemoteDir = "/opt/tg-support-bot",
    [string]$SshKey = "",
    [string]$Destination = "\\192.168.0.102\superdata\!Backups\tg-support-bot",
    [int]$KeepDaily = 30,
    [int]$KeepWeekly = 12,
    [int]$KeepMonthly = 12,
    [int]$KeepOnServer = 3,
    [switch]$VerifyRestore
)

$ErrorActionPreference = "Stop"
$quoteBash = {
    param([string]$Value)
    "'" + $Value.Replace("'", ("'" + '"' + "'" + '"' + "'")) + "'"
}
$remoteDirectory = & $quoteBash $RemoteDir
$sshOptions = @("-o", "BatchMode=yes", "-o", "ConnectTimeout=10")
if (-not [string]::IsNullOrWhiteSpace($SshKey)) {
    $sshOptions += @("-i", $SshKey, "-o", "IdentitiesOnly=yes")
}

function Invoke-Remote {
    <#
    .SYNOPSIS
    Execute strict Bash from a temporary file with stdin closed.
    .DESCRIPTION
    Base64 preserves UTF-8; file execution prevents Compose from consuming code.
    #>
    param([string]$Script)
    $payload = ("set -euo pipefail`ncd -- $remoteDirectory`n" + $Script).Replace("`r`n", "`n").Replace("`r", "`n")
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($payload))
    $stderrPath = [IO.Path]::GetTempFileName()
    try {
        $remoteCommand = "bash -c 'set -uo pipefail; script_file=`$(mktemp) || exit `$?; echo $encoded | base64 -d > `"`$script_file`"; result=`$?; if [ `"`$result`" -eq 0 ]; then bash `"`$script_file`" < /dev/null; result=`$?; fi; rm -f -- `"`$script_file`"; exit `"`$result`"'"
        $output = & ssh @sshOptions $VmHost $remoteCommand 2> $stderrPath
        if ($LASTEXITCODE -ne 0) {
            $remoteExitCode = $LASTEXITCODE
            $diagnostics = (Get-Content -LiteralPath $stderrPath -Tail 20) -join "`n"
            throw "Remote step failed (exit $remoteExitCode).`n$diagnostics"
        }
        return $output
    } finally {
        Remove-Item -LiteralPath $stderrPath -Force
    }
}

function Copy-ToRemote {
    <# .SYNOPSIS
    Copy one local file using noninteractive legacy SCP and shell quoting.
    #>
    param([string]$LocalPath, [string]$RemotePath)
    $target = "${VmHost}:$(& $quoteBash $RemotePath)"
    & scp -q -O @sshOptions $LocalPath $target
    if ($LASTEXITCODE -ne 0) { throw "Upload failed (exit $LASTEXITCODE)." }
}

function Copy-FromRemote {
    <# .SYNOPSIS
    Download one file using the same SSH options and legacy SCP transport.
    #>
    param([string]$RemotePath, [string]$LocalPath)
    $source = "${VmHost}:$(& $quoteBash $RemotePath)"
    & scp -q -O @sshOptions $source $LocalPath
    if ($LASTEXITCODE -ne 0) { throw "Download failed (exit $LASTEXITCODE)." }
}

function Assert-Hash {
    <# .SYNOPSIS
    Verify a file against the SHA256 reported by the VM.
    #>
    param([string]$Path, [string]$Expected)
    $actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
    if (-not [string]::Equals($actual, $Expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw "SHA256 mismatch: $([IO.Path]::GetFileName($Path))"
    }
}

function Get-StampDate {
    <# .SYNOPSIS
    Parse the UTC timestamp embedded in a backup name.
    #>
    param([string]$Stamp)
    return [DateTime]::ParseExact($Stamp, "yyyyMMdd-HHmmss", [Globalization.CultureInfo]::InvariantCulture,
        [Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal)
}

function Get-IsoWeek {
    <# .SYNOPSIS
    Return an ISO week identifier, including its ISO year.
    #>
    param([DateTime]$Date)
    return "{0:D4}-{1:D2}" -f [Globalization.ISOWeek]::GetYear($Date), [Globalization.ISOWeek]::GetWeekOfYear($Date)
}

function Copy-BackupPair {
    <# .SYNOPSIS
    Copy the verified daily pair and checksums into another tier and verify it.
    #>
    param([string]$TierPath)
    foreach ($name in @($metadata.DUMP_NAME, $metadata.STORAGE_NAME)) {
        $target = Join-Path $TierPath $name
        Copy-Item -LiteralPath (Join-Path $dailyPath $name) -Destination $target
        $hash = if ($name -eq $metadata.DUMP_NAME) { $metadata.DUMP_SHA256 } else { $metadata.STORAGE_SHA256 }
        Assert-Hash $target $hash
        Copy-Item -LiteralPath (Join-Path $dailyPath "$name.sha256") -Destination (Join-Path $TierPath "$name.sha256")
    }
}

function Invoke-Docker {
    <# .SYNOPSIS
    Run Docker, check its exit code, and return stdout without printing secrets.
    #>
    param([string[]]$Arguments, [string[]]$MaskValues = @())
    $stderrPath = [IO.Path]::GetTempFileName()
    try {
        $output = & docker @Arguments 2> $stderrPath
        if ($LASTEXITCODE -ne 0) {
            $dockerExitCode = $LASTEXITCODE
            $diagnostics = (Get-Content -LiteralPath $stderrPath -Tail 20) -join "`n"
            foreach ($value in $MaskValues) {
                if (-not [string]::IsNullOrEmpty($value)) {
                    $diagnostics = $diagnostics.Replace($value, "[hidden]")
                }
            }
            throw "Restore Docker step failed (exit $dockerExitCode).`n$diagnostics"
        }
        return $output
    } finally {
        Remove-Item -LiteralPath $stderrPath -Force
    }
}

# Read individual keys without executing or printing the environment file.
$envReader = @'
read_env() {
    local value
    value=$(sed -n "/^${1}=/ { s/^${1}=//; s/\r$//; p; q; }" .env)
    if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then
        value=${value:1:${#value}-2}
    fi
    printf '%s' "$value"
}
'@
$startedUtc = [DateTime]::UtcNow
$stamp = $startedUtc.ToString("yyyyMMdd-HHmmss")
$logPath = $null
$tempPath = $null
$weeklyCreated = $false
$monthlyCreated = $false
$restoreVerified = $false
$messages = $null
$deletedFiles = 0

try {
    Write-Host "1/7 Check prerequisites"
    if ([string]::IsNullOrWhiteSpace($Destination)) { throw "Destination must not be empty." }
    if ($Destination -notmatch '^(?:[A-Za-z]:[\\/]|\\\\[^\\/]+\\[^\\/]+\\)') {
        throw "Destination must be an absolute drive path or UNC subdirectory."
    }
    $Destination = [IO.Path]::GetFullPath($Destination)
    $root = [IO.Path]::GetPathRoot($Destination)
    if ($Destination.TrimEnd('\', '/') -eq $root.TrimEnd('\', '/')) {
        throw "Destination must not be a drive or share root."
    }
    foreach ($keep in @($KeepDaily, $KeepWeekly, $KeepMonthly, $KeepOnServer)) {
        if ($keep -lt 1) { throw "All Keep parameters must be at least 1." }
    }
    foreach ($tier in @("daily", "weekly", "monthly", "logs")) {
        New-Item -ItemType Directory -Path (Join-Path $Destination $tier) -Force | Out-Null
    }
    $logPath = Join-Path $Destination "logs/backup-$($startedUtc.ToString('yyyy-MM')).log"
    $probe = Join-Path $Destination ".write-test-$([Guid]::NewGuid().ToString('N'))"
    try { Set-Content -LiteralPath $probe -Value "write test" -Encoding utf8 }
    finally { if (Test-Path -LiteralPath $probe) { Remove-Item -LiteralPath $probe -Force } }
    Invoke-Remote @'
test -d .
test -f .env
grep -q '^DB_DATABASE=' .env
grep -q '^DB_USERNAME=' .env
running=$(docker compose ps --status running --services)
grep -qx 'pgdb' <<< "$running"
'@ | Out-Null

    Write-Host "2/7 Create VM backup"
    $createScript = @'
umask 077
db_name=$(read_env DB_DATABASE)
db_user=$(read_env DB_USERNAME)
[[ "$db_name" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]]
test -n "$db_user"
stamp=__STAMP__
mkdir -p backups/auto
dump_name="${db_name}-${stamp}.dump"
storage_name="storage-${stamp}.tar.gz"
dump_path="backups/auto/$dump_name"
storage_path="backups/auto/$storage_name"
test ! -e "$dump_path"
test ! -e "$storage_path"
docker compose exec -T pgdb pg_dump -U "$db_user" -d "$db_name" -Fc > "$dump_path"
test -s "$dump_path"
listing=$(docker compose exec -T pgdb pg_restore --list < "$dump_path")
tables=$(grep -c ' TABLE DATA ' <<< "$listing")
test "$tables" -gt 0
docker compose exec -T app tar -czf - -C /var/www/storage app certs > "$storage_path"
test -s "$storage_path"
tar -tzf "$storage_path" > /dev/null
dump_size=$(wc -c < "$dump_path")
storage_size=$(wc -c < "$storage_path")
dump_hash=$(sha256sum -- "$dump_path")
storage_hash=$(sha256sum -- "$storage_path")
printf 'DUMP_NAME=%s\nDUMP_SIZE=%s\nDUMP_SHA256=%s\nTABLES=%s\nSTORAGE_NAME=%s\nSTORAGE_SIZE=%s\nSTORAGE_SHA256=%s\n' \
    "$dump_name" "$dump_size" "${dump_hash%% *}" "$tables" "$storage_name" "$storage_size" "${storage_hash%% *}"
'@
    $lines = @(Invoke-Remote ($envReader + "`n" + $createScript.Replace('__STAMP__', (& $quoteBash $stamp))))
    $metadata = @{}
    $keys = @("DUMP_NAME", "DUMP_SIZE", "DUMP_SHA256", "TABLES", "STORAGE_NAME", "STORAGE_SIZE", "STORAGE_SHA256")
    foreach ($line in $lines) {
        if ($line -notmatch '^([A-Z_0-9]+)=(.+)$' -or $Matches[1] -notin $keys) { throw "Invalid VM metadata." }
        $key = $Matches[1]
        $value = $Matches[2].Trim()
        if ($metadata.ContainsKey($key)) { throw "Duplicate VM metadata." }
        $metadata[$key] = $value
    }
    if ($metadata.Count -ne $keys.Count) { throw "Incomplete VM metadata." }
    if ($metadata.DUMP_NAME -notmatch ('^([A-Za-z0-9_][A-Za-z0-9_.-]*)-' + [regex]::Escape($stamp) + '\.dump$')) {
        throw "Invalid dump filename."
    }
    $dbName = $Matches[1]
    if ($metadata.STORAGE_NAME -cne "storage-$stamp.tar.gz") { throw "Invalid storage filename." }
    foreach ($key in @("DUMP_SHA256", "STORAGE_SHA256")) {
        if ($metadata[$key] -notmatch '^[a-fA-F0-9]{64}$') { throw "Invalid SHA256 metadata." }
    }
    foreach ($key in @("DUMP_SIZE", "STORAGE_SIZE", "TABLES")) {
        if ($metadata[$key] -notmatch '^[0-9]+$' -or [long]$metadata[$key] -lt 1) { throw "Invalid numeric metadata." }
    }
    Write-Host "$($metadata.DUMP_NAME): $($metadata.DUMP_SIZE) bytes; $($metadata.STORAGE_NAME): $($metadata.STORAGE_SIZE) bytes"

    Write-Host "3/7 Download and verify network copies"
    $tempPath = Join-Path ([IO.Path]::GetTempPath()) "tgsb-backup-$([Guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $tempPath | Out-Null
    $dailyPath = Join-Path $Destination "daily"
    try {
        foreach ($name in @($metadata.DUMP_NAME, $metadata.STORAGE_NAME)) {
            $local = Join-Path $tempPath $name
            Copy-FromRemote "$RemoteDir/backups/auto/$name" $local
            $hash = if ($name -eq $metadata.DUMP_NAME) { $metadata.DUMP_SHA256 } else { $metadata.STORAGE_SHA256 }
            Assert-Hash $local $hash
            $network = Join-Path $dailyPath $name
            Copy-Item -LiteralPath $local -Destination $network
            Assert-Hash $network $hash
            [IO.File]::WriteAllText("$network.sha256", "$($hash.ToLowerInvariant())  $name`n", [Text.UTF8Encoding]::new($false))
        }
    } finally {
        Remove-Item -LiteralPath $tempPath -Recurse -Force
        $tempPath = $null
    }

    Write-Host "4/7 Create weekly and monthly tiers"
    $dumpPattern = '^' + [regex]::Escape($dbName) + '-(\d{8}-\d{6})\.dump$'
    foreach ($tier in @("weekly", "monthly")) {
        $tierPath = Join-Path $Destination $tier
        $exists = $false
        foreach ($file in Get-ChildItem -LiteralPath $tierPath -File) {
            if ($file.Name -match $dumpPattern) {
                $date = Get-StampDate $Matches[1]
                if (($tier -eq "weekly" -and (Get-IsoWeek $date) -eq (Get-IsoWeek $startedUtc)) -or
                    ($tier -eq "monthly" -and $date.ToString('yyyy-MM') -eq $startedUtc.ToString('yyyy-MM'))) {
                    $exists = $true
                    break
                }
            }
        }
        if (-not $exists) {
            Copy-BackupPair $tierPath
            if ($tier -eq "weekly") { $weeklyCreated = $true } else { $monthlyCreated = $true }
            Write-Host "$tier created"
        } else { Write-Host "$tier already exists" }
    }

    Write-Host "5/7 Verify restore when required"
    if ($VerifyRestore -or $weeklyCreated) {
        $composePath = Join-Path $PSScriptRoot "../docker-compose.yml"
        $inPgdb = $false
        $pgIndent = 0
        $image = $null
        foreach ($line in Get-Content -LiteralPath $composePath) {
            if ($line -match '^(\s+)pgdb:\s*(?:#.*)?$') {
                $inPgdb = $true
                $pgIndent = $Matches[1].Length
                continue
            }
            if (-not $inPgdb -or $line -match '^\s*(?:#.*)?$') { continue }
            $indent = $line.Length - $line.TrimStart().Length
            if ($indent -le $pgIndent) { $inPgdb = $false; continue }
            if ($line -match '^\s+image:\s*(.+?)\s*$') {
                $image = ($Matches[1] -replace '\s+#.*$', '').Trim().Trim('"', "'")
                break
            }
        }
        if ($image -notmatch '^postgres:[A-Za-z0-9_.-]+@sha256:[a-fA-F0-9]{64}$') {
            throw "Pinned PostgreSQL image not found in the pgdb service."
        }
        $container = "tgsb-restore-test-$([Guid]::NewGuid().ToString('N'))"
        $password = [Guid]::NewGuid().ToString('N')
        try {
            Invoke-Docker @("run", "-d", "--rm", "--pull=never", "--network", "none", "--name", $container,
                "--tmpfs", "/var/lib/postgresql", "-e", "POSTGRES_PASSWORD=$password", $image) -MaskValues @($password) | Out-Null
            $deadline = [DateTime]::UtcNow.AddSeconds(60)
            $ready = $false
            do {
                try {
                    Invoke-Docker @("exec", $container, "pg_isready", "-h", "127.0.0.1", "-U", "postgres") | Out-Null
                    $ready = $true
                } catch { if ([DateTime]::UtcNow -ge $deadline) { throw "Restore database readiness timed out." } }
                if (-not $ready) { Start-Sleep -Seconds 1 }
            } until ($ready)
            Invoke-Docker @("cp", (Join-Path $dailyPath $metadata.DUMP_NAME), "${container}:/tmp/backup.dump") | Out-Null
            Invoke-Docker @("exec", $container, "createdb", "-U", "postgres", "restore_check") | Out-Null
            Invoke-Docker @("exec", $container, "pg_restore", "-U", "postgres", "--exit-on-error", "--no-owner",
                "--no-privileges", "-d", "restore_check", "/tmp/backup.dump") | Out-Null
            $query = "SELECT (SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'), (SELECT count(*) FROM public.messages);"
            $counts = ((Invoke-Docker @("exec", $container, "psql", "-U", "postgres", "-d", "restore_check",
                "-X", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-c", $query)) -join "").Trim()
            if ($counts -notmatch '^(\d+)\|(\d+)$') { throw "Invalid restore counts." }
            $tableCount = [long]$Matches[1]
            $messages = [long]$Matches[2]
            if ($tableCount -ne [long]$metadata.TABLES -or $messages -lt 1) { throw "Restore counts failed validation." }
            $restoreVerified = $true
            Write-Host "Restore OK: $tableCount tables, $messages messages"
        } finally {
            $password = $null
            try { Invoke-Docker @("rm", "-f", $container) | Out-Null }
            catch { Write-Warning "Restore container cleanup failed: $container" -WarningAction Continue }
        }
    } else { Write-Host "Restore skipped: weekly copy already exists" }

    Write-Host "6/7 Apply retention"
    foreach ($entry in @(@{ Tier = "daily"; Keep = $KeepDaily }, @{ Tier = "weekly"; Keep = $KeepWeekly }, @{ Tier = "monthly"; Keep = $KeepMonthly })) {
        $tierPath = Join-Path $Destination $entry.Tier
        $dumps = @(Get-ChildItem -LiteralPath $tierPath -File | Where-Object { $_.Name -match $dumpPattern } | Sort-Object Name -Descending)
        foreach ($dump in ($dumps | Select-Object -Skip $entry.Keep)) {
            if ($dump.Name -notmatch $dumpPattern) { throw "Unexpected retention filename." }
            $oldStamp = $Matches[1]
            foreach ($name in @($dump.Name, "$($dump.Name).sha256", "storage-$oldStamp.tar.gz", "storage-$oldStamp.tar.gz.sha256")) {
                $path = Join-Path $tierPath $name
                if (Test-Path -LiteralPath $path -PathType Leaf) {
                    Remove-Item -LiteralPath $path -Force
                    $deletedFiles++
                }
            }
        }
    }
    $retentionScript = @'
db_name=__DB__
keep=__KEEP__
cd backups/auto
shopt -s nullglob
dumps=()
for file in "$db_name"-*.dump; do
    suffix=${file#"$db_name"-}
    if [[ "$suffix" =~ ^[0-9]{8}-[0-9]{6}\.dump$ ]] && [[ -f "$file" ]]; then
        dumps+=("$file")
    fi
done
deleted=0
if (( ${#dumps[@]} > keep )); then
    mapfile -t sorted < <(printf '%s\n' "${dumps[@]}" | LC_ALL=C sort -r)
    for ((i=keep; i<${#sorted[@]}; i++)); do
        file=${sorted[i]}
        suffix=${file#"$db_name"-}
        stamp=${suffix%.dump}
        for target in "$file" "storage-$stamp.tar.gz"; do
            if [[ -f "$target" ]]; then
                rm -- "$target"
                deleted=$((deleted + 1))
            fi
        done
    done
fi
printf '%s\n' "$deleted"
'@
    $remoteDeleted = ((Invoke-Remote ($retentionScript.Replace('__DB__', (& $quoteBash $dbName)).Replace('__KEEP__', $KeepOnServer.ToString()))) -join "").Trim()
    if ($remoteDeleted -notmatch '^\d+$') { throw "Invalid remote retention result." }
    $deletedFiles += [int]$remoteDeleted
    Write-Host "Deleted files: $deletedFiles"

    Write-Host "7/7 Record success"
    $completedUtc = [DateTime]::UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ")
    $status = [ordered]@{
        utc = $completedUtc
        dumpName = $metadata.DUMP_NAME
        dumpSize = [long]$metadata.DUMP_SIZE
        dumpSha256 = $metadata.DUMP_SHA256
        storageName = $metadata.STORAGE_NAME
        storageSize = [long]$metadata.STORAGE_SIZE
        storageSha256 = $metadata.STORAGE_SHA256
        tables = [long]$metadata.TABLES
        messages = $messages
        weeklyCreated = $weeklyCreated
        monthlyCreated = $monthlyCreated
        restoreVerified = $restoreVerified
        deletedFiles = $deletedFiles
    }
    $restoreLabel = if ($restoreVerified) { "yes" } else { "no" }
    $statusTemp = Join-Path $Destination ".last-success-$([Guid]::NewGuid().ToString('N')).tmp"
    try {
        $status | ConvertTo-Json | Set-Content -LiteralPath $statusTemp -Encoding utf8
        Add-Content -LiteralPath $logPath -Encoding utf8 -Value "$completedUtc OK $($metadata.DUMP_NAME) size=$($metadata.DUMP_SIZE) tables=$($metadata.TABLES) restore=$restoreLabel"
        Move-Item -LiteralPath $statusTemp -Destination (Join-Path $Destination "last-success.json") -Force
    } finally {
        if (Test-Path -LiteralPath $statusTemp) { Remove-Item -LiteralPath $statusTemp -Force -ErrorAction SilentlyContinue }
    }
} catch {
    $failure = $_.Exception.Message -replace '[\r\n]+', ' '
    if ($failure.Length -gt 1500) { $failure = $failure.Substring(0, 1500) }
    if (-not $logPath -and -not [string]::IsNullOrWhiteSpace($Destination)) {
        try {
            $existingLogs = Join-Path $Destination "logs"
            if (Test-Path -LiteralPath $existingLogs -PathType Container) {
                $logPath = Join-Path $existingLogs "backup-$($startedUtc.ToString('yyyy-MM')).log"
            }
        } catch { }
    }
    if ($logPath) {
        try { Add-Content -LiteralPath $logPath -Encoding utf8 -Value "$([DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')) FAILED $failure" }
        catch { }
    }
    Write-Error $failure -ErrorAction Continue
    exit 1
}
