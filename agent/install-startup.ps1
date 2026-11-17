# Starts the gate agent by itself when the weighbridge PC starts, with nobody logged in.
#
# Run once, in PowerShell opened AS ADMINISTRATOR, in the agent folder (after setup-windows.ps1 and a good "check"):
#   powershell -ExecutionPolicy Bypass -File .\install-startup.ps1
# Remove the tasks again:
#   powershell -ExecutionPolicy Bypass -File .\install-startup.ps1 -Remove
#
# Two Windows tasks, run as the PC itself (SYSTEM), which can read the secrets in agent.toml (protected for this PC):
#   "RiceMill Agent - live weight": at start-up, sends the indicator reading to the screens; restarted every minute if it stops.
#   "RiceMill Agent - sync":        every minute, sends recorded weights and photos (catches up after the internet is back).
# What they print goes to live.log and sync.log in this folder (started afresh each time).

param([switch]$Remove)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$live = 'RiceMill Agent - live weight'
$sync = 'RiceMill Agent - sync'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Write-Host 'Open PowerShell as administrator (right-click > Run as administrator) and run this again.' -ForegroundColor Red
    exit 1
}

foreach ($name in @($live, $sync)) {
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }
}
if ($Remove) {
    Write-Host 'The agent no longer starts by itself.' -ForegroundColor Green
    exit 0
}

$python = Join-Path $here '.venv\Scripts\python.exe'
$config = Join-Path $here 'agent.toml'
if (-not (Test-Path $python)) { Write-Host 'Run setup-windows.ps1 first (the agent is not installed in this folder).' -ForegroundColor Red; exit 1 }
if (-not (Test-Path $config)) { Write-Host 'agent.toml is missing: run setup-windows.ps1 and pair this PC first.' -ForegroundColor Red; exit 1 }

function Agent-Action([string]$command, [string]$log) {
    $line = "`"$python`" -m ricemill_agent.cli --config `"$config`" $command > `"$(Join-Path $here $log)`" 2>&1"
    New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$line`"" -WorkingDirectory $here
}

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

# Live weight: at start-up, never stopped for running long, restarted every minute if it ends.
$liveSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 9999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $live -Action (Agent-Action 'live' 'live.log') -Trigger (New-ScheduledTaskTrigger -AtStartup) `
    -Principal $principal -Settings $liveSettings -Description 'Rice mill gate agent: the weighbridge reading for the ERP screens.' | Out-Null

# Sync: every minute from now on, and again after every start-up.
$every = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$boot = New-ScheduledTaskTrigger -AtStartup
$syncSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $sync -Action (Agent-Action 'sync' 'sync.log') -Trigger @($every, $boot) `
    -Principal $principal -Settings $syncSettings -Description 'Rice mill gate agent: sends recorded weights to the ERP.' | Out-Null

Start-ScheduledTask -TaskName $live
Start-Sleep -Seconds 8
Write-Host ''
Write-Host 'Done. The agent now starts by itself when this PC starts (nobody needs to log in).' -ForegroundColor Green
Write-Host "Live weight is running now. Close any agent window you started by hand, so only one sends."
Write-Host "What it prints: $(Join-Path $here 'live.log')"
if (Test-Path (Join-Path $here 'live.log')) { Get-Content (Join-Path $here 'live.log') -Tail 5 }
Write-Host ''
Write-Host 'Check it any time in Task Scheduler (Task Scheduler Library > "RiceMill Agent - ..."), or run: .venv\Scripts\ricemill-agent --config agent.toml check'
