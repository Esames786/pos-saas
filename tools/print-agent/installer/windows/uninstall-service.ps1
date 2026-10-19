# Bingoo Print Agent - Windows uninstaller
# Run as Administrator. Removes the auto-start task AND the saved pairing (config.json).
# agent.log is kept. Use -KeepConfig to leave the pairing in place.

param([switch]$KeepConfig)

$ErrorActionPreference = 'Stop'
$taskName = 'BingooPrintAgent'
$dataDir  = Join-Path $env:ProgramData 'BingooPrintAgent'

try { Stop-ScheduledTask -TaskName $taskName -ErrorAction Stop } catch {}
try { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop; Write-Host "Removed task '$taskName'." } catch { Write-Host "Task '$taskName' was not installed." }

# Uninstall ka matlab UNINSTALL hai - config bhi jata hai.
#
# Pehle config.json rakh liya jata tha. 9 Oct ko us ki qeemat chukai: malik ne
# agent uninstall kiya, nayi exe chalai, aur server ne 401 "Invalid print agent"
# de diya - kyunke purani config me us agent ka code pada tha jo server par ab
# mojood hi nahi tha. Wajah dhoondne me waqt gaya aur screen par kuch nahi tha
# jo is taraf ishara karta.
#
# Ab config default par jati hai. agent.log BACHTA hai - us me nakamiyon ka
# record hota hai aur uninstall ke baad aksar wohi ek cheez bachti hai jis se
# pata chale ke hua kya tha. -KeepConfig se purana rawaiyya mil jata hai.
if (-not $KeepConfig -and (Test-Path $dataDir)) {
    Get-ChildItem $dataDir -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'agent.log' } | Remove-Item -Force
    Write-Host "Removed config from $dataDir (agent.log kept)."
} else {
    Write-Host "Config kept at $dataDir (-KeepConfig diya gaya tha)."
}
