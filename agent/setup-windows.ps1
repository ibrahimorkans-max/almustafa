# One-step set-up of the gate agent on the weighbridge PC (Windows 10/11).
# Run in PowerShell from this folder:   powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1
# It installs the agent, makes agent.toml, finds the weighing indicator on the COM ports and writes its
# settings, and lists the cameras. Secrets (password, signing secret) are asked for, never shown.

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

Step 'Configuration file agent.toml'
if (-not (Test-Path agent.toml)) {
    Copy-Item agent.example.toml agent.toml
    $server = Read-Host 'ERP address (for example https://almustafarm.zhtrader.com)'
    $user = Read-Host 'Login of this PC (Weighbridge / Gate Operator, for example wb.agent)'
    $uid = Read-Host 'device_uid shown under Security -> Devices -> Register gate agent'
    $scale = Read-Host 'Weighbridge id (Gate & weighbridge -> Weighbridges)'
    Write-Host 'Now the password of that login (typing is hidden):'
    $password = (& $agent protect) | Select-Object -Last 1
    Write-Host 'Now the signing secret shown once with the device_uid (typing is hidden):'
    $secret = (& $agent protect) | Select-Object -Last 1
    $key = (& $agent new-store-key) | Select-Object -Last 1
    $keyProtected = (& .\.venv\Scripts\python.exe -c "from ricemill_agent import secrets; print(secrets.protect('$key', 'machine'))") | Select-Object -Last 1
    $text = Get-Content agent.toml -Raw
    $text = $text -replace 'url = "https://erp.example.in"', "url = `"$server`""
    $text = $text -replace 'username = "wb.agent"', "username = `"$user`""
    $text = $text -replace 'device_uid = "agent-x+"', "device_uid = `"$uid`""
    $text = $text -replace '(?m)^password = ""', "password = `"$password`""
    $text = $text -replace '(?m)^signing_secret = ""', "signing_secret = `"$secret`""
    $text = $text -replace '(?m)^key = ""', "key = `"$keyProtected`""
    $text = $text -replace '(?m)^id = ""', "id = `"$scale`""
    Set-Content -Path agent.toml -Value $text -Encoding UTF8
    Write-Host 'agent.toml written (secrets protected for this PC only).'
} else {
    Write-Host 'agent.toml already exists; keeping it.'
}

Step 'COM ports'
& $agent ports

Step 'Finding the weighing indicator (keep a weight on the scale, or press on the platform)'
& $agent --config agent.toml detect --write
if ($LASTEXITCODE -ne 0) {
    Write-Host 'The indicator was not found. See "If the indicator is not found" in docs\connect-weighbridge.md.' -ForegroundColor Yellow
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

Step 'Next'
Write-Host "Test the server connection:   $agent --config agent.toml sync"
Write-Host "Live weight on the screens:   $agent --config agent.toml live"
Write-Host 'Then add the two Task Scheduler tasks (live at startup, sync every minute) - see docs\connect-weighbridge.md.'
