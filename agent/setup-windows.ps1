# One-step set-up of the gate agent on the weighbridge PC (Windows 10/11).
# Run in PowerShell from this folder:   powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1
# It installs the agent, pairs this PC with the ERP using a pairing code (login, device, weighbridge and
# secrets are filled in by themselves and the sign-in is tested), finds the weighing indicator on the COM
# ports and writes its settings, and lists the cameras. Run it again at any time: it keeps what works.
#
# Before running it, in the ERP: Security -> Devices -> Pair a weighbridge PC -> Make pairing code.

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }

Step 'Python 3.12'
$py = Get-Command py -ErrorAction SilentlyContinue
if (-not $py) {
    Write-Host 'Python is not installed. Install Python 3.12 from https://www.python.org (tick "Add python.exe to PATH"), then run this again.' -ForegroundColor Yellow
    exit 1
}
& py -3.12 --version

Step 'Installing the agent'
if (-not (Test-Path .venv)) { & py -3.12 -m venv .venv }
& .\.venv\Scripts\python.exe -m pip install --quiet --upgrade pip
& .\.venv\Scripts\pip.exe install --quiet -e '.[serial]'
$agent = '.\.venv\Scripts\ricemill-agent.exe'

Step 'Pairing this PC with the ERP'
$pair = $true
if (Test-Path agent.toml) {
    & $agent --config agent.toml check *> $null
    if ($LASTEXITCODE -eq 0) {
        $again = Read-Host 'This PC is already paired and working. Pair it again with a new code? (y/N)'
        $pair = $again -match '^[yY]'
    }
}
if ($pair) {
    Write-Host 'In the ERP: Security -> Devices -> Pair a weighbridge PC -> Make pairing code. Then type it here.'
    $ok = $false
    for ($i = 1; $i -le 3 -and -not $ok; $i++) {
        & $agent --config agent.toml pair
        $ok = ($LASTEXITCODE -eq 0)
        if (-not $ok -and $i -lt 3) { Write-Host 'Pairing did not finish (see above). Try again.' -ForegroundColor Yellow }
    }
    if (-not $ok) {
        Write-Host 'Pairing did not work. Check the ERP address (as in the browser) and make a new code, then run this again.' -ForegroundColor Yellow
        exit 1
    }
}

Step 'COM ports'
& $agent ports

Step 'Finding the weighing indicator (keep a weight on the scale, or press on the platform)'
& $agent --config agent.toml detect --write
if ($LASTEXITCODE -ne 0) {
    Write-Host 'The indicator was not found. See "If the indicator is not found" in the README.' -ForegroundColor Yellow
} else {
    Step 'Readings for 10 seconds (they must match the indicator display)'
    & $agent --config agent.toml watch --seconds 10
}

Step 'Cameras'
if (Get-Command ffmpeg -ErrorAction SilentlyContinue) {
    & $agent cameras
    Write-Host 'Put the camera name (USB) or the RTSP address (IP camera) in the [camera] section of agent.toml, then run:'
    Write-Host "  $agent --config agent.toml camera-test"
} else {
    Write-Host 'ffmpeg is not installed (needed for USB and IP cameras): winget install Gyan.FFmpeg   (or download from ffmpeg.org)' -ForegroundColor Yellow
}

Step 'Testing every step'
& $agent --config agent.toml check

Step 'Next'
Write-Host "Live weight on the screens:   $agent --config agent.toml live"
Write-Host "Send recorded weights:        $agent --config agent.toml sync"
Write-Host 'Then add the two Task Scheduler tasks (live at startup, sync every minute) - see the README.'
