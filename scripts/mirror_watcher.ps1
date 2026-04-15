# mirror_watcher.ps1
# Long-running host-side watcher: mirrors NAS backups to a secondary
# destination (USB drive) the instant they appear in the source folder.
#
# Replaces the older 5-minute polling task with near-real-time sync
# (within ~3 seconds of a backup being created).
#
# Started automatically at user logon by install_usb_sync.ps1.

param(
    [string]$Source = "C:\Users\owner\NAS\external_backups",
    [string]$Target = "D:\nas-backups",
    [int]$IntervalSeconds = 3
)

$ErrorActionPreference = "Continue"
$heartbeatFile  = Join-Path $Source ".usb_sync_status"
$syncRequestFile = Join-Path $Source ".sync_request"
$lastWriteUnix  = 0
$lastManualUnix = 0

function Get-DriveCapacity([string]$path) {
    $root = [System.IO.Path]::GetPathRoot($path)
    if (-not (Test-Path $root)) { return @{ total = 0; free = 0 } }
    $drive = Get-PSDrive -Name $root.Substring(0,1) -ErrorAction SilentlyContinue
    if (-not $drive) { return @{ total = 0; free = 0 } }
    return @{
        total = [int64]($drive.Used + $drive.Free)
        free  = [int64]$drive.Free
    }
}

function Write-Heartbeat([int]$count, [string]$status, [int]$rc = 0, [int64]$lastWrite = 0, [int64]$lastManual = 0) {
    $cap = Get-DriveCapacity $Target
    $payload = @{
        last_sync          = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        last_sync_unix     = [int][DateTimeOffset]::Now.ToUnixTimeSeconds()
        last_write_unix    = $lastWrite
        last_manual_unix   = $lastManual
        files_mirrored     = $count
        target_path        = $Target
        target_total_bytes = $cap.total
        target_free_bytes  = $cap.free
        status             = $status
        robocopy_code      = $rc
        watcher_pid        = $PID
        poll_interval_s    = $IntervalSeconds
        mode               = "archive"
    } | ConvertTo-Json -Compress
    Set-Content -Path $heartbeatFile -Value $payload -Encoding ASCII -Force
}

# Make sure source exists; bail if not (NAS not initialized yet)
if (-not (Test-Path $Source)) {
    Write-Output "$(Get-Date -Format 'HH:mm:ss') Source folder $Source not found - exiting"
    exit 1
}

Write-Output "$(Get-Date -Format 'HH:mm:ss') Watcher started (PID $PID), polling $Source every ${IntervalSeconds}s"

# Main loop - robocopy /MIR is incremental, so calling it repeatedly is cheap
# when nothing has changed. Each tick takes a few ms unless there are new files.
$lastTargetMissing = $false
while ($true) {
    $targetRoot = [System.IO.Path]::GetPathRoot($Target)
    if (-not (Test-Path $targetRoot)) {
        if (-not $lastTargetMissing) {
            Write-Output "$(Get-Date -Format 'HH:mm:ss') USB drive $targetRoot not present - pausing sync"
            Write-Heartbeat 0 "usb_unplugged" 0 $lastWriteUnix $lastManualUnix
            $lastTargetMissing = $true
        }
        Start-Sleep -Seconds $IntervalSeconds
        continue
    }

    # Handle a manual "Push All to USB Now" request from the web UI
    $manualRequested = $false
    if (Test-Path $syncRequestFile) {
        Write-Output "$(Get-Date -Format 'HH:mm:ss') Manual sync requested via web UI"
        $manualRequested = $true
        $lastManualUnix = [int][DateTimeOffset]::Now.ToUnixTimeSeconds()
        Remove-Item $syncRequestFile -Force -ErrorAction SilentlyContinue
    }
    if ($lastTargetMissing) {
        Write-Output "$(Get-Date -Format 'HH:mm:ss') USB drive back online - resuming sync"
        $lastTargetMissing = $false
    }

    if (-not (Test-Path $Target)) {
        New-Item -ItemType Directory -Path $Target -Force | Out-Null
    }

    # Append-only archive: /E copies new + changed files but never deletes from
    # target. Protects backups against accidental deletion in the web UI -
    # the USB acts as a true backup, not a sync target.
    $null = robocopy $Source $Target *.zip /E /R:0 /W:0 /NP /NDL /NJH /NJS /NFL 2>&1
    $rc = $LASTEXITCODE

    if ($rc -ge 8) {
        Write-Output "$(Get-Date -Format 'HH:mm:ss') ERROR: robocopy returned $rc"
        Write-Heartbeat 0 "error" $rc $lastWriteUnix $lastManualUnix
    } else {
        $count = (Get-ChildItem $Target -Filter *.zip -ErrorAction SilentlyContinue | Measure-Object).Count
        # Robocopy exit codes: bit 0 (1) = files copied, bit 1 (2) = extras removed
        if (($rc -band 3) -ne 0) {
            $lastWriteUnix = [int][DateTimeOffset]::Now.ToUnixTimeSeconds()
        }
        $status = if ($manualRequested) { "ok_manual" } else { "ok" }
        if ($manualRequested) {
            Write-Output "$(Get-Date -Format 'HH:mm:ss') Manual sync complete (robocopy code $rc, $count file(s) on USB)"
        }
        Write-Heartbeat $count $status $rc $lastWriteUnix $lastManualUnix
    }

    Start-Sleep -Seconds $IntervalSeconds
}
