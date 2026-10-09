# !! KHABARDAR - YE SCRIPT A4/A5 DOCUMENT PRINTING TOR DETI HAI !!
#
# Ye task SYSTEM + RunLevel Highest par banati hai. Chromium ELEVATED process
# se chalne par kaam karne se inkar kar deta hai, is liye HTML se PDF banti hi
# nahi aur quotation / kitchen sheet / address sheet kuch nahi chhapte.
#
# 9 Oct 2026, Kashif Kitchen: isi ne poora din liya. Chrome ek second me exit 0
# de kar nikal jata tha - bina PDF, bina shikayat. Edge ne bhi wohi kiya. Wohi
# command aam (non-admin) PowerShell me chalai to PDF foran ban gayi.
#
# Nakami KHAMOSH hai: thermal parchi chalti rehti hai, sirf document rukte hain.
#
# ISTEMAL KARO: install-autostart-user.ps1  (RunLevel Limited)
# Ye file sirf us soorat ke liye bachai gayi hai jahan koi user kabhi login hi
# na karta ho AUR sirf thermal parchi chahiye ho.

# Bingoo Print Agent - Windows auto-start installer
# Run as Administrator AFTER pairing succeeded (print-agent setup).
# Registers a Scheduled Task that starts the agent at boot and restarts it on failure.
# Works for both the packaged BingooPrintAgent.exe and the node script mode.

$ErrorActionPreference = 'Stop'

$taskName   = 'BingooPrintAgent'
$installDir = Join-Path $env:ProgramFiles 'BingooPrintAgent'
$dataDir    = Join-Path $env:ProgramData 'BingooPrintAgent'
$exePath    = Join-Path $installDir 'BingooPrintAgent.exe'

New-Item -ItemType Directory -Force $dataDir | Out-Null

if (Test-Path $exePath) {
    $action = New-ScheduledTaskAction -Execute $exePath -Argument 'run' -WorkingDirectory $installDir
} else {
    # Script mode fallback: node must be on PATH; agent files live next to this script's parent.
    $agentJs = Resolve-Path (Join-Path $PSScriptRoot '..\..\print-agent.js')
    $node = (Get-Command node -ErrorAction SilentlyContinue)
    if ($null -eq $node) {
        Write-Error 'Neither BingooPrintAgent.exe nor node.exe found. Install Node.js or use the packaged installer.'
    }
    $action = New-ScheduledTaskAction -Execute $node.Source -Argument "`"$agentJs`" run" -WorkingDirectory (Split-Path $agentJs)
}

$trigger  = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
            -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries `
            -ExecutionTimeLimit (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -RunLevel Highest

try { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop } catch {}
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $taskName

Write-Host "Installed and started scheduled task '$taskName'."
Write-Host "Logs: $dataDir\agent.log"
Write-Host "Config: $dataDir\config.json"
